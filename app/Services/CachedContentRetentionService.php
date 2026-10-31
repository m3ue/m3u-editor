<?php

namespace App\Services;

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentManagedBy;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\ManagedCacheInterest;
use App\Models\Playlist;
use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Automatic cleanup of cached content files.
 *
 * A cached file is stale once the channel or episode it was downloaded for
 * no longer exists (the item left its playlist on a later sync), or its
 * playlist was deleted. Channel and episode ids are stable across syncs
 * (both are upserted on their source keys), so a missing row really does
 * mean the content is gone.
 *
 * Only playlists whose effective retention mode is `automatic` are swept;
 * `never-expire` and `manual` playlists keep their files until a user
 * deletes them. Active downloads (Pending/Downloading) are never touched.
 */
class CachedContentRetentionService
{
    /**
     * Ids of every cached file that is no longer wanted.
     *
     * The whole check runs in SQL (NOT EXISTS against channels/episodes),
     * and only the matching ids are streamed back.
     *
     * @return Collection<int, int>
     */
    public function evaluate(): Collection
    {
        $automaticPlaylists = $this->automaticPlaylistIdsQuery();

        return CachedContentFile::query()
            ->whereIn('status', [
                CachedContentFileStatus::Completed->value,
                CachedContentFileStatus::Failed->value,
            ])
            ->where(function (Builder $q) use ($automaticPlaylists): void {
                $q->whereNull('playlist_id')
                    ->orWhereIn('playlist_id', $automaticPlaylists);
            })
            ->where(function (Builder $q): void {
                $q->whereNull('playlist_id')
                    ->orWhere(fn (Builder $movie) => $this->whereSourceMissing($movie, Channel::class, 'channels'))
                    ->orWhere(fn (Builder $episode) => $this->whereSourceMissing($episode, Episode::class, 'episodes'));
            })
            ->select('id')
            ->toBase()
            ->cursor()
            ->map(fn (object $row): int => (int) $row->id)
            ->collect();
    }

    /**
     * Playlist ids whose effective retention mode is `automatic`, as a
     * subquery (never loaded into PHP). Mirrors
     * `Playlist::effectiveCacheRetentionMode()`.
     */
    public function automaticPlaylistIdsQuery(): Builder
    {
        $raw = app(GeneralSettings::class)->refresh()->cache_retention_mode ?? null;
        $global = (is_string($raw) && $raw !== '') ? $raw : 'automatic';

        $query = Playlist::query()->select('id');

        if ($global === 'automatic') {
            return $query->where(function ($q): void {
                $q->whereNull('cache_retention_mode')
                    ->orWhere('cache_retention_mode', '')
                    ->orWhere('cache_retention_mode', 'automatic');
            });
        }

        return $query->where('cache_retention_mode', 'automatic');
    }

    /**
     * Delete the given cached files (row + file on disk). Re-checks status
     * so a row that was re-queued between evaluate() and now survives.
     *
     * @param  Collection<int, int>  $ids
     * @return int Number of rows deleted.
     */
    public function deleteIds(Collection $ids): int
    {
        if ($ids->isEmpty()) {
            return 0;
        }

        $deleted = 0;

        foreach ($ids->chunk(500) as $chunk) {
            CachedContentFile::query()
                ->whereIn('id', $chunk->all())
                ->where(function (Builder $query): void {
                    $query->whereNull('managed_by')
                        ->orWhere('managed_by', '!=', CachedContentManagedBy::ManagedPublishing->value);
                })
                ->whereNotIn('status', [
                    CachedContentFileStatus::Pending->value,
                    CachedContentFileStatus::Downloading->value,
                ])
                ->get()
                ->each(function (CachedContentFile $row) use (&$deleted): void {
                    $row->deleteStoredFile();
                    $row->delete();
                    $deleted++;
                });
        }

        if ($deleted > 0) {
            Log::info("CachedContentRetention: deleted {$deleted} cached files.");
        }

        return $deleted;
    }

