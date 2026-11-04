<?php

namespace App\Services;

use App\Casts\UtcDateTime;
use App\Enums\DvrRecordingStatus;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use Filament\Notifications\Notification;
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
     * How long a file must stay missing, while other library files are present, before
     * its recordings are treated as gone rather than temporarily unavailable.
     */
    private const MISSING_FILE_GRACE_DAYS = 7;

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
     * share a series_key, the most generous policy wins: an explicit unlimited
     * rule (keep_last null or 0) protects every recording under that series_key,
     * even if a sibling rule for the same series has a positive limit. Only when
     * every rule sharing the key has a positive limit does the largest of those
     * limits apply.
     *
     * Recordings without a series_key (legacy, or empty title) fall back to
     * grouping by dvr_recording_rule_id so behaviour is preserved for them.
     */
    private function enforceKeepLast(DvrSetting $setting): void
    {
        $rules = $setting->recordingRules()->get();

        if ($rules->isEmpty()) {
            return;
        }

        // Group rules by series_key (or fallback rule_id). Track the largest
        // positive keep_last per group, and whether the group contains any
        // unlimited rule (keep_last null or 0) that must veto trimming entirely.
        $keepByKey = [];
        $unlimitedKeys = [];
        $rulesWithoutKey = [];

        foreach ($rules as $rule) {
            if (empty($rule->series_key)) {
                if ((int) $rule->keep_last > 0) {
                    $rulesWithoutKey[] = $rule;
                }

                continue;
            }

            $key = $rule->series_key;

            if ($rule->keep_last === null || (int) $rule->keep_last === 0) {
                $unlimitedKeys[$key] = true;
            } else {
                $keepByKey[$key] = max($keepByKey[$key] ?? 0, (int) $rule->keep_last);
            }
        }

        foreach ($keepByKey as $seriesKey => $keep) {
            if (! empty($unlimitedKeys[$seriesKey])) {
                // An unlimited rule shares this series_key - it must not lose
                // recordings to a sibling rule's finite limit.
                continue;
            }

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
        $cutoff = UtcDateTime::forQuery(now()->subDays($setting->retention_days));

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
        $files = $this->reconcileMissingFiles($setting, $this->libraryFiles($setting));

        // A file that isn't on disk uses no space: leave it out of the total (so other
        // recordings aren't evicted for it).
        $present = array_values(array_filter($files, fn (array $file): bool => $file['on_disk']));
        $usedBytes = $this->bytesOnDisk($files);

        if ($usedBytes <= $quotaBytes) {
            return;
        }

        // When half or more of the library looks missing, the storage is probably not
        // (fully) mounted: the sizes can't be trusted, so don't evict anything this run.
        // Files that stay missing leave the library after the grace period, so this clears.
        $missing = count($files) - count($present);
        if ($missing > 0 && $missing * 2 >= count($files)) {
            Log::warning("DVR retention: {$missing} of ".count($files).' library files are not on disk - storage may be unavailable, skipping quota enforcement', [
                'setting_id' => $setting->id,
            ]);

            return;
        }

        Log::info("DVR retention: quota exceeded for setting {$setting->id}", [
            'used_gb' => round($usedBytes / 1024 / 1024 / 1024, 2),
            'quota_gb' => $setting->global_disk_quota_gb,
        ]);

        foreach ($present as $file) {
            if ($usedBytes <= $quotaBytes) {
                break;
            }

            $recording = DvrRecording::find($file['recording_ids'][0]);

            if ($recording && $this->deleteRecordingFile($recording, $setting, $file['recording_ids'])) {
                Log::info("DVR retention: quota-based deleted {$file['path']} (recordings ".implode(', ', $file['recording_ids']).')');
            } else {
                Log::warning("DVR retention: could not free {$file['path']} - file deletion failed");
            }

            // Freed or stuck, this run can't reclaim the file's space any more. Leaving a
            // stuck file in the total would evict every other recording trying to make up
            // for space that can't come back.
            $usedBytes -= $file['bytes'];
        }
    }

    /**
     * Library space used by a DVR setting, as the disk quota measures it: the size on
     * disk of each library file that is present, counted once.
     */
    public function libraryUsageBytes(DvrSetting $setting): int
    {
        return $this->bytesOnDisk($this->libraryFiles($setting));
    }

    /**
     * @param  list<array{bytes: int, on_disk: bool}>  $files
     */
    private function bytesOnDisk(array $files): int
    {
        return array_sum(array_column(array_filter($files, fn (array $file): bool => $file['on_disk']), 'bytes'));
    }

    /**
     * Track when library files went missing, and retire the ones that stayed missing.
     *
     * A missing file can mean an unavailable share or a file that is really gone. Rows
     * are marked when their file first goes missing (the owner is told once) and cleared
     * if it comes back. A file still missing after the grace period, while other files
     * prove the storage is there, has its rows purged. With no files present at all the
     * storage itself may be down, so nothing is purged however long that lasts.
     *
     * @param  list<array{path: string, bytes: int, on_disk: bool, missing_since: ?int, recording_ids: list<int>}>  $files
     * @return list<array{path: string, bytes: int, on_disk: bool, missing_since: ?int, recording_ids: list<int>}> The files still in the library.
     */
    private function reconcileMissingFiles(DvrSetting $setting, array $files): array
    {
        $anyPresent = array_any($files, fn (array $file): bool => $file['on_disk']);
        $graceCutoff = now()->subDays(self::MISSING_FILE_GRACE_DAYS)->getTimestamp();
        $library = [];
        $newlyMissing = [];
        $returned = [];
        $gone = [];

        foreach ($files as $file) {
            if ($file['on_disk']) {
                if ($file['missing_since'] !== null) {
                    array_push($returned, ...$file['recording_ids']);
                }
            } elseif ($file['missing_since'] === null) {
                array_push($newlyMissing, ...$file['recording_ids']);
            } elseif ($anyPresent && $file['missing_since'] <= $graceCutoff) {
                array_push($gone, ...$file['recording_ids']);

                continue;
            }

            $library[] = $file;
        }

        if ($returned !== []) {
            DvrRecording::whereIn('id', $returned)->update(['file_missing_since' => null]);
        }

        if ($newlyMissing !== []) {
            DvrRecording::whereIn('id', $newlyMissing)->update(['file_missing_since' => UtcDateTime::forQuery(now())]);
            $this->notifyFilesMissing($setting, count($newlyMissing));
        }

        if ($gone !== []) {
            $this->purgeRecordings($gone);
            Log::warning('DVR retention: purged '.count($gone).' recording(s) whose files stayed missing for '.self::MISSING_FILE_GRACE_DAYS." days [setting {$setting->id}]", [
                'recording_ids' => $gone,
            ]);
        }

        return $library;
    }

    private function notifyFilesMissing(DvrSetting $setting, int $count): void
    {
        if (! $user = $setting->user) {
            return;
        }

        Notification::make()
            ->warning()
            ->title(__('DVR recordings missing from storage'))
            ->body(__('Files for :count recordings are missing from DVR storage. If storage is unavailable, the disk quota may be paused until it returns. Recordings removed on purpose leave the library after :days days.', [
                'count' => $count,
                'days' => self::MISSING_FILE_GRACE_DAYS,
            ]))
            ->broadcast($user)
            ->sendToDatabase($user);
    }

    /**
     * A setting's library files, oldest content first, with every recording on each.
     *
     * Recordings post-processed before output paths were unique can share one file
     * (same title, same day); summing file_size_bytes per row counted that file once
     * per row. Each file is listed once with its size on disk. Where the size can't be
     * read, the largest size any of its rows recorded is used (a newer row may have a
     * null or smaller figure; the file can't be smaller than its largest recording).
     *
     * @return list<array{path: string, bytes: int, on_disk: bool, missing_since: ?int, recording_ids: list<int>}>
     */
    private function libraryFiles(DvrSetting $setting): array
    {
        $storage = Storage::disk($setting->storage_disk ?: config('dvr.storage_disk'));
        $files = [];

        $recordings = DvrRecording::where('dvr_setting_id', $setting->id)
            ->where('status', DvrRecordingStatus::Completed->value)
            ->whereNotNull('file_path')
            ->orderBy('id')
            ->get(['id', 'file_path', 'file_size_bytes', 'actual_end', 'file_missing_since']);

        foreach ($recordings as $recording) {
            $file = &$files[$recording->file_path];
            $file ??= ['path' => $recording->file_path, 'recorded' => 0, 'newest_end' => 0, 'missing_since' => null, 'recording_ids' => []];
            $file['recorded'] = max($file['recorded'], (int) $recording->file_size_bytes);
            // A recording with no end time has an unknown age: don't evict it first.
            $file['newest_end'] = max($file['newest_end'], $recording->actual_end?->getTimestamp() ?? PHP_INT_MAX);
            if ($recording->file_missing_since) {
                $file['missing_since'] = min($file['missing_since'] ?? PHP_INT_MAX, $recording->file_missing_since->getTimestamp());
            }
            $file['recording_ids'][] = $recording->id;
            unset($file);
        }

        $files = array_values($files);
        usort($files, fn (array $a, array $b): int => $a['newest_end'] <=> $b['newest_end']);

        return array_map(function (array $f) use ($storage): array {
            $onDisk = $storage->exists($f['path']);
            $bytes = $f['recorded'];
            if ($onDisk) {
                try {
                    $bytes = (int) $storage->size($f['path']);
                } catch (\Throwable) {
                    // keep the recorded size
                }
            }

            return ['path' => $f['path'], 'bytes' => $bytes, 'on_disk' => $onDisk, 'missing_since' => $f['missing_since'], 'recording_ids' => $f['recording_ids']];
        }, $files);
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
     *
     * @param  list<int>  $recordingIds  Every row to purge with the file, when removing a whole file; defaults to this recording.
     */
    private function deleteRecordingFile(DvrRecording $recording, DvrSetting $setting, array $recordingIds = []): bool
    {
        if (empty($recording->file_path)) {
            return true;
        }

        $storageDisk = $setting->storage_disk ?: config('dvr.storage_disk');

        try {
            // A legacy same-day collision can leave two rows on one file: purge this row
            // but keep the file while another recording still points at it.
            if ($recording->fileIsSharedWithOtherRecordings($recordingIds)) {
                Log::info("DVR retention: keeping {$recording->file_path}, another recording still uses it", [
                    'recording_id' => $recording->id,
                ]);
            } elseif (Storage::disk($storageDisk)->exists($recording->file_path)) {
                Storage::disk($storageDisk)->delete($recording->file_path);

                // The dvr disk has 'throw' => false, so a failed delete only returns false.
                // Never purge rows pointing at a file that is still there: the recordings
                // would lose their reference while the file keeps using the space.
                if (Storage::disk($storageDisk)->exists($recording->file_path)) {
                    Log::warning("DVR retention: could not delete {$recording->file_path}; keeping recording {$recording->id}");

                    return false;
                }
            }

            $this->purgeRecordings($recordingIds ?: [$recording->id]);

            return true;
        } catch (\Exception $e) {
            Log::warning("DVR retention: failed to delete file {$recording->file_path}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * @param  list<int>  $recordingIds
     */
    private function purgeRecordings(array $recordingIds): void
    {
        DvrRecording::whereIn('id', $recordingIds)->update([
            'status' => DvrRecordingStatus::Purged->value,
            'file_path' => null,
            'file_missing_since' => null,
        ]);
    }
}
