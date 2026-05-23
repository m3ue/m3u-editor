# DVR Scheduling

End-to-end view of how DVR rules become recordings, how the scheduler decides
what is "new", and the deduplication that prevents re-recording the same
content.

---

## 1. The Tick Loop

Scheduling is driven by a single recurring job that runs **every minute**.

- Schedule registration: `routes/console.php:98`
  ```php
  Schedule::job(new DvrSchedulerTick)->everyMinute()->withoutOverlapping();
  ```
- Job: `app/Jobs/DvrSchedulerTick.php`
  - Implements `ShouldBeUnique` — only one tick can be queued/running at a time.
  - Dispatched onto the `dvr` queue (handled by the `dvr-queue` Horizon
    supervisor).
  - `tries = 1`, `timeout = 120s`.
- Service: `app/Services/DvrSchedulerService::tick()` at
  `app/Services/DvrSchedulerService.php:35`.

Each tick does three things in order:

1. **Match rules → create `Scheduled` rows** (`matchAndSchedule`)
2. **Trigger** any `Scheduled` rows whose `scheduled_start` has arrived
   (`triggerPendingRecordings`)
3. **Stop** any `Recording` rows whose `scheduled_end` has passed
   (`stopExpiredRecordings`)

> The tick is also dispatched ad-hoc whenever a user creates/edits a rule or
> hits "Record" in the UI (Browse Shows, EPG Viewer, rule resource pages) so
> users do not have to wait up to 60s for the next cron tick to see their
> recording appear.

---

## 2. Rule Types

Defined in `app/Enums/DvrRuleType.php`. Stored on `dvr_recording_rules`.

| Type     | What it matches                                                                                    | Enabled lifecycle |
|----------|----------------------------------------------------------------------------------------------------|-------------------|
| `Series` | All upcoming EPG programmes whose **title contains** `series_title` (case-insensitive)             | Stays enabled — fires repeatedly |
| `Once`   | A specific `programme_id` (or current dummy-EPG slot when no programme is set)                     | Auto-disables after scheduling once |
| `Manual` | A user-supplied `manual_start` / `manual_end` window on a specific channel                         | Stays enabled until window passes |

A rule is matched only while `enabled = true` (see `scopeEnabled`).

---

## 3. Lookahead Window

Configured via `config/dvr.php`:

```php
'scheduler_lookahead_minutes' => env('DVR_SCHEDULER_LOOKAHEAD_MINUTES', 30),
```

The scheduler only considers programmes whose `start_time` falls within
**now → now + 30 minutes** (default). Anything farther in the future is
deferred to a later tick. This keeps each tick cheap and lets EPG refreshes
correct programme metadata before we lock anything in.

> Once and Manual rules **bypass** the lookahead because the user explicitly
> chose the air-time — they schedule as soon as the rule exists, provided the
> end-time has not passed yet.

---

## 4. Series Matching: How "What to Record" Is Decided

`matchSeriesRule()` (`DvrSchedulerService.php:96`):

1. **Resolve EPG scope** via `resolveSeriesEpgScope()`:
   - Rule has `epg_channel_id` → just that channel.
   - Rule has `channel_id` (pinned channel) → the channel's mapped EPG channel.
   - Neither → **all EPG-mapped channels** in the DVR setting's playlist.

   This prevents cross-playlist contamination — a "Seinfeld" rule on Playlist A
   never matches programmes from Playlist B's EPG.

2. **Query upcoming programmes**:
   ```php
   EpgProgramme::where('title', 'like', "%{$rule->series_title}%")
       ->whereIn('epg_channel_id', $epgChannelStringIds)
       ->where('start_time', '>=', now())
       ->where('start_time', '<=', now()->addMinutes(30));
   ```

3. **`new_only` filter** — if the rule has `new_only = true`, append:
   ```php
   ->where('is_new', true);
   ```
   `is_new` comes from XMLTV's `<new />` flag during EPG ingest. This is the
   primary mechanism a user has to say "skip reruns".

4. Each matched programme is handed to `createScheduledRecordingFromProgramme()`.

---

## 5. Detecting "New" Content / Avoiding Re-Recording

Two layers protect against duplicate recordings:

### Layer A — `new_only` (EPG-driven)

