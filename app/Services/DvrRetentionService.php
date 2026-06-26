<?php

namespace App\Services;

use App\Enums\DvrRecordingStatus;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * DvrRetentionService — Enforces retention policies on completed recordings.
 *
 * Policies (applied in order):
 *   1. keepLast — per rule: keep only the N most recent recordings for a series rule
 *   2. retention_days — per DvrSetting: delete recordings older than N days
 *   3. global_disk_quota_gb — per DvrSetting: if over quota, delete oldest first
 */
class DvrRetentionService
{
    /**
     * Run retention enforcement for all DVR settings.
     */
    public function runAll(): void
    {
        $settings = DvrSetting::where('enabled', true)->get();

        foreach ($settings as $setting) {
            try {
                $this->runForSetting($setting);
            } catch (\Exception $e) {
                Log::error("DVR retention failed for setting {$setting->id}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Run retention for a single DvrSetting.
     */
    public function runForSetting(DvrSetting $setting): void
    {
        // 0. Purge any ghost rows left from previous runs (Completed + no file on disk).
        $this->purgeGhostRows($setting);

        // 1. keepLast per rule
        $this->enforceKeepLast($setting);

        // 2. Age-based retention
        if ($setting->retention_days > 0) {
            $this->enforceRetentionDays($setting);
        }

        // 3. Disk quota
        if ($setting->global_disk_quota_gb > 0) {
            $this->enforceDiskQuota($setting);
        }
    }

    /**
     * Hard-delete completed recording rows that have no file on disk.
     *
     * These "ghost" rows accumulate when a previous retention run deleted the file
     * (setting file_path to null) but the row was left behind. They corrupt the
     * keep_last counter because they count as completed recordings without consuming
     * any storage. Runs at the start of each retention cycle before any other policy.
     */
    private function purgeGhostRows(DvrSetting $setting): void
    {
        $deleted = DvrRecording::where('dvr_setting_id', $setting->id)
            ->where('status', DvrRecordingStatus::Completed->value)
            ->whereNull('file_path')
            ->delete();

        if ($deleted > 0) {
            Log::info("DVR retention: purged {$deleted} ghost recording row(s) for setting {$setting->id}");
        }
    }

    /**
     * For each series_key with keep_last configured, delete excess completed recordings.
     *
     * Keep_last is configured per-rule but enforced per-series_key so overlapping
     * rules targeting the same show don't multiply retention. When multiple rules
     * share a series_key, the largest keep_last wins (the most generous policy).
     *
     * Recordings without a series_key (legacy, or empty title) fall back to
     * grouping by dvr_recording_rule_id so behaviour is preserved for them.
     */
    private function enforceKeepLast(DvrSetting $setting): void
    {
        $rules = $setting->recordingRules()
            ->whereNotNull('keep_last')
            ->where('keep_last', '>', 0)
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        // Group rules by series_key (or fallback rule_id) and pick the most
        // generous keep_last per group.
        $keepByKey = [];
        $rulesWithoutKey = [];

        foreach ($rules as $rule) {
            if (! empty($rule->series_key)) {
                $key = $rule->series_key;
                $keepByKey[$key] = max($keepByKey[$key] ?? 0, (int) $rule->keep_last);
            } else {
                $rulesWithoutKey[] = $rule;
            }
        }

        foreach ($keepByKey as $seriesKey => $keep) {
            $this->trimGroup(
                DvrRecording::where('dvr_setting_id', $setting->id)
                    ->where('series_key', $seriesKey)
                    ->where('status', DvrRecordingStatus::Completed->value),
                $keep,
                $setting,
                "series_key={$seriesKey}"
            );
        }

        foreach ($rulesWithoutKey as $rule) {
            $this->trimGroup(
                DvrRecording::where('dvr_recording_rule_id', $rule->id)
                    ->where('status', DvrRecordingStatus::Completed->value),
                (int) $rule->keep_last,
                $setting,
                "rule_id={$rule->id}"
            );
        }
    }

    /**
     * Delete the file for any recording past position $keep when ordered by most-recent first.
     *
     * Ghost rows (file_path IS NULL) are purged at the start of each retention run, so
     * every row here has a real file on disk and counts toward the keep threshold correctly.
     */
    private function trimGroup(Builder $query, int $keep, DvrSetting $setting, string $groupLabel): void
    {
        $completed = $query->orderByDesc('scheduled_start')->get();

        if ($completed->count() <= $keep) {
            return;
        }

        $toDelete = $completed->slice($keep);

        foreach ($toDelete as $recording) {
            if ($this->deleteRecordingFile($recording, $setting)) {
                Log::info("DVR retention: keepLast deleted recording {$recording->id} ({$recording->title}) [group {$groupLabel}, keep={$keep}]");
            }
        }
    }

    /**
     * Delete completed recordings older than retention_days.
     */
    private function enforceRetentionDays(DvrSetting $setting): void
    {
        $cutoff = now()->subDays($setting->retention_days);

        $old = DvrRecording::where('dvr_setting_id', $setting->id)
            ->where('status', DvrRecordingStatus::Completed->value)
            ->where('actual_end', '<', $cutoff)
            ->get();

        foreach ($old as $recording) {
            if ($this->deleteRecordingFile($recording, $setting)) {
                Log::info("DVR retention: age-based deleted recording {$recording->id} ({$recording->title})");
            }
        }
    }

    /**
     * If disk usage exceeds the quota, delete oldest completed recordings until under quota.
     */
    private function enforceDiskQuota(DvrSetting $setting): void
    {
        $quotaBytes = $setting->global_disk_quota_gb * 1024 * 1024 * 1024;
        $disk = $setting->storage_disk ?: config('dvr.storage_disk');

        $usedBytes = DvrRecording::where('dvr_setting_id', $setting->id)
            ->where('status', DvrRecordingStatus::Completed->value)
            ->whereNotNull('file_size_bytes')
            ->sum('file_size_bytes');

        if ($usedBytes <= $quotaBytes) {
            return;
        }

        Log::info("DVR retention: disk quota exceeded for setting {$setting->id}", [
            'used_gb' => round($usedBytes / (1024 ** 3), 2),
            'quota_gb' => $setting->global_disk_quota_gb,
        ]);

        $recordings = DvrRecording::where('dvr_setting_id', $setting->id)
            ->where('status', DvrRecordingStatus::Completed->value)
            ->whereNotNull('file_size_bytes')
            ->orderBy('actual_end')
            ->get();

        foreach ($recordings as $recording) {
            if ($usedBytes <= $quotaBytes) {
                break;
            }

            $fileSize = (int) ($recording->file_size_bytes ?? 0);

            if ($this->deleteRecordingFile($recording, $setting)) {
                $usedBytes -= $fileSize;
                Log::info("DVR retention: quota-based deleted recording {$recording->id} ({$recording->title})");
            } else {
                Log::warning("DVR retention: skipping size deduction for recording {$recording->id} — file deletion failed");
            }
        }
    }

    /**
     * Delete the file on disk then mark the recording row as Purged.
     *
     * The row is kept (with status=Purged, file_path=null) as a permanent dedup sentinel
     * so DvrRecordingRule::alreadyHaveEpisode() continues to block re-recording of the
     * same episode after retention prunes the file. Hard-deleting the row would cause the
     * scheduler to re-schedule the same episode on its next pass.
     *
     * Uses a raw query to bypass model hooks (avoids N+1 relation loads and rule cascades
     * that are inappropriate in a bulk retention context).
     */
    private function deleteRecordingFile(DvrRecording $recording, DvrSetting $setting): bool
    {
        if (empty($recording->file_path)) {
            return true;
        }

        $storageDisk = $setting->storage_disk ?: config('dvr.storage_disk');

        try {
            if (Storage::disk($storageDisk)->exists($recording->file_path)) {
                Storage::disk($storageDisk)->delete($recording->file_path);
            }

            DvrRecording::where('id', $recording->id)->update([
                'status' => DvrRecordingStatus::Purged->value,
                'file_path' => null,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::warning("DVR retention: failed to delete file {$recording->file_path}: {$e->getMessage()}");

            return false;
        }
    }
}
