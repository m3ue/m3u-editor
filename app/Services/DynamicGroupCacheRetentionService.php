<?php

namespace App\Services;

use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * DynamicGroupCacheRetentionService — Enforces the per-rule retention mode on
 * cached_content_file rows via the cached_content_file_dynamic_groups pivot.
 *
 * Each CachedContentFile can be referenced by multiple DynamicGroup rows; the
 * "file is still wanted" question is "is it wanted by *any* referencing group?"
 *
 * Retention policies per the per-rule cache_retention_mode in dynamic_groups_config:
 *  - `never_expire`     → this group's reference pins the file forever. If any
 *                        referencing group says this, skip the whole file this pass.
 *  - `match_group_lifetime` → detach this group's pivot row the moment the
 *                        content is no longer in the group's live membership.
 *  - `lifetime_plus_days` → stamp dropped_at on the pivot row the moment the
 *                        content falls out of live membership; only actually
 *                        detach once dropped_at + cache_retention_extra_days has
 *                        passed.
 *
 * Hard deletion (file on disk + row + cascade-deletes pivot):
 *  Fires when a CachedContentFile ends up with zero referencing groups AND
 *  no never_expire rule blocked this pass.
 */
class DynamicGroupCacheRetentionService
{
    /**
     * Run retention enforcement across all CachedContentFile rows.
     *
     * cursor() over the files — never ->all()/->get() (pr-review-standards §1).
     */
    public function runAll(): void
    {
        CachedContentFile::query()
            ->cursor()
            ->each(function (CachedContentFile $file): void {
                try {
                    $this->evaluate($file);
                } catch (\Throwable $e) {
                    Log::error("DynamicGroupCacheRetention: failed for file {$file->id} (fp={$file->content_fingerprint}): {$e->getMessage()}");
                }
            });
    }

    /**
     * Evaluate a single file. May detach pivot rows and/or delete the file/row.
     */
    public function evaluate(CachedContentFile $file): void
    {
        $groups = $file->dynamicGroups()->with('playlist')->get();

        // Track whether any referencing group pins the file forever.
        $hasNeverExpireGroup = false;

        foreach ($groups as $group) {
            $rule = $this->findRuleForGroup($group);
            $mode = $rule['cache_retention_mode'] ?? 'match_group_lifetime';
            $extraDays = (int) ($rule['cache_retention_extra_days'] ?? 0);

            // Build the set of content identities currently in the group's live membership.
            // Match the cached fingerprint shape.
            $liveFingerprints = $this->liveFingerprintsForGroup($group, $rule);

            // Did the pivot's content fall out of this group's live membership?
            // We compare the pivot row's "claimed fingerprint" against the live set.
            // Since the pivot just links a file_id to a group_id, the relevant
            // question is: is THIS specific file's fingerprint still in the live set?
            $pivotFingerprint = $file->content_fingerprint;

            $stillAlive = $liveFingerprints->contains($pivotFingerprint);

            if ($stillAlive) {
                // Content is still in the group's live membership — clear any dropped_at.
                $this->pivotTouch($file, $group);

                continue;
            }

            // Content is out of the group's live membership — apply the mode.
            switch ($mode) {
                case 'never_expire':
                    $hasNeverExpireGroup = true;
                    break;

                case 'match_group_lifetime':
                    // Detach immediately.
                    $file->dynamicGroups()->detach($group->id);
                    break;

                case 'lifetime_plus_days':
                    // Stamp dropped_at on first sight, only detach after grace period.
                    $this->markDroppedOrDetach($file, $group, $extraDays);
                    break;
            }
        }

        // If after the pass the file has no references AND nothing pinned it forever,
        // hard-delete the file on disk + the row.
        $remaining = $file->dynamicGroups()->count();
        if ($remaining === 0 && ! $hasNeverExpireGroup) {
            $this->hardDelete($file);
        }
    }

    /**
     * Find the dynamic_groups_config rule that produced this DynamicGroup.
     * Match by name (Phase 1's SyncDynamicGroups materializes by name).
     * Falls back to 'match_group_lifetime' if no matching rule is found.
     */
    private function findRuleForGroup(DynamicGroup $group): array
    {
        $config = $group->playlist?->dynamic_groups_config;
        if (! is_array($config)) {
            return [];
        }

        foreach ($config as $rule) {
            if (($rule['name'] ?? null) === $group->name) {
                return $rule;
            }
        }

        return [];
    }

    /**
     * Build the set of content fingerprints currently in this group's live membership.
     * Used to decide whether THIS pivot row's content is still wanted by the group.
     *
     * VOD (movies): iterate $group->channels (MorphToMany), produce the fingerprints
     *  matching the per-rule quality preference.
     * Series: iterate $group->series → each series' episodes, produce fingerprints.
     */
    private function liveFingerprintsForGroup(DynamicGroup $group, array $rule): Collection
    {
        $quality = $rule['cache_prefer_quality_keyword'] ?? null;
        $fingerprints = collect();

        if ($group->type === 'vod') {
            foreach ($group->channels as $channel) {
                $tmdbId = $channel->tmdb_id !== null ? (string) $channel->tmdb_id : null;
                $fingerprints->push(CachedContentFile::fingerprintFor([
                    'content_type' => 'movie',
                    'tmdb_id' => $tmdbId,
                    'quality' => $quality,
                ]));
            }
        } elseif ($group->type === 'series') {
            foreach ($group->series as $series) {
                $tmdbId = $series->tmdb_id !== null ? (string) $series->tmdb_id : null;
                foreach ($series->episodes as $episode) {
                    $fingerprints->push(CachedContentFile::fingerprintFor([
                        'content_type' => 'episode',
                        'tmdb_id' => $tmdbId,
                        'season_number' => $episode->season,
                        'episode_number' => $episode->episode_number,
                        'quality' => $quality,
                    ]));
                }
            }
        }

        return $fingerprints;
    }