The XMLTV `<new />` element is parsed during EPG import and stored on
`epg_programmes.is_new`. With `new_only = true`, only flagged-new airings are
considered. (Reliability depends on the EPG provider; many feeds omit the
flag, so users falling back to Layer B is normal.)

### Layer B — Recording-row deduplication (the hard guard)

Every scheduling path runs an `exists` check inside a `DB::transaction` before
inserting a new `dvr_recordings` row. This is what truly prevents the same
slot from being recorded twice — even across multiple ticks, multiple rules
matching the same programme, or a user adding a duplicate rule.

For programme-based scheduling (Series / Once with `programme_id`), see
`createScheduledRecordingFromProgramme()` at
`DvrSchedulerService.php:389`:

```php
$exists = DvrRecording::where('dvr_setting_id', $setting->id)
    ->where('programme_start', $programme->start_time)
    ->where('epg_programme_data->epg_channel_id', $programme->epg_channel_id)
    ->whereIn('status', [
        DvrRecordingStatus::Scheduled,
        DvrRecordingStatus::Recording,
        DvrRecordingStatus::PostProcessing,
    ])
    ->exists();
```

The dedup key is **(series_key, programme_start, epg_channel_id)**:

- **`series_key`** — a stable string derived from `setting:{id}|title:{normalized_title}`
  that groups all recordings of the same logical show within a DVR setting, regardless
  of which rule scheduled them. See `App\Support\SeriesKey`. Two rules in the same
  setting targeting the same show now collapse to a single recording per airing.
- **`programme_start`** — exact air-time. A re-air at a different time is a
  different airing and **will** be recorded again (which is what users want).
- **`epg_channel_id`** (from `epg_programme_data->epg_channel_id` JSON) — same
  show on a different channel records separately.

When `series_key` is null (legacy recordings, or manual/once rules with no stable
title), dedup falls back to `(dvr_setting_id, programme_start, epg_channel_id)`.

Manual rules and dummy-EPG Once rules use `series_key = setting:{id}|rule:{rule_id}`
so dedup is scoped to the rule itself. Future recordings derived from the same
channel title will use that title as the series_key instead.

#### Why `Cancelled` and `Failed` are intentionally excluded

The `whereIn(status, …)` only blocks **active** statuses. If a previous
attempt was `Cancelled` or `Failed`, the next tick will happily re-schedule
the same programme. This is by design — a failed recording should be
retryable, not permanently locked out.

#### Why this is race-safe

- `DvrSchedulerTick implements ShouldBeUnique` — only one tick runs at a time.
- The `exists` + `create` pair is wrapped in `DB::transaction()` so the read
  and the write happen atomically.
- The combination means even with multiple rules matching the same programme
  inside a single tick, only one row wins.

### Layer C — `keep_last` (rolling retention, not strictly dedup)

`DvrRetentionService::enforceKeepLast()` runs hourly via
`DvrRetentionCleanup`. For any rule with `keep_last = N`, completed recordings
beyond the newest N are deleted. This is post-hoc (it does **not** stop a
recording from being made) but is how users say "always keep just the latest 5
episodes".

---

## 6. Capacity / Conflict Resolution

Each `DvrSetting` has `max_concurrent_recordings`. Before any new row is
inserted **and** before any pending row is triggered, the scheduler checks:

```php
DvrSetting::isAtCapacity()
    // count of recordings in [Recording, PostProcessing] >= max_concurrent_recordings
```

- `matchAndSchedule` paths: capacity is checked **inside** the transaction
  that creates the row, so the count cannot drift between read and write.
- `triggerPendingRecordings` checks capacity again before dispatching
  `StartDvrRecording` — a programme that should have started may be skipped
  this minute and retried next minute when a slot frees up.

`DvrSchedulerTick` being `ShouldBeUnique` provides the outer concurrency
fence; the per-row transaction provides the inner one.

---

## 7. Once Rule — Two Paths

`matchOnceRule()` at `DvrSchedulerService.php:176`:

1. **`programme_id` is set** — load the `EpgProgramme`.
   - If the programme has been deleted (e.g. by an EPG re-import), the rule
     is auto-disabled with a warning. This avoids zombie rules pointing at
     stale FK ids.
   - If the programme has already ended (`end_time <= now`), do nothing.
   - Otherwise schedule via the standard programme path.

