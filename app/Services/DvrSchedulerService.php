<?php

namespace App\Services;

use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use App\Jobs\StartDvrRecording;
use App\Jobs\StopDvrRecording;
use App\Models\DvrRecording;
use App\Models\DvrRecordingRule;
use App\Models\DvrSetting;
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
            ->with(['dvrSetting.playlist', 'channel', 'epgChannel'])
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
     */
    private function matchSeriesRule(DvrRecordingRule $rule, int $lookaheadMinutes): void
    {
        if (empty($rule->series_title)) {
            return;
        }

        $now = now();
        $lookahead = now()->addMinutes($lookaheadMinutes);

        $query = EpgProgramme::where('title', 'like', '%'.$rule->series_title.'%')
            ->where('start_time', '>=', $now)
            ->where('start_time', '<=', $lookahead);

        if (! empty($rule->epg_channel_id)) {
            $query->where('epg_channel_id', $rule->epg_channel_id);
        }

        if ($rule->new_only) {
            $query->where('is_new', true);
        }

        $programmes = $query->get();
        if ($programmes->isEmpty()) {
            return;
        }

        // Batch dedup: find existing recording rows for these programme start/channel combinations
        foreach ($programmes as $programme) {
            $this->createScheduledRecordingFromProgramme($rule, $programme);
        }
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

        $now = now();
        $lookahead = now()->addMinutes($lookaheadMinutes);

        $isUpcoming = $programme->start_time >= $now && $programme->start_time <= $lookahead;
        $isCurrentlyAiring = $programme->start_time < $now && $programme->end_time > $now;

        if (! $isUpcoming && ! $isCurrentlyAiring) {
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

        $now = now();
        $lookahead = now()->addMinutes($lookaheadMinutes);

        $isUpcoming = $rule->manual_start >= $now && $rule->manual_start <= $lookahead;
        $isCurrentlyAiring = $rule->manual_start < $now && $rule->manual_end > $now;

        if (! $isUpcoming && ! $isCurrentlyAiring) {
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

            $streamUrl = $this->resolveStreamUrl($rule, $setting);

            DvrRecording::create([
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
            $streamUrl = $this->resolveStreamUrl($rule, $setting);

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

            $streamUrl = $this->resolveStreamUrl($rule, $setting);

            DvrRecording::create([
                'user_id' => $setting->user_id,
                'dvr_setting_id' => $setting->id,
                'dvr_recording_rule_id' => $rule->id,
                'channel_id' => $rule->channel_id,
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
     */
    private function resolveStreamUrl(DvrRecordingRule $rule, DvrSetting $setting): ?string
    {
        if (! $rule->channel_id) {
            return null;
        }

        $channel = $rule->channel;
        if (! $channel) {
            return null;
        }

        // Use the proxy URL when the source playlist has proxy enabled
        $playlist = $setting->playlist;
        if ($playlist && ! empty($playlist->proxy_options['enabled'])) {
            $proxyUrl = $channel->getProxyUrl();

            return $proxyUrl ?: $channel->url;
        }

        return $channel->url;
    }
}
