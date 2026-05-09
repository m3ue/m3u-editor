<?php

namespace App\Services;

use App\Enums\DvrMatchMode;
use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use App\Enums\DvrSeriesMode;
use App\Jobs\StartDvrRecording;
use App\Jobs\StopDvrRecording;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Support\SeriesKey;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DvrSchedulerService — Runs every minute via the DvrSchedulerTick job.
 *
 * Responsibilities:
 * 1. Match enabled rules against upcoming epg_programmes (30-min lookahead)
 * 2. Deduplicate: skip programmes that already have a recording row
 * 3. Conflict resolution: respect max_concurrent_recordings
 * 4. Create SCHEDULED recording rows
 * 5. Trigger recordings whose scheduled_start <= now
 * 6. Stop recordings whose scheduled_end <= now
 */
class DvrSchedulerService
{
    /**
     * Execute one scheduler tick.
     */
    public function tick(): void
    {
        try {
            $lookaheadMinutes = (int) config('dvr.scheduler_lookahead_minutes', 30);

            // Step 1-4: Match rules → create SCHEDULED recordings
            $this->matchAndSchedule($lookaheadMinutes);

            // Step 5: Trigger recordings whose time has come
            $this->triggerPendingRecordings();

            // Step 6: Stop recordings whose scheduled end has passed
            $this->stopExpiredRecordings();
        } catch (Exception $e) {
            Log::error('DVR scheduler tick failed: '.$e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Match all enabled rules against upcoming programmes and create SCHEDULED rows.
     *
     * @param  int  $lookaheadMinutes  How many minutes ahead to search
     */
    private function matchAndSchedule(int $lookaheadMinutes): void
    {
        $rules = DvrRecordingRule::enabled()
            ->with(['dvrSetting.playlist', 'channel.epgChannel', 'sourceChannel.epgChannel', 'epgChannel'])
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        foreach ($rules as $rule) {
            try {
                $this->matchRule($rule, $lookaheadMinutes);
            } catch (Exception $e) {
                Log::error("DVR: Failed to match rule {$rule->id}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Dispatch rule matching to the appropriate type handler.
     */
    private function matchRule(DvrRecordingRule $rule, int $lookaheadMinutes): void
    {
        match ($rule->type) {
            DvrRuleType::Series => $this->matchSeriesRule($rule, $lookaheadMinutes),
            DvrRuleType::Once => $this->matchOnceRule($rule, $lookaheadMinutes),
            DvrRuleType::Manual => $this->matchManualRule($rule, $lookaheadMinutes),
        };
    }

    /**
     * SERIES rule: match all upcoming programmes by title (case-insensitive contains).
     * Scopes the programme query to EPG channels that belong to the DVR setting's
     * playlist to avoid matching programmes from unrelated EPG feeds.
     */
    private function matchSeriesRule(DvrRecordingRule $rule, int $lookaheadMinutes): void
    {
        if (empty($rule->series_title)) {
            return;
        }

        $epgChannelStringIds = $this->resolveSeriesEpgScope($rule);

        if (empty($epgChannelStringIds)) {
            return;
        }

        $now = now();
        $lookahead = now()->addMinutes($lookaheadMinutes);

        $query = EpgProgramme::query()
            ->whereIn('epg_channel_id', $epgChannelStringIds)
            ->where('start_time', '>=', $now)
            ->where('start_time', '<=', $lookahead);

        $title = $rule->series_title;
        $matchMode = $rule->match_mode ?? DvrMatchMode::Contains;

        if ($matchMode === DvrMatchMode::Tmdb) {
            if (empty($rule->tmdb_id)) {
                return;
            }

            $query->where('tmdb_id', $rule->tmdb_id);
        } else {
            [$sql, $binding] = match ($matchMode) {
                DvrMatchMode::Exact => ['lower(title) = lower(?)', $title],
                DvrMatchMode::StartsWith => ['lower(title) LIKE lower(?)', $title.'%'],
                default => ['lower(title) LIKE lower(?)', '%'.$title.'%'],
            };

            $query->whereRaw($sql, [$binding]);
        }

        if ($rule->series_mode === DvrSeriesMode::NewFlag) {
            $query->where('is_new', true);
        }

        $programmes = $query->get();
        if ($programmes->isEmpty()) {
            return;
        }

        // For unique_se mode, pre-compute series_key so we can check alreadyHaveEpisode
        // before attempting to schedule each programme.
        $seriesKey = $rule->series_mode === DvrSeriesMode::UniqueSe
            ? SeriesKey::for($rule->dvrSetting->id, $rule->series_title)
            : null;

        foreach ($programmes as $programme) {
            if ($seriesKey !== null && $rule->alreadyHaveEpisode($seriesKey, $programme->season, $programme->episode)) {
                Log::debug('DVR: Skipping programme — already have episode', [
                    'rule_id' => $rule->id,
                    'title' => $programme->title,
                    'season' => $programme->season,
                    'episode' => $programme->episode,
                ]);

                continue;
            }

            $this->createScheduledRecordingFromProgramme($rule, $programme);
        }
    }

    /**
     * Resolve the EPG channel string IDs (e.g. "CNN", "BBC One") that a series rule
     * should match programmes against. This prevents cross-playlist contamination and
     * ensures the EPG fallback in resolveStreamUrl can always find a channel.
     *
     * Priority:
     *   1. Rule has epg_channel_id (int FK to epg_channels) → use that channel's string ID
     *   2. Rule has channel_id → use that channel's EPG channel string ID
     *   3. Neither → all EPG-mapped channels in the DVR setting's playlist
     *
     * @return list<string>
     */
    private function resolveSeriesEpgScope(DvrRecordingRule $rule): array
    {
        // 1. Explicit EPG channel set on the rule
        if ($rule->epg_channel_id) {
            $stringId = $rule->epgChannel?->channel_id;

            return $stringId ? [$stringId] : [];
        }

        // 2. Pinned channel: derive EPG scope from that channel's mapping
        if ($rule->channel_id) {
            $stringId = $rule->channel?->epgChannel?->channel_id;

            return $stringId ? [$stringId] : [];
        }

        // 2.5. Source channel: created via Browse Shows; use the original channel's EPG mapping
        if ($rule->source_channel_id) {
            $stringId = $rule->sourceChannel?->epgChannel?->channel_id;

            return $stringId ? [$stringId] : [];
        }

        // 3. No explicit channel: scope to all EPG-mapped channels in the playlist
        $playlistId = $rule->dvrSetting->playlist_id;

        return Channel::where('playlist_id', $playlistId)
            ->whereNotNull('epg_channel_id')
            ->with('epgChannel')
            ->get()
            ->map(fn (Channel $c) => $c->epgChannel?->channel_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * ONCE rule: match a specific programme by start time + channel.
     * Falls back to the current dummy EPG slot when no programme is linked.
     */
    private function matchOnceRule(DvrRecordingRule $rule, int $lookaheadMinutes): void
    {
        if (empty($rule->programme_id)) {
            $this->matchOnceRuleViaDummyEpg($rule, $lookaheadMinutes);

            return;
        }

        $programme = EpgProgramme::find($rule->programme_id);

        if (! $programme) {
            // Programme was replaced by an EPG refresh — disable the dead rule
            Log::warning("DVR ONCE rule {$rule->id}: programme_id {$rule->programme_id} not found — disabling rule");
            $rule->update(['enabled' => false]);

            return;
        }

        // For a Once rule targeting a specific programme we schedule as soon as the rule
        // exists — as long as the programme hasn't finished airing. The 30-minute lookahead
        // is irrelevant here because we already know the exact air-time.
        if ($programme->end_time <= now()) {
            return;
        }

        $this->createScheduledRecordingFromProgramme($rule, $programme);
    }

    /**
     * MANUAL rule: create a recording for the specified manual_start/manual_end window.
     */
    private function matchManualRule(DvrRecordingRule $rule, int $lookaheadMinutes): void
    {
        if (! $rule->manual_start || ! $rule->manual_end) {
            return;
        }

        // Schedule immediately as long as the manual window hasn't ended.
        // There is no reason to hold off until 30 minutes before manual_start —
        // the user explicitly set a start time and expects it to appear scheduled right away.
        if ($rule->manual_end <= now()) {
            return;
        }

        $setting = $rule->dvrSetting;
        if (! $setting || ! $setting->enabled) {
            return;
        }

        DB::transaction(function () use ($rule, $setting): void {
            // Check capacity inside transaction so lockForUpdate is effective.
            if ($setting->isAtCapacity()) {
                Log::debug("DVR: Skipping manual recording for rule {$rule->id} — at capacity");

                return;
            }

            // Manual recordings have no canonical "title" until we resolve from the
            // channel below — derive series_key from the channel display name when
            // available, falling back to a per-rule key so dedup is at least scoped.
            $channel = $rule->channel;
            $title = $channel
                ? ($channel->title_custom ?? $channel->title ?? 'Manual Recording')
                : 'Manual Recording';
            $seriesKey = SeriesKey::for($setting->id, $title) ?? "setting:{$setting->id}|rule:{$rule->id}";
            $normalizedTitle = SeriesKey::normalize($title) ?: null;

            // Check dedup — only block active recordings, not Cancelled/Failed.
            $exists = DvrRecording::where('series_key', $seriesKey)
                ->where('programme_start', $rule->manual_start)
                ->whereIn('status', [
                    DvrRecordingStatus::Scheduled,
                    DvrRecordingStatus::Recording,
                    DvrRecordingStatus::PostProcessing,
                ])
                ->exists();

            if ($exists) {
                return;
            }

            $startEarly = $setting->resolveStartEarlySeconds($rule->start_early_seconds);
            $endLate = $setting->resolveEndLateSeconds($rule->end_late_seconds);

            $scheduledStart = $rule->manual_start->subSeconds($startEarly);
            $scheduledEnd = $rule->manual_end->addSeconds($endLate);

            [$streamUrl] = $this->resolveStreamUrl($rule, $setting);

            $recording = DvrRecording::create([
                'user_id' => $setting->user_id,
                'dvr_setting_id' => $setting->id,
                'dvr_recording_rule_id' => $rule->id,
                'playlist_auth_id' => $rule->playlist_auth_id,
                'channel_id' => $rule->channel_id,
                'status' => DvrRecordingStatus::Scheduled,
                'title' => $title,
                'series_key' => $seriesKey,
                'normalized_title' => $normalizedTitle,
                'scheduled_start' => $scheduledStart,
                'scheduled_end' => $scheduledEnd,
                'programme_start' => $rule->manual_start,
                'programme_end' => $rule->manual_end,
                'stream_url' => $streamUrl,
            ]);

            // If the manual window is already in progress, dispatch immediately.
            if ($scheduledStart->lte(now())) {
                Log::info("DVR: Manual recording for rule {$rule->id} already in progress — dispatching StartDvrRecording immediately");
                StartDvrRecording::dispatch($recording->id)->onQueue('dvr');
            }
        });
    }

    /**
     * ONCE rule fallback: schedule the current dummy EPG slot when no programme_id is set.
     *
     * Dummy EPG slots are aligned to midnight in blocks of `dummy_epg_length` minutes.
     * After scheduling, the rule is disabled so only one recording is created.
     */
    private function matchOnceRuleViaDummyEpg(DvrRecordingRule $rule, int $lookaheadMinutes): void
    {
        $setting = $rule->dvrSetting;
        if (! $setting || ! $setting->enabled) {
            return;
        }

        $playlist = $setting->playlist;
        if (! $playlist || ! $playlist->dummy_epg || ! $rule->channel_id) {
            return;
        }

        $dummyEpgLength = (int) ($playlist->dummy_epg_length ?? 120);

        $now = now();
        $startOfDay = $now->copy()->startOfDay();
        $minutesSinceMidnight = (int) floor($startOfDay->diffInMinutes($now));
        $slotIndex = (int) floor($minutesSinceMidnight / $dummyEpgLength);
        $slotStart = $startOfDay->copy()->addMinutes($slotIndex * $dummyEpgLength);
        $slotEnd = $slotStart->copy()->addMinutes($dummyEpgLength);
        $lookahead = $now->copy()->addMinutes($lookaheadMinutes);

        $isCurrentlyAiring = $slotStart <= $now && $slotEnd > $now;
        $isUpcoming = $slotStart > $now && $slotStart <= $lookahead;

        if (! $isCurrentlyAiring && ! $isUpcoming) {
            return;
        }

        DB::transaction(function () use ($rule, $setting, $slotStart, $slotEnd): void {
            if ($setting->isAtCapacity()) {
                return;
            }

            $channel = $rule->channel;
            $title = $channel ? ($channel->title_custom ?? $channel->title ?? 'Recording') : 'Recording';

            $seriesKey = SeriesKey::for($setting->id, $title) ?? "setting:{$setting->id}|rule:{$rule->id}";
            $normalizedTitle = SeriesKey::normalize($title) ?: null;

            $exists = DvrRecording::where('series_key', $seriesKey)
                ->where('programme_start', $slotStart)
                ->whereIn('status', [
                    DvrRecordingStatus::Scheduled,
                    DvrRecordingStatus::Recording,
                    DvrRecordingStatus::PostProcessing,
                ])
                ->exists();

            if ($exists) {
                return;
            }

            $startEarly = $setting->resolveStartEarlySeconds($rule->start_early_seconds);
            $endLate = $setting->resolveEndLateSeconds($rule->end_late_seconds);

            [$streamUrl] = $this->resolveStreamUrl($rule, $setting);

            DvrRecording::create([
                'user_id' => $setting->user_id,
                'dvr_setting_id' => $setting->id,
                'dvr_recording_rule_id' => $rule->id,
                'channel_id' => $rule->channel_id,
                'status' => DvrRecordingStatus::Scheduled,
                'title' => $title,
                'series_key' => $seriesKey,
                'normalized_title' => $normalizedTitle,
                'scheduled_start' => $slotStart->copy()->subSeconds($startEarly),
                'scheduled_end' => $slotEnd->copy()->addSeconds($endLate),
                'programme_start' => $slotStart,
                'programme_end' => $slotEnd,
                'stream_url' => $streamUrl,
            ]);

            // Disable once the recording is scheduled — "once" means record this slot, then stop.
            $rule->update(['enabled' => false]);

            Log::info("DVR: Scheduled once recording via dummy EPG for '{$title}'", [
                'rule_id' => $rule->id,
                'slot_start' => $slotStart,
                'slot_end' => $slotEnd,
            ]);
        });
    }

    /**
     * Create a SCHEDULED DvrRecording from a rule + programme, if not already scheduled.
     */
    private function createScheduledRecordingFromProgramme(DvrRecordingRule $rule, EpgProgramme $programme): void
    {
        $setting = $rule->dvrSetting;
        if (! $setting || ! $setting->enabled) {
            return;
        }

        DB::transaction(function () use ($rule, $setting, $programme): void {
            if ($setting->isAtCapacity()) {
                Log::debug("DVR: Skipping schedule for rule {$rule->id} — at capacity");

                return;
            }

            $seriesKey = SeriesKey::for($setting->id, $programme->title);
            $normalizedTitle = SeriesKey::normalize($programme->title);
            $programmeUid = $this->buildProgrammeUid($programme);

            // Phase 3: handle Failed recordings within the airing window.
            // Instead of creating a brand-new row, we resurrect the existing Failed one if:
            //   - it is within its airing window (scheduled_end > now)
            //   - user_cancelled is false
            //   - attempt_count < max_attempts_per_airing
            $maxAttempts = (int) config('dvr.max_attempts_per_airing', 3);
            $existingFailed = $this->findRetriableFailedRecording($rule, $setting, $programme, $seriesKey, $maxAttempts);

            if ($existingFailed) {
                Log::info("DVR: Retrying failed recording {$existingFailed->id} for '{$programme->title}'", [
                    'rule_id' => $rule->id,
                    'attempt' => $existingFailed->attempt_count + 1,
                    'max' => $maxAttempts,
                ]);

                $existingFailed->update([
                    'status' => DvrRecordingStatus::Scheduled->value,
                    'error_message' => null,
                ]);

                if ($programme->start_time->lte(now())) {
                    StartDvrRecording::dispatch($existingFailed->id)->onQueue('dvr');
                }

                return;
            }

            // Phase 3: If a Failed row exists for this programme whose attempts are
            // already exhausted (attempt_count >= maxAttempts), do not create a new row.
            // We have no more retry budget for this airing window.
            $exhausted = $this->findExhaustedFailedRecording($rule, $setting, $programme, $maxAttempts);

            if ($exhausted) {
                Log::debug("DVR: Skipping {$programme->title} — attempt budget exhausted", [
                    'rule_id' => $rule->id,
                    'attempt_count' => $exhausted->attempt_count,
                    'max' => $maxAttempts,
                ]);

                return;
            }

            // Dedup: block if there's an active recording (Scheduled/Recording/PostProcessing)
            // for the same programme_uid (stable identity: epg_channel_id + title + season + episode).
            // Falls back to (programme_start, epg_channel_id) for legacy recordings without programme_uid.
            //
            // Phase 6: programme_uid replaces (programme_start, epg_channel_id) as the primary
            // dedup key, making the scheduler resilient to EPG time drifts.
            //
            // Phase 3: also block if there's a user_cancelled=True recording.
            $dedupQuery = DvrRecording::query()
                ->where(function (Builder $q) use ($programmeUid, $programme): void {
                    $q->where('programme_uid', $programmeUid)
                        ->orWhere(function (Builder $q) use ($programme): void {
                            $q->whereNull('programme_uid')
                                ->where('programme_start', $programme->start_time)
                                ->where('epg_programme_data->epg_channel_id', $programme->epg_channel_id);
                        });
                })
                ->whereIn('status', [
                    DvrRecordingStatus::Scheduled,
                    DvrRecordingStatus::Recording,
                    DvrRecordingStatus::PostProcessing,
                ]);

            if ($seriesKey !== null) {
                $dedupQuery->where(function (Builder $q) use ($seriesKey, $rule): void {
                    $q->where('series_key', $seriesKey)
                        ->orWhere(function (Builder $q) use ($rule): void {
                            $q->whereNull('series_key')
                                ->where('dvr_recording_rule_id', $rule->id);
                        });
                });
            } else {
                $dedupQuery->where('dvr_setting_id', $setting->id);
            }

            if ($dedupQuery->exists()) {
                return;
            }

            // Also block if a user_cancelled recording exists for the same programme
            // (explicit user intent should be respected — do not auto-re-record).
            if ($this->isProgrammeUserCancelled($programme, $programmeUid, $seriesKey, $rule, $setting)) {
                Log::debug("DVR: Skipping {$programme->title} — user cancelled this airing");

                return;
            }

            $startEarly = $setting->resolveStartEarlySeconds($rule->start_early_seconds);
            $endLate = $setting->resolveEndLateSeconds($rule->end_late_seconds);

            $scheduledStart = $programme->start_time->copy()->subSeconds($startEarly);
            $scheduledEnd = $programme->end_time->copy()->addSeconds($endLate);

            [$streamUrl, $resolvedChannelId] = $this->resolveStreamUrl($rule, $setting, $programme);

            $recording = DvrRecording::create([
                'user_id' => $setting->user_id,
                'dvr_setting_id' => $setting->id,
                'dvr_recording_rule_id' => $rule->id,
                'playlist_auth_id' => $rule->playlist_auth_id,
                'channel_id' => $resolvedChannelId ?? $rule->channel_id,
                'status' => DvrRecordingStatus::Scheduled,
                'title' => $programme->title,
                'series_key' => $seriesKey,
                'normalized_title' => $normalizedTitle ?: null,
                'subtitle' => $programme->subtitle,
                'description' => $programme->description,
                'season' => $programme->season,
                'episode' => $programme->episode,
                'scheduled_start' => $scheduledStart,
                'scheduled_end' => $scheduledEnd,
                'programme_start' => $programme->start_time,
                'programme_end' => $programme->end_time,
                'stream_url' => $streamUrl,
                'attempt_count' => 1,
                'programme_uid' => $programmeUid,
                'epg_programme_data' => [
                    'epg_id' => $programme->epg_id,
                    'epg_channel_id' => $programme->epg_channel_id,
                    'episode_num' => $programme->episode_num,
                    'category' => $programme->category,
                    'icon' => $programme->icon,
                    'rating' => $programme->rating,
                    'is_new' => $programme->is_new,
                ],
            ]);

            if ($scheduledStart->lte(now())) {
                Log::info("DVR: Programme '{$programme->title}' already in progress — dispatching StartDvrRecording immediately");
                StartDvrRecording::dispatch($recording->id)->onQueue('dvr');
            }
        });
    }

    /**
     * Trigger recordings whose scheduled_start has arrived (dispatch StartDvrRecording jobs).
     *
     * Skips and fails any rows whose entire scheduled window has already passed
     * (e.g. the queue was down). Counts rows already due against capacity so a
     * single tick cannot dispatch more starts than free slots.
     */
    private function triggerPendingRecordings(): void
    {
        $now = now();

        DvrRecording::scheduled()
            ->where('scheduled_end', '<=', $now)
            ->get()
            ->each(function (DvrRecording $stale): void {
                Log::warning("DVR: Marking stale Scheduled recording {$stale->id} as Failed — window already ended", [
                    'recording_id' => $stale->id,
                    'scheduled_start' => $stale->scheduled_start,
                    'scheduled_end' => $stale->scheduled_end,
                ]);

                $stale->update([
                    'status' => DvrRecordingStatus::Failed->value,
                    'error_message' => 'Missed recording window — scheduler did not fire before scheduled_end.',
                ]);
            });

        $due = DvrRecording::scheduled()
            ->where('scheduled_start', '<=', $now)
            ->where('scheduled_end', '>', $now)
            ->with(['dvrSetting', 'recordingRule'])
            ->orderBy('scheduled_start')
            ->get()
            ->sortByDesc(fn (DvrRecording $r) => $r->recordingRule?->priority ?? 0)
            ->values();

        $pendingStartsBySetting = [];

        foreach ($due as $recording) {
            $setting = $recording->dvrSetting;

            if (! $setting || ! $setting->enabled) {
                continue;
            }

            $sid = $setting->id;
            $extra = $pendingStartsBySetting[$sid] ?? 0;

            if ($setting->isAtCapacity($extra)) {
                Log::debug("DVR: Skipping trigger for recording {$recording->id} — at capacity (pending_in_tick={$extra})");

                continue;
            }

            $pendingStartsBySetting[$sid] = $extra + 1;

            Log::info("DVR: Triggering recording {$recording->id} ({$recording->title})");
            StartDvrRecording::dispatch($recording->id)->onQueue('dvr');
        }
    }

    /**
     * Stop recordings whose scheduled_end has passed (dispatch StopDvrRecording jobs).
     */
    private function stopExpiredRecordings(): void
    {
        $expired = DvrRecording::recording()
            ->where('scheduled_end', '<=', now())
            ->get();

        foreach ($expired as $recording) {
            Log::info("DvrSchedulerService: Stopping expired recording {$recording->id} ({$recording->title})");
            StopDvrRecording::dispatch($recording->id)->onQueue('dvr');
        }
    }

    /**
     * Resolve the stream URL to use for a recording.
     *
     * Uses the channel proxy URL if the source playlist has proxy enabled.
     * When the rule has no channel_id, falls back to resolving via the programme's
     * EPG channel ID so that rules created without an explicit channel still record.
     *
     * @return array{0: string|null, 1: int|null} [streamUrl, resolvedChannelId]
     */
    private function resolveStreamUrl(DvrRecordingRule $rule, DvrSetting $setting, ?EpgProgramme $programme = null): array
    {
        $channel = null;

        if ($rule->channel_id) {
            $channel = $rule->channel;
        } elseif ($rule->source_channel_id) {
            // Rule was created via Browse Shows — use the original source channel directly.
            $channel = $rule->sourceChannel;
        } elseif ($programme?->epg_channel_id) {
            // Rule was created without an explicit channel (e.g. series defaults or guest once rule).
            // Attempt to resolve the matching channel from the programme's EPG channel ID.
            $epgChannelPk = EpgChannel::where('channel_id', $programme->epg_channel_id)->value('id');

            if ($epgChannelPk) {
                $channel = Channel::where('playlist_id', $setting->playlist_id)
                    ->where('epg_channel_id', $epgChannelPk)
                    ->first();

                if ($channel) {
                    Log::debug('DVR: Resolved channel via EPG fallback', [
                        'rule_id' => $rule->id,
                        'epg_channel_id' => $programme->epg_channel_id,
                        'channel_id' => $channel->id,
                    ]);
                }
            }
        }

        if (! $channel) {
            return [null, null];
        }

        // Use the proxy URL when both the DVR setting and the source playlist have
        // proxy enabled. The DVR-level toggle lets a user opt out of proxying for a
        // specific DVR profile even when the playlist is proxied for streaming.
        $playlist = $setting->playlist;
        $playlistProxy = $playlist && ! empty($playlist->proxy_options['enabled']);

        if ($setting->use_proxy && $playlistProxy) {
            $proxyUrl = $channel->getProxyUrl();
            $streamUrl = $proxyUrl ?: $channel->url;
        } else {
            $streamUrl = $channel->url;
        }

        return [$streamUrl, $channel->id];
    }

    /**
     * Find a Failed recording for the same programme that is eligible for a retry.
     *
     * Conditions:
     *   - status = Failed
     *   - user_cancelled = false (user cancellations should never auto-retry)
     *   - scheduled_end > now() (still within its airing window)
     *   - attempt_count < maxAttempts (hasn't exhausted retry budget)
     *   - series_key matches (same show, or same rule for legacy null-key recordings)
     *
     * Returns the DvrRecording model, or null if none is retriable.
     */
    private function findRetriableFailedRecording(
        DvrRecordingRule $rule,
        DvrSetting $setting,
        EpgProgramme $programme,
        ?string $seriesKey,
        int $maxAttempts,
    ): ?DvrRecording {
        $programmeUid = $this->buildProgrammeUid($programme);

        $query = DvrRecording::query()
            ->where(function (Builder $q) use ($programmeUid, $programme): void {
                $q->where('programme_uid', $programmeUid)
                    ->orWhere(function (Builder $q) use ($programme): void {
                        $q->whereNull('programme_uid')
                            ->where('programme_start', $programme->start_time)
                            ->where('epg_programme_data->epg_channel_id', $programme->epg_channel_id);
                    });
            })
            ->where('status', DvrRecordingStatus::Failed)
            ->where('user_cancelled', false)
            ->where('scheduled_end', '>', now())
            ->where('attempt_count', '<', $maxAttempts);

        if ($seriesKey !== null) {
            $query->where(function (Builder $q) use ($seriesKey, $rule): void {
                $q->where('series_key', $seriesKey)
                    ->orWhere(function (Builder $q) use ($rule): void {
                        $q->whereNull('series_key')
                            ->where('dvr_recording_rule_id', $rule->id);
                    });
            });
        } else {
            $query->where('dvr_setting_id', $setting->id);
        }

        return $query->first();
    }

    /**
     * Build a stable programme UID from an EPG programme.
     *
     * This is a deterministic hash of the programme's identity (channel + title + season + episode)
     * that does NOT include start_time. It remains stable even when the EPG provider shifts
     * the broadcast time due to schedule changes (sports overrun, etc.).
     *
     * Used as the primary dedup key in place of (programme_start, epg_channel_id).
     */
    private function buildProgrammeUid(EpgProgramme $programme): string
    {
        return md5(sprintf(
            '%s|%s|%s|%s',
            $programme->epg_channel_id,
            $programme->title,
            (string) ($programme->season ?? ''),
            (string) ($programme->episode ?? ''),
        ));
    }

    /**
     * Find a Failed recording that has exhausted its retry budget for the same programme.
     *
     * This is used as a blocking check: when such a row exists, we must not create
     * a new recording row for the same programme_start + epg_channel_id.
     *
     * Conditions:
     *   - status = Failed
     *   - user_cancelled = false
     *   - scheduled_end > now() (still within its airing window)
     *   - attempt_count >= maxAttempts (budget exhausted)
     *   - series_key matches (same show, or same rule for legacy null-key recordings)
     */
    private function findExhaustedFailedRecording(
        DvrRecordingRule $rule,
        DvrSetting $setting,
        EpgProgramme $programme,
        int $maxAttempts,
    ): ?DvrRecording {
        $programmeUid = $this->buildProgrammeUid($programme);

        $query = DvrRecording::query()
            ->where(function (Builder $q) use ($programmeUid, $programme): void {
                $q->where('programme_uid', $programmeUid)
                    ->orWhere(function (Builder $q) use ($programme): void {
                        $q->whereNull('programme_uid')
                            ->where('programme_start', $programme->start_time)
                            ->where('epg_programme_data->epg_channel_id', $programme->epg_channel_id);
                    });
            })
            ->where('status', DvrRecordingStatus::Failed)
            ->where('user_cancelled', false)
            ->where('scheduled_end', '>', now())
            ->where('attempt_count', '>=', $maxAttempts);

        $seriesKey = SeriesKey::for($setting->id, $programme->title);

        if ($seriesKey !== null) {
            $query->where(function (Builder $q) use ($seriesKey, $rule): void {
                $q->where('series_key', $seriesKey)
                    ->orWhere(function (Builder $q) use ($rule): void {
                        $q->whereNull('series_key')
                            ->where('dvr_recording_rule_id', $rule->id);
                    });
            });
        } else {
            $query->where('dvr_setting_id', $setting->id);
        }

        return $query->first();
    }

    /**
     * Determine whether the user has explicitly cancelled a recording for this programme.
     *
     * A user-cancelled row means the user does not want this specific airing recorded
     * — even if a series rule would otherwise match. This respects explicit user intent.
     *
     * Uses the same programme identity + series_key scoping pattern as
     * findExhaustedFailedRecording so the two blocking checks are consistent.
     */
    private function isProgrammeUserCancelled(
        EpgProgramme $programme,
        string $programmeUid,
        ?string $seriesKey,
        DvrRecordingRule $rule,
        DvrSetting $setting,
    ): bool {
        $query = DvrRecording::query()
            ->where(function (Builder $q) use ($programmeUid, $programme): void {
                $q->where('programme_uid', $programmeUid)
                    ->orWhere(function (Builder $q) use ($programme): void {
                        $q->whereNull('programme_uid')
                            ->where('programme_start', $programme->start_time)
                            ->where('epg_programme_data->epg_channel_id', $programme->epg_channel_id);
                    });
            })
            ->where('user_cancelled', true);

        if ($seriesKey !== null) {
            $query->where(function (Builder $q) use ($seriesKey, $rule): void {
                $q->where('series_key', $seriesKey)
                    ->orWhere(function (Builder $q) use ($rule): void {
                        $q->whereNull('series_key')
                            ->where('dvr_recording_rule_id', $rule->id);
                    });
            });
        } else {
            $query->where('dvr_setting_id', $setting->id);
        }

        return $query->exists();
    }
}
