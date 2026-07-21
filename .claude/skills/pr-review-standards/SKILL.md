---
name: pr-review-standards
description: "Project-specific PR review standards for m3u-editor, supplementing the general-purpose /code-review skill. Apply whenever reviewing a diff, PR, or branch in this project (via /code-review, /review, or a direct request to review changes). Enforces five rules: (1) memory-efficient queries — no ->all()/->get() on large/unbounded datasets, prefer ->cursor() or generators; (2) no unnecessary complexity — no hand-rolled DB::statement() over Eloquent, no new function where an existing one could be extended, no duplicated logic where a centralized Service or Filament Action exists (including stragglers a PR's own new helper should have replaced but didn't); (3) framework-first UI — Filament/Livewire built-ins over hand-rolled Blade/Alpine, except in explicitly approved custom surfaces (EPG Viewer, in-app player); (4) no regressions — existing behavioral defaults must not change unless the user explicitly opted in, or the change fixes a real bug/gap; (5) migration/schema safety — DDL that locks tables the Process*/Sync job chains write to continuously (e.g. plain CREATE INDEX instead of CONCURRENTLY on Postgres) must be called out explicitly. This is a project convention kept in its own skill directory (not under laravel-best-practices/rules) so `php artisan boost:update` cannot overwrite it."
metadata:
  author: m3u-editor
---

# PR Review Standards (m3u-editor)

Project-specific checks to apply on top of general code review. Use alongside `laravel-best-practices` and `filament-first` — this skill does not replace them, it adds five m3u-editor-specific gates that a generic Laravel review would miss.

---

## 1. Memory-Efficient Queries

Flag `->all()` or `->get()` on any query that isn't bounded to a small, known-size result (a handful of records, a `select` scoped by ID, a `limit()`, or a UI-paginated table). Large or unbounded reads must use `->cursor()` or a generator so memory stays flat regardless of table size.

Incorrect:
```php
$channels = Channel::where('playlist_id', $playlistId)->get();
foreach ($channels as $channel) {
    $this->syncChannel($channel);
}
```

Correct:
```php
foreach (Channel::where('playlist_id', $playlistId)->cursor() as $channel) {
    $this->syncChannel($channel);
}
```

- `cursor()` is for read-only iteration. If the loop body modifies the same rows being iterated, use `chunkById()` instead (see `laravel-best-practices/rules/db-performance.md`) — don't let this rule push someone into an unsafe `cursor()` + mutate pattern.
- Bounded queries are fine as-is: `Playlist::find($id)`, `->get()` behind a Filament table's own pagination, small lookup lists for a select input, etc. Don't flag those — the rule targets syncs, exports, and bulk jobs over playlist/channel/EPG-scale tables (`app/Sync/`, `app/Jobs/Sync*.php`, `app/Services/*Sync*`), not every query in the codebase.

## 2. No Unnecessary Complexity

Prefer the simpler, already-available tool over a hand-rolled one. Three recurring patterns to flag:

**Raw `DB::statement()` instead of the query builder / Eloquent**, when the query builder can express the same thing portably:

Incorrect:
```php
DB::statement('UPDATE channels SET enabled = 1 WHERE playlist_id = ?', [$playlistId]);
```

Correct:
```php
Channel::where('playlist_id', $playlistId)->update(['enabled' => true]);
```

Raw SQL is acceptable when it's genuinely needed (a DB-specific feature, a query the builder can't express) — but flag it when it's just avoiding the builder for no reason, since it silently drops query-builder portability and any model events/casts.

**A new function where extending an existing one would do.** Before approving a new method, check whether an existing method in the same class/service already does 90% of the job and could take a parameter or an early branch instead. Near-duplicate method pairs (`syncPlaylist()` / `syncPlaylistForced()`, `buildQuery()` / `buildQueryWithFilters()`) are the tell.

**Duplicated logic across files that a shared Service or Action should own.** This codebase centralizes reusable logic in `app/Services/` (e.g. `SyncPipelineService`, `NetworkChannelSyncService`) and `app/Filament/Actions/` (e.g. `CopyToUserAction`). If a PR copies near-identical logic into two Filament resources, two jobs, or a job and a console command, flag it and point at (or propose) the shared service/action instead of the copy.

**Stragglers left behind by the PR's own new helper.** When a PR extracts a new shared method specifically to stop two callers from duplicating logic, actively search for *other* pre-existing call sites with the same duplicated logic that the PR didn't touch — grep for the inline pattern the new helper replaces (e.g. the same set of `$settings['x'] ?? default` keys, the same hand-rolled query shape) across the codebase, not just the files in the diff. A PR that centralizes 2 of 3 existing copies and leaves the third un-migrated is a legitimate, PR-scoped finding — don't limit the search to changed files just because the diff itself did.

Don't flag necessary duplication — two methods that happen to be short and superficially similar but diverge in real business logic are not the same thing as copy-pasted code.

## 3. Framework-First UI

Full rules live in [[filament-first]] (`.claude/skills/filament-first/SKILL.md`) — apply that skill's checklist during review: badges, icons, buttons, spinners, dropdowns, sections, slide-overs/modals, and confirmations must use `<x-filament::*>` components and `Action::make()`, not hand-rolled HTML, raw `<svg>`, or Alpine-built equivalents.

Known, already-approved exceptions — don't flag hand-rolled Blade/Alpine/JS inside these, since they were deliberately built custom:
- The EPG Viewer
- The in-app player