    /**
     * Clear dropped_at for an alive pivot row (so the grace period restarts cleanly).
     */
    private function pivotTouch(CachedContentFile $file, DynamicGroup $group): void
    {
        // Use a raw DB touch on the pivot — avoids loading/saving the pivot row entirely.
        \DB::table('cached_content_file_dynamic_groups')
            ->where('cached_content_file_id', $file->id)
            ->where('dynamic_group_id', $group->id)
            ->update([
                'dropped_at' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Stamp dropped_at the first time we notice the content is out, only detach
     * once grace period (extra_days) has elapsed.
     */
    private function markDroppedOrDetach(CachedContentFile $file, DynamicGroup $group, int $extraDays): void
    {
        $cutoff = now()->subDays($extraDays);

        // Pull the existing dropped_at for this pivot row (if any).
        $existing = \DB::table('cached_content_file_dynamic_groups')
            ->where('cached_content_file_id', $file->id)
            ->where('dynamic_group_id', $group->id)
            ->first();

        if (! $existing) {
            return; // pivot row already gone
        }

        if ($existing->dropped_at === null) {
            // First sight — stamp dropped_at
            \DB::table('cached_content_file_dynamic_groups')
                ->where('cached_content_file_id', $file->id)
                ->where('dynamic_group_id', $group->id)
                ->update([
                    'dropped_at' => now(),
                    'updated_at' => now(),
                ]);

            return;
        }

        // Already stamped — check if grace period elapsed
        $droppedAt = Carbon::parse($existing->dropped_at);
        if ($droppedAt->lessThanOrEqualTo($cutoff)) {
            $file->dynamicGroups()->detach($group->id);
        }
    }

    /**
     * Hard-delete: remove file from disk + the row. Pivot cascade-deletes via FK.
     *
     * Cleanup is gated on $file->file_path being set rather than hasFilePath()
     * (which additionally requires status=Completed). Defense-in-depth: any
     * future code path that sets file_path on a Failed/Downloading row will
     * still trigger Storage cleanup here, instead of leaving the file behind.
     */
    private function hardDelete(CachedContentFile $file): void
    {
        if (! empty($file->file_path)) {
            try {
                Storage::disk($file->resolveStorageDisk())->delete($file->file_path);
            } catch (\Throwable $e) {
                Log::warning("DynamicGroupCacheRetention: failed to delete file {$file->file_path} for fp={$file->content_fingerprint}: {$e->getMessage()}");
            }
        }

        try {
            $file->delete();
        } catch (\Throwable $e) {
            Log::warning("DynamicGroupCacheRetention: failed to delete row {$file->id}: {$e->getMessage()}");
        }
    }

    /**
     * Walk the configured cache directory and return paths that are not
     * referenced by any cached_content_files row. Used by the
     * `app:cache-dynamic-group-content-cleanup-orphans` command to clean
     * up orphans that escaped the normal retention lifecycle (e.g. files
     * written by a Step 7-success / Step 8-failure race before that path
     * had try/catch protection, or files left over from manual Storage
     * edits).
     *
     * When $delete is true (default), the orphans are deleted from Storage.
     * Returns the array of orphan paths (deleted-or-listed, depending on $delete).
     */
    public function cleanupStorageOrphans(bool $delete = true): array
    {
        $disk = Storage::disk(config('filesystems.default'));
        $cacheDir = $this->cacheDirectory();

        // Flip for O(1) isset() lookup against potentially tens of thousands of paths.
        $referenced = CachedContentFile::query()
            ->whereNotNull('file_path')
            ->pluck('file_path')
            ->flip();

        $orphans = [];
        foreach ($disk->files($cacheDir) as $path) {
            if (! $referenced->has($path)) {
                $orphans[] = $path;
            }
        }

        if (! $delete || $orphans === []) {
            return $orphans;
        }

        $deleted = 0;
        foreach ($orphans as $path) {
            try {
                $disk->delete($path);
                $deleted++;
            } catch (\Throwable $e) {
                Log::warning("cleanupStorageOrphans: failed to delete {$path}: {$e->getMessage()}");
            }
        }

        Log::info("cleanupStorageOrphans: deleted {$deleted}/".count($orphans)." orphan files in {$cacheDir}");

        return $orphans;
    }

    /**
     * Cache directory under the configured default disk where DownloadCachedContentFile
     * writes its completed files. Centralized so the orphan scan and the job agree.
     */
    private function cacheDirectory(): string
    {
        return trim((string) config('dynamic_group_cache.path_prefix', 'cache'), '/');
    }
}
