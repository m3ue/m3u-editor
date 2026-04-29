<?php

namespace App\Services;

use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use App\Jobs\StartDvrRecording;
use App\Jobs\StopDvrRecording;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use Exception;
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
            ->with(['dvrSetting.playlist', 'channel.epgChannel', 'epgChannel'])
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

        $query = EpgProgramme::where('title', 'like', '%'.$rule->series_title.'%')
            ->whereIn('epg_channel_id', $epgChannelStringIds)
            ->where('start_time', '>=', $now)
            ->where('start_time', '<=', $lookahead);

        if ($rule->new_only) {
            $query->where('is_new', true);
        }

        $programmes = $query->get();
        if ($programmes->isEmpty()) {
            return;
        }

        foreach ($programmes as $programme) {
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

            // Check dedup — only block active recordings, not Cancelled/Failed.
            $exists = DvrRecording::where('dvr_recording_rule_id', $rule->id)
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
                'channel_id' => $rule->channel_id,
                'status' => DvrRecordingStatus::Scheduled,
                'title' => 'Manual Recording',
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

            $exists = DvrRecording::where('dvr_recording_rule_id', $rule->id)
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

            $channel = $rule->channel;
            $title = $channel ? ($channel->title_custom ?? $channel->title ?? 'Recording') : 'Recording';
            [$streamUrl] = $this->resolveStreamUrl($rule, $setting);

            $startEarly = $setting->resolveStartEarlySeconds($rule->start_early_seconds);
            $endLate = $setting->resolveEndLateSeconds($rule->end_late_seconds);

            $channel = $rule->channel;
            $title = $channel ? ($channel->title_custom ?? $channel->title ?? 'Recording') : 'Recording';
            [$streamUrl] = $this->resolveStreamUrl($rule, $setting);

            DvrRecording::create([
                'user_id' => $setting->user_id,
                'dvr_setting_id' => $setting->id,
                'dvr_recording_rule_id' => $rule->id,
                'channel_id' => $rule->channel_id,
                'status' => DvrRecordingStatus::Scheduled,
                'title' => $title,
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
            // Check capacity inside transaction so lockForUpdate is effective.
            if ($setting->isAtCapacity()) {
                Log::debug("DVR: Skipping schedule for rule {$rule->id} — at capacity");

                return;
            }

            // Dedup: only block if there's an active recording (Scheduled/Recording/PostProcessing).
            // Cancelled and Failed recordings should not block re-scheduling.
            $exists = DvrRecording::where('dvr_setting_id', $setting->id)
                ->where('programme_start', $programme->start_time)
                ->where('epg_programme_data->epg_channel_id', $programme->epg_channel_id)
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

            $scheduledStart = $programme->start_time->copy()->subSeconds($startEarly);
            $scheduledEnd = $programme->end_time->copy()->addSeconds($endLate);

            [$streamUrl, $resolvedChannelId] = $this->resolveStreamUrl($rule, $setting, $programme);

            $recording = DvrRecording::create([
                'user_id' => $setting->user_id,
                'dvr_setting_id' => $setting->id,
                'dvr_recording_rule_id' => $rule->id,
                'channel_id' => $resolvedChannelId ?? $rule->channel_id,
                'status' => DvrRecordingStatus::Scheduled,
                'title' => $programme->title,
                'subtitle' => $programme->subtitle,
                'description' => $programme->description,
                'season' => $programme->season,
                'episode' => $programme->episode,
                'scheduled_start' => $scheduledStart,
                'scheduled_end' => $scheduledEnd,
                'programme_start' => $programme->start_time,
                'programme_end' => $programme->end_time,
                'stream_url' => $streamUrl,
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

            Log::info("DVR: Scheduled recording for '{$programme->title}'", [
                'rule_id' => $rule->id,
                'scheduled_start' => $scheduledStart,
                'scheduled_end' => $scheduledEnd,
            ]);

            // If the programme is already in progress (scheduledStart <= now),
            // dispatch StartDvrRecording immediately instead of waiting for the next tick.
            // This avoids up to 60s of delay for in-progress recordings.
            if ($scheduledStart->lte(now())) {
                Log::info("DVR: Programme '{$programme->title}' already in progress — dispatching StartDvrRecording immediately");
                StartDvrRecording::dispatch($recording->id)->onQueue('dvr');
            }
        });
    }

    /**
     * Trigger recordings whose scheduled_start has arrived (dispatch StartDvrRecording jobs).
     */
    private function triggerPendingRecordings(): void
    {
        $due = DvrRecording::scheduled()
            ->where('scheduled_start', '<=', now())
            ->with('dvrSetting')
            ->get();

        foreach ($due as $recording) {
            $setting = $recording->dvrSetting;

            if (! $setting || ! $setting->enabled) {
                continue;
            }

            if ($setting->isAtCapacity()) {
                Log::debug("DVR: Skipping trigger for recording {$recording->id} — at capacity");

                continue;
            }

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
            Log::info("DVR: Stopping expired recording {$recording->id} ({$recording->title})");
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

        // Use the proxy URL when the source playlist has proxy enabled
        $playlist = $setting->playlist;
        if ($playlist && ! empty($playlist->proxy_options['enabled'])) {
            $proxyUrl = $channel->getProxyUrl();
            $streamUrl = $proxyUrl ?: $channel->url;
        } else {
            $streamUrl = $channel->url;
        }

        return [$streamUrl, $channel->id];
    }
}