2. **No `programme_id`** — falls back to `matchOnceRuleViaDummyEpg()`. Used
   for channels that have no real EPG (the playlist's dummy-EPG generator
   produces fixed-length slots aligned to midnight). The current or upcoming
   slot becomes the recording window, and the rule is **disabled
   immediately** after scheduling so it only fires once.

---

## 8. Manual Rule

`matchManualRule()` at `DvrSchedulerService.php:207`:

- Requires `manual_start` and `manual_end`.
- Skipped if `manual_end <= now()` (window already passed).
- Same capacity + dedup checks as the other paths.
- If the manual window is **already in progress** when the row is created,
  `StartDvrRecording` is dispatched immediately on the `dvr` queue rather
  than waiting for the next tick (saves up to 60s).

---

## 9. Padding: `start_early_seconds` / `end_late_seconds`

A rule can extend the recording window beyond the EPG programme times. The
effective values come from `DvrSetting::resolveStartEarlySeconds()` /
`resolveEndLateSeconds()`, which fall back to setting-level defaults when the
rule's value is null.

```php
$scheduledStart = $programme->start_time->copy()->subSeconds($startEarly);
$scheduledEnd   = $programme->end_time->copy()->addSeconds($endLate);
```

Padding affects the **scheduled** window (and therefore the trigger/stop
times) but not `programme_start` / `programme_end`, which remain the true EPG
times for dedup and post-processing.

---

## 10. Triggering and Stopping

After `matchAndSchedule`, the tick processes:

### `triggerPendingRecordings()` (`:457`)

```php
DvrRecording::scheduled()                    // status = Scheduled
    ->where('scheduled_start', '<=', now())
    ->get();
```

For each row: re-check the setting is enabled and not at capacity, then
dispatch `StartDvrRecording` on the `dvr` queue.

### `stopExpiredRecordings()` (`:485`)

```php
DvrRecording::recording()                    // status = Recording
    ->where('scheduled_end', '<=', now())
    ->get();
```

Each gets a `StopDvrRecording` job dispatched to flip into `PostProcessing`,
which is handled by `DvrPostProcessorService` (download → ffmpeg concat →
proxy cleanup).

---

## 11. Status State Machine

`app/Enums/DvrRecordingStatus.php`:

```
                ┌──────────────┐
                │  Scheduled   │  ← scheduler created the row
                └──────┬───────┘
                       │ scheduled_start <= now AND not at capacity
                       ▼
                ┌──────────────┐
                │  Recording   │  ← StartDvrRecording dispatched
                └──────┬───────┘
                       │ scheduled_end <= now  (or user stops, or stream ends)
                       ▼
                ┌──────────────┐
                │PostProcessing│  ← ffmpeg concat + cleanup
                └──┬────────┬──┘
                   │        │
                   ▼        ▼
          ┌──────────┐  ┌────────┐
          │Completed │  │ Failed │
          └──────────┘  └────────┘

                ┌──────────────┐
                │  Cancelled   │  ← user cancelled (any pre-completed state)
                └──────────────┘
```

Only `Scheduled / Recording / PostProcessing` block re-scheduling.
`Completed / Failed / Cancelled` do **not** — letting failed runs be retried
and letting completed shows be re-recorded if a new airing is encountered.

---

## 12. Stream URL Resolution

`resolveStreamUrl()` (`:506`) determines what the recorder pulls from:

1. Pick a channel:
   - `rule->channel_id` if set, else
   - look up a channel in the same playlist whose `epg_channel_id` matches
     the programme's EPG feed (the "EPG fallback" — keeps series rules
     working without a pinned channel).
2. If the playlist has the proxy enabled, use `Channel::getProxyUrl()` so
   recording goes through the m3u-proxy (HLS-aware, share-safe, log-friendly).
   Otherwise use the channel's raw URL.

The resolved `stream_url` and `channel_id` are stored on the recording row at
schedule time so subsequent EPG/channel edits don't change what an in-flight
recording is pulling.

---

## 13. Observability

- All scheduling decisions log under the `dvr` channel (`Log::info`,
  `Log::debug`, `Log::warning`).
