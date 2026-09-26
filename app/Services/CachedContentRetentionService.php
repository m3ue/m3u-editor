<?php

namespace App\Services;

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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
}