    /**
     * Release watched managed-publishing cache files whose grace period has
     * expired, all interests are released, and no active reader/download/
     * playback lease protects them.
     */
    public function releaseManagedCacheFiles(): int
    {
        $ids = CachedContentFile::query()
            ->where('managed_by', CachedContentManagedBy::ManagedPublishing->value)
            ->where('source', 'provider')
            ->whereIn('status', [
                CachedContentFileStatus::Completed->value,
                CachedContentFileStatus::Failed->value,
            ])
            ->whereHas('managedCacheInterests', fn (Builder $q) => $q
                ->whereNotNull('watched_at')
                ->whereNotNull('release_after')
                ->where('release_after', '<=', now()))
            ->pluck('id');

        $deleted = 0;

        foreach ($ids as $id) {
            $file = CachedContentFile::query()->find($id);

            if ($file === null) {
                continue;
            }

            if ($this->deleteManagedFileIfReleasable($file)) {
                $deleted++;
            }
        }

        if ($deleted > 0) {
            Log::info("ReleaseWatchedManagedCache: deleted {$deleted} managed files.");
        }

        return $deleted;
    }

    /**
     * Atomically check and delete a managed-publishing cache file. Returns
     * true only when the row and storage were both removed. Storage failure
     * leaves the row and interests intact for retry.
     */
    public function deleteManagedFileIfReleasable(CachedContentFile $file): bool
    {
        return app(CachedContentActivityService::class)->withFileActivityLock($file, function () use ($file): bool {
            $file = $file->fresh();
            if ($file === null || $file->managed_by !== CachedContentManagedBy::ManagedPublishing || $file->source !== 'provider') {
                return false;
            }

            if ($file->playlist?->share_cache_across_playlists) {
                return false;
            }

            if (in_array($file->status, [
                CachedContentFileStatus::Pending,
                CachedContentFileStatus::Downloading,
            ], true)) {
                return false;
            }

            $hasUnreleasedInterest = ManagedCacheInterest::query()
                ->where('cached_content_file_id', $file->id)
                ->where(function (Builder $q): void {
                    $q->whereNull('watched_at')
                        ->orWhereNull('release_after')
                        ->orWhere('release_after', '>', now());
                })
                ->exists();

            if ($hasUnreleasedInterest || ! app(CachedContentActivityService::class)->isSafeToDelete($file)) {
                return false;
            }

            return $this->deleteManagedFileWithDatabaseClaim($file);
        });
    }