- Horizon supervisor: `dvr-queue` (queues: `dvr`, `dvr-post`, `dvr-meta`).
- Recording rows persist `epg_programme_data` JSON snapshot of the programme
  at schedule time — useful for audit even after the EPG row is replaced.

---

## 14. File Map

| Concern                         | File |
|---------------------------------|------|
| Tick entry job                  | `app/Jobs/DvrSchedulerTick.php` |
| Scheduling logic                | `app/Services/DvrSchedulerService.php` |
| Capacity check                  | `app/Models/DvrSetting.php:97` |
| Rule model                      | `app/Models/DvrRecordingRule.php` |
| Recording model                 | `app/Models/DvrRecording.php` |
| Rule-type enum                  | `app/Enums/DvrRuleType.php` |
| Status enum                     | `app/Enums/DvrRecordingStatus.php` |
| Schedule registration           | `routes/console.php:98` |
| Config                          | `config/dvr.php` |
| Retention / `keep_last`         | `app/Services/DvrRetentionService.php` |
| Recorder (start)                | `app/Services/DvrRecorderService.php` + `app/Jobs/StartDvrRecording.php` |
| Post-processing                 | `app/Services/DvrPostProcessorService.php` |

---

## 15. Gaps and Recommended Improvements

Issues identified by walking the code paths against real-world EPG behaviour.
Severity is a rough triage, not a commitment.

### 15.1 Series recordings have no first-class "series" grouping  ✅ DONE

**Implemented (Phase 1).** `dvr_recordings` and `dvr_recording_rules` now have
`series_key` and `normalized_title` columns. `App\Support\SeriesKey` provides
`for(int $dvrSettingId, ?string $title): ?string` and `normalize(?string): string`.

`DvrRecordingRule::boot()` auto-derives `series_key` / `normalized_title` for
Series rules on save. The scheduler derives and stores these on every recording
created from a programme.

Dedup was switched from `(dvr_setting_id, programme_start, epg_channel_id)` to
`(series_key, programme_start, epg_channel_id)` with a legacy fallback when
`series_key` is null. `DvrRetentionService::enforceKeepLast()` now groups by
`series_key` across rules, with per-rule fallback for recordings that predate
the migration.

### 15.2 No "skip episodes already recorded" check  ✅ DONE

**Implemented (Phase 2).** `DvrRecordingRule` now has a `series_mode` enum
(`App\Enums\DvrSeriesMode`) with three values:

| Value | Behaviour |
|-------|-----------|
| `all` | Record every matching programme (default) |
| `new_flag` | Record only when `is_new = true` (former `new_only` behaviour) |
| `unique_se` | Skip if `(series_key, season, episode)` already has an active or completed recording |

`DvrRecordingRule::alreadyHaveEpisode(string $seriesKey, ?int $season, ?int $episode): bool`
checks `(series_key, season, episode)` against active/completed recordings.
`DvrSchedulerService::matchSeriesRule()` gates scheduling on `alreadyHaveEpisode()`
when `series_mode === unique_se`. The old `new_only` boolean is preserved as a
read-compute accessor so Filament table columns and existing tests work unchanged.

### 15.3 `series_title LIKE %x%` is too loose  ✅ DONE

**Implemented (Phase 4).**

`match_mode` column added to `dvr_recording_rules` (string, default `contains`).
`tmdb_id` column added to `dvr_recording_rules` (nullable string) and
`epg_programmes` (nullable string) to support TMDB ID-based matching.

`App\Enums\DvrMatchMode`: `Contains` (default), `Exact`, `StartsWith`, `Tmdb`.

Scheduler `matchSeriesRule()` branches on `match_mode`:

| match_mode   | SQL                                             |
|--------------|-------------------------------------------------|
| `contains`   | `lower(title) LIKE lower(%x%)`                  |
| `exact`      | `lower(title) = lower(x)` (case-insensitive)    |
| `starts_with`| `lower(title) LIKE lower(x%)`                   |
| `tmdb`       | `epg_programme.tmdb_id = rule.tmdb_id`         |

Existing rules migrate as `contains` for backward compatibility. New rules
default to `contains` via DB-level default.

`"The Office"` matches:

- `"The Office"`
- `"The Office Tour"`
- `"Welcome to The Office"`
- `"Behind the Office"`