Outside of explicitly custom surfaces like those, a new hand-rolled UI pattern needs a stated reason (e.g. a genuine Filament component gap) in the PR description, not just convenience.

## 4. No Regressions — Behavior Changes Are Opt-In

Existing user-facing behavior must not change as a side effect of a PR unless:

- The user (via a setting, a form field, a new action) explicitly opted into the new behavior, or
- The change is a genuine bug fix or fills a real gap (missing case, broken invariant) — in which case the PR description should say so explicitly, not leave it implicit.

This is a general rule for the whole app, not a sync-specific one — apply it to whatever feature the diff touches (playlist/EPG management, Xtream/Plex integrations, the proxy/streaming path, notifications, the in-app player, user/permission handling, etc.). When reviewing a diff:

1. Diff the actual control flow of the feature being touched (order of steps, what gets skipped/included by default, what happens when a flag/option is absent) against `main` — not just the lines the PR intended to change.
2. If a default changed (e.g. a filter that used to be off is now on, an option that used to require a flag now runs unconditionally, an existing endpoint's response shape changed), that's a regression unless it's gated behind a new opt-in setting or is explicitly the bug being fixed.
3. Prefer additive changes: new opt-in parameter/setting with the old behavior as default, over rewriting the existing default path.

Playlist and EPG syncing is the app's flagship feature and the single highest-cost place to get this wrong — give diffs touching it the most scrutiny — but treat it as the sharpest example of this rule, not its boundary. A silent default change in, say, Xtream credential handling or proxy stream selection is just as much a regression as one in sync.

The actual import/sync work lives in the `Process*` job chains, not just `Sync*`:
- M3U: `app/Jobs/ProcessM3uImport.php` → `ProcessM3uImportChunk.php` → `ProcessM3uImportComplete.php` (plus the series variants: `ProcessM3uImportSeries*`, `ProcessM3uVodImportChunk.php`)
- EPG: `app/Jobs/ProcessEpgImport.php` → `ProcessEpgImportChunk.php` → `ProcessEpgImportComplete.php` (plus `ProcessEpgSDImport.php` for Schedules Direct)
- Also relevant: `app/Jobs/ProcessChannelScrubber*.php`, `app/Jobs/ProcessVodChannels*.php`, `app/Sync/`, `app/Jobs/Sync*.php`, `app/Services/SyncPipelineService.php`, `app/Services/NetworkChannelSyncService.php`, and anything touching `SyncRun`/`PlaylistSyncStatus`

A diff touching any stage of one of these chains needs the same "did a default silently change" scrutiny described above — check the whole chain's control flow, not just the file the PR happened to edit.

## 5. Migration / Schema Safety on Hot Tables

A migration can regress the app without changing a single line of application code, by locking a table the `Process*`/`Sync*` job chains write to continuously. Treat any migration touching `channels`, `epg_channels`, `playlists`, `epgs`, or another table those job chains read/write as needing the same scrutiny as §4.

The specific pattern to catch: **Postgres `CREATE INDEX` (or other DDL) without `CONCURRENTLY`, run inside Laravel's default migration transaction.** A plain `CREATE INDEX` takes an exclusive lock for the full build time — on a large table, that stalls any in-flight `Process*Import`/`Sync*` job trying to write to it during deploy, and reads too.

Incorrect (blocks writers for the full index build):
```php
public function up(): void
{
    DB::statement('CREATE INDEX idx_epg_channels_name_trgm ON epg_channels USING gin (LOWER(name) gin_trgm_ops)');
}
```

Correct (Postgres — `CONCURRENTLY` doesn't block reads/writes, but cannot run inside a transaction, so the migration must opt out of one):
```php
public $withinTransaction = false;

public function up(): void
{
    DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_epg_channels_name_trgm ON epg_channels USING gin (LOWER(name) gin_trgm_ops)');
}
```

If a PR adds a blocking index/DDL change to one of these tables without `CONCURRENTLY` (or an equivalent, explicit acknowledgment that the lock is acceptable — e.g. a small table, or a documented maintenance-window expectation), flag it. This applies to any DDL statement — `CREATE INDEX`, `ALTER TABLE ... ADD COLUMN` with a default on old Postgres versions, etc. — not just the trigram-index case that motivated this rule.

## How to Apply

1. Get the diff under review (`git diff <base>...HEAD` or the PR's changed files).
2. Walk the five sections above against the changed files — most PRs will only touch one or two. If the diff includes a `database/migrations/*.php` file, §5 always applies — check it even if the rest of the PR is otherwise clean.
3. Apply §4 to whatever the diff touches, not just sync — it's a whole-app rule. Give it extra scrutiny when the diff touches the `Process*` import job chains (`ProcessM3uImport*`, `ProcessEpgImport*`, `ProcessEpgSDImport`, `ProcessVodChannels*`, `ProcessChannelScrubber*`), `app/Sync/`, or `app/Jobs/Sync*.php`, since that's the highest-cost feature to regress, but don't skip it elsewhere.
4. For §2's straggler check, don't stop at the diff — `grep` the codebase for other call sites matching the pattern a new helper was introduced to replace. This is the one check in this skill that requires looking outside the changed files.
5. Report findings the same way the invoking review flow expects (e.g. via `ReportFindings` when running under `/code-review`) — category slug `pr-review-standards` or the specific rule number, most-severe first.