    /**
     * Hold the file, playlist and interest rows through physical deletion.
     * Ordinary keep/sharing/undo updates must serialize with this claim, even
     * when their caller does not use the cache activity lock.
     */
    private function deleteManagedFileWithDatabaseClaim(CachedContentFile $file): bool
    {
        return DB::transaction(function () use ($file): bool {
            if (DB::connection()->getDriverName() === 'sqlite') {
                // SQLite has no FOR UPDATE. Reserve its writer before taking
                // the protection snapshot; this does not change metadata.
                DB::table('cached_content_files')->where('id', $file->id)
                    ->update(['updated_at' => DB::raw('updated_at')]);
            }

            $file = CachedContentFile::query()->lockForUpdate()->find($file->id);
            if ($file === null || $file->managed_by !== CachedContentManagedBy::ManagedPublishing || $file->source !== 'provider') {
                return false;
            }
            $playlist = Playlist::query()->lockForUpdate()->find($file->playlist_id);
            if ($playlist?->share_cache_across_playlists || in_array($file->status, [
                CachedContentFileStatus::Pending,
                CachedContentFileStatus::Downloading,
            ], true)) {
                return false;
            }

            $interests = ManagedCacheInterest::query()
                ->where('cached_content_file_id', $file->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($interests->contains(fn (ManagedCacheInterest $interest): bool => $interest->watched_at === null
                || $interest->release_after === null || $interest->release_after->isFuture())
                || ! app(CachedContentActivityService::class)->isSafeToDelete($file)) {
                return false;
            }

            return $this->deleteStoredFileAndRow($file);
        });
    }

    /**
     * Delete storage and row. Returns false when storage deletion fails so
     * the caller can retry later.
     */
    private function deleteStoredFileAndRow(CachedContentFile $file): bool
    {
        if (! empty($file->file_path)) {
            try {
                $deleted = Storage::disk($file->resolveStorageDisk())->delete($file->file_path);
                if ($deleted === false) {
                    return false;
                }
            } catch (\Throwable $e) {
                Log::warning('Managed cache file storage deletion failed', [
                    'file_id' => $file->id,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        }

        $file->delete();

        return true;
    }

    /**
     * Constrain to rows of `$morphClass` whose source row no longer exists.
     *
     * @param  class-string  $morphClass
     */
    private function whereSourceMissing(Builder $query, string $morphClass, string $table): Builder
    {
        return $query->where('cacheable_type', (new $morphClass)->getMorphClass())
            ->whereNotExists(function (QueryBuilder $sub) use ($table): void {
                $sub->selectRaw('1')
                    ->from($table)
                    ->whereColumn("{$table}.id", 'cached_content_files.cacheable_id');
            });
    }

    /**
     * Dynamic-group retention: mark files that left their group's cache
     * scope as dropped, unlink them once the rule's keep days have passed,
     * then delete group-managed files no group links any more. Runs
     * regardless of the playlist's cache_retention_mode, since auto-cache
     * is opted into per rule.
     *
     * @return int Number of cached files deleted.
     */
    public function releaseDynamicGroupCaches(): int
    {
        DynamicGroup::query()
            ->whereHas('cachedContentFiles')
            ->cursor()
            ->each(fn (DynamicGroup $group) => $this->releaseGroupLinks($group));

        $ids = CachedContentFile::query()
            ->where('managed_by', CachedContentManagedBy::DynamicGroup->value)
            ->whereIn('status', [
                CachedContentFileStatus::Completed->value,
                CachedContentFileStatus::Failed->value,
            ])
            ->whereDoesntHave('dynamicGroups')
            ->pluck('id');

        return $this->deleteIds($ids);
    }

    /**
     * A file is in scope while its channel, or its episode's series, is one
     * of the group's cache members and (for episodes) in the latest season.
     * A group whose rule is gone was removed or renamed and not re-synced
     * yet: its files are held a day so a renamed rule's group can re-link
     * them on the next refresh before the old group is deleted.
     */
    private function releaseGroupLinks(DynamicGroup $group): void
    {
        $settings = $group->cacheSettings();
        $links = fn (): QueryBuilder => DB::table('cached_content_file_dynamic_groups')
            ->where('dynamic_group_id', $group->id);

        if ($settings === null || ! $settings['enabled']) {
            $links()->whereNull('dropped_at')->update(['dropped_at' => now()]);
        } else {
            $members = $group->cacheMembers($settings['max_items']);
            $memberIds = $members->pluck($members->getRelated()->qualifyColumn('id'))->all();

            $inScope = $group->type === 'series'
                ? Episode::query()->inLatestSeason()->whereIn('episodes.series_id', $memberIds)->select('episodes.id')
                : $memberIds;

            $links()->whereNull('dropped_at')
                ->whereExists(function (QueryBuilder $file) use ($inScope): void {
                    $file->selectRaw('1')
                        ->from('cached_content_files')
                        ->whereColumn('cached_content_files.id', 'cached_content_file_dynamic_groups.cached_content_file_id')
                        ->whereNotIn('cached_content_files.cacheable_id', $inScope);
                })
                ->update(['dropped_at' => now()]);
        }

        $links()->where('dropped_at', '<=', now()->subDays($settings['keep_days'] ?? 1))->delete();
    }
}