There is no anchoring, no exact-match option, no word-boundary check, no
year/country disambiguation (`"The Office (US)"` vs `"The Office (UK)"`),
and no TMDB-id binding even though we resolve TMDB during enrichment.

**Suggested fix.** Add `match_mode` to rules:

| match_mode    | SQL                                            |
|---------------|------------------------------------------------|
| `exact`       | `title = ?`                                    |
| `starts_with` | `title LIKE 'x%'`                              |
| `contains`    | current behaviour                              |
| `tmdb`        | `epg_programme_data->>'tmdb_id' = ?`           |

Default new rules to `exact` and only fall back to `contains` when the user
opts in. Existing rules migrate as `contains` for compatibility.

### 15.4 `priority` field exists on rules but is never read  ✅ DONE

**Implemented (Phase 5).**

`DvrSchedulerService::matchAndSchedule()` now orders rules by `priority DESC, id ASC`
before processing, ensuring higher-priority rules match first and have first
dibs on programmes they uniquely match.

`triggerPendingRecordings()` now orders due recordings by
`dvr_recording_rules.priority DESC, scheduled_start ASC` using PHP collection
sorting (to avoid SQL join ambiguity with nullable FKs). Higher-priority
recordings are dispatched before lower-priority ones when multiple are due
in the same tick.

Note: preemption (stopping an in-progress lower-priority recording to make
room for a higher-priority pending one) is not yet implemented. A future
enhancement could add this.

`dvr_recording_rules.priority` is fillable and cast to integer, but
`DvrSchedulerService` never references it. Two implications:

1. **Conflict resolution by priority is impossible.** When at capacity, the
   scheduler skips the new recording rather than preempting a lower-priority
   one. A user's "must record" rule loses to whatever is already running.
2. **Tie-breaking between overlapping rules is undefined.** When two series
   rules match the same programme (see 15.1), the "winner" is determined by
   query order, not user intent.

**Suggested fix.** Either (a) honour priority in capacity decisions and
inter-rule tie-breaks, or (b) drop the column and the UI field to avoid
implying behaviour we don't deliver.

### 15.5 Cancelled / Failed recordings are immediately re-scheduled  ✅ DONE

**Implemented (Phase 3).**

Two fields added to `dvr_recordings`:
- `user_cancelled` boolean — set to `true` in `DvrRecorderService::cancel()`. User-cancelled
  recordings are **blocked** from re-scheduling within the same airing window (dedup respects
  `user_cancelled = true` as a hard block).
- `attempt_count` smallInteger (default 1) — incremented in `DvrRecorderService::start()`
  when a recording transitions to `Recording`.

Retry logic in `DvrSchedulerService::createScheduledRecordingFromProgramme()`:
1. If a Failed row for the same programme is retriable (within window, `user_cancelled=false`,
   `attempt_count < max_attempts_per_airing`), it is **resurrected** to `Scheduled` and
   re-dispatched if already in progress.
2. If a Failed row exists but is exhausted (`attempt_count >= max_attempts_per_airing`),
   no new row is created — the airing is skipped.
3. `user_cancelled = true` rows are always blocked and never retried.

Config `dvr.max_attempts_per_airing` (default: 3) controls the cap. Set to 0 to disable
retries entirely.

### 15.6 `triggerPendingRecordings` will fire programmes that already ended  ✅ DONE

**Implemented (Phase 0).** Added `->where('scheduled_end', '>', now())` to the
trigger query. Stale rows (`scheduled_end <= now()`) are now explicitly
transitioned to `Failed` with `error_message = 'Missed recording window — scheduler
did not fire before scheduled_end.'` before the trigger query runs.

### 15.7 Capacity check ignores about-to-trigger recordings  ✅ DONE

**Implemented (Phase 0).** `isAtCapacity(int $pendingInTick = 0)` now accepts a
`$pendingInTick` parameter counting starts already dispatched in this tick.
`triggerPendingRecordings` tracks `$pendingStartsBySetting` and passes it to
each `isAtCapacity()` call, preventing a single tick from dispatching more
starts than free slots.

### 15.8 EPG drift breaks dedup  ✅ DONE

**Implemented (Phase 6).**

`programme_uid` column added to `dvr_recordings` (string 64, nullable). Set at
schedule time from a deterministic hash of the programme's stable identity:
`(epg_channel_id | title | season | episode)`. Does NOT include `start_time`,
so a schedule shift (sports overrun, breaking news) does not produce a duplicate.

Dedup queries in `createScheduledRecordingFromProgramme()` and the Phase 3 helpers
now check `programme_uid` as the primary key, falling back to
`(programme_start, epg_channel_id)` only for legacy recordings without a
`programme_uid` (backward compatible).

Result: re-scheduling after EPG ingest with shifted `start_time` correctly
recognises the same programme and skips it, rather than creating a duplicate
recording row.

The dedup key includes `programme_start`. If the EPG provider shifts the
programme (sports overrun, schedule change), the next ingest writes a new
`start_time`, and the next tick happily creates a *second* recording for the
"new" airing while the first is still in-flight or completed.

**Suggested fix.** Resolve dedup by a stable identifier first (e.g.
`epg_programmes.epg_id` if available, or
`(epg_channel_id, normalized_title, season, episode)`), and use
`programme_start` only as the final tiebreaker.

### 15.9 `Once` rule with `programme_id` is fragile  ⚠ LOW

`programme_id` is an FK to `epg_programmes.id`. EPG re-imports replace rows
by id, so an in-flight Once rule frequently finds its programme deleted and
auto-disables itself (with a warning). The user's intent — "record this
specific airing" — is lost even though the programme still logically exists
under a new id.

**Suggested fix.** Snapshot the programme's natural key on the rule at
creation time: `(epg_channel_id, start_time, normalized_title)`. On match,
look up by natural key first, fall back to id.

### 15.10 `DvrSetting.use_proxy` is unused  ⚠ LOW

`resolveStreamUrl()` decides proxy vs direct based on
`$playlist->proxy_options['enabled']`, ignoring the per-DVR
`DvrSetting.use_proxy` toggle. Either honour it or remove it.

### 15.11 Series fan-out has no cap  ⚠ LOW

A loose `series_title` on a playlist with hundreds of EPG channels can
match many programmes per tick. There is no per-rule cap, no per-tick
budget, and no warning to the user.

**Suggested fix.**

- Soft-warn in the UI when a rule's title pattern matches > N programmes in
  the next 7 days at creation time.
- Optional `max_per_day` / `max_per_week` on the rule.

### 15.12 No timezone safety net in scheduling code

All comparisons rely on `now()` and `start_time` being in compatible
timezones. The TZ single-source fix (see project notes) addressed this, but
there is no defensive assertion in the scheduler. A future config drift
silently produces empty match windows or always-matches.

**Suggested fix.** Log `now()->timezone` and a sample
`programme->start_time->timezone` once per tick at debug level, or at
startup. Cheap, catches drift fast.

### 15.13 No metric/health endpoint

There is no quick way to answer:

- How many ticks ran in the last hour?
- How many recordings did each tick create?
- What's the average time between `Scheduled` and `Recording`?

**Suggested fix.** Emit counters via Horizon tags or a simple `dvr_metrics`
table written at the end of each tick.

### 15.14 Stop time doesn't follow EPG extensions

If a programme is extended after we schedule (sports overrun, breaking
news), `scheduled_end` is fixed at schedule time + `end_late_seconds`. The
recording stops at the originally-planned end, missing the tail.

**Suggested fix.** During each tick, for `Recording` rows whose underlying
`epg_programme` still exists and whose `end_time` has moved later, update
`scheduled_end` accordingly (cap by some safety ceiling, e.g.
`+max_overrun_minutes`).

### 15.15 Manual rule dedup is rule-scoped, not channel/time-scoped  ⚠ LOW

Two manual rules covering the same channel + window create two recordings.
Probably fine, but document the behaviour or dedup by
`(channel_id, manual_start)` regardless of rule.

---

## 16. Priority Order for Improvements

Status: **Phase 0** (stabilize) ✅, **Phase 1** (series grouping) ✅,
**Phase 2** (skip already-recorded S/E) ✅, **Phase 3** (bounded retries) ✅,
**Phase 4** (match modes + tmdb_id) ✅, **Phase 5** (priority ordering) ✅,
**Phase 6** (stable EPG dedup) ✅.
All §15 items addressed.
