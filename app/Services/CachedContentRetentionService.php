<?php

namespace App\Services;

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentManagedBy;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\Playlist;
use App\Settings\GeneralSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
 *
 * Dynamic-group retention is separate and explicit per rule: files a group
 * queued (`managed_by = dynamic_group`) are released once they leave the
 * group's cache scope — including when the item gains an eligible
 * media-server match (local media always wins) — honoring the retention
 * snapshot stored on the provenance pivot at attach time, regardless of
 * the playlist's cache_retention_mode, which only governs the "source row
 * missing" sweep above.
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

    /**
     * Stamp `dropped_at` on this group's provenance rows whose file's
     * cacheable is no longer in the group's cache scope: not a member of
     * `dynamic_group_items`, or outside the top-N item ids. Set-based —
     * one UPDATE per morph type, never per-row PHP loops over files.
     *
     * When the rule's cache settings are null (caching turned off, or the
     * rule disabled/removed), every undropped row of the group is stamped.
     */
    public function markDroppedForGroup(DynamicGroup $group): void
    {
        $settings = DynamicGroup::cacheSettings($group->ruleFromConfig());

        if ($settings === null) {
            DB::table('cached_content_file_dynamic_groups')
                ->where('dynamic_group_id', $group->id)
                ->whereNull('dropped_at')
                ->update(['dropped_at' => now()]);

            return;
        }

        // In-scope member ids in TMDB-rank order, capped by max_items.
        // Resolved in PHP (the set is small: <= pages x 20), then applied
        // as one whereNotIn update per morph type. The pluck column must
        // be table-qualified — the pivot join also has an `id`.
        $members = $group->type === 'series' ? $group->series() : $group->channels();
        $members->orderByPivot('position');
        if ($settings['max_items'] !== null) {
            $members->limit((int) $settings['max_items']);
        }
        $scopeItemIds = $members->pluck($members->getRelated()->getTable().'.id')->all() ?: [0];

        $now = now();

        // VOD files: dropped when their channel left the in-scope set.
        DB::table('cached_content_file_dynamic_groups')
            ->where('dynamic_group_id', $group->id)
            ->whereNull('dropped_at')
            ->whereExists(function (QueryBuilder $sub) use ($scopeItemIds): void {
                $sub->selectRaw('1')
                    ->from('cached_content_files')
                    ->whereColumn('cached_content_files.id', 'cached_content_file_dynamic_groups.cached_content_file_id')
                    ->where('cached_content_files.cacheable_type', (new Channel)->getMorphClass())
                    ->whereNotIn('cached_content_files.cacheable_id', $scopeItemIds);
            })
            ->update(['dropped_at' => $now]);

        // Episode files: dropped when their series left the in-scope set
        // (episodes have no membership of their own; they belong through
        // their series).
        DB::table('cached_content_file_dynamic_groups')
            ->where('dynamic_group_id', $group->id)
            ->whereNull('dropped_at')
            ->whereExists(function (QueryBuilder $sub) use ($scopeItemIds): void {
                $sub->selectRaw('1')
                    ->from('cached_content_files')
                    ->whereColumn('cached_content_files.id', 'cached_content_file_dynamic_groups.cached_content_file_id')
                    ->where('cached_content_files.cacheable_type', (new Episode)->getMorphClass())
                    ->whereNotIn('cached_content_files.cacheable_id', function (QueryBuilder $episodes) use ($scopeItemIds): void {
                        $episodes->select('id')
                            ->from('episodes')
                            ->whereIn('series_id', $scopeItemIds);
                    });
            })
            ->update(['dropped_at' => $now]);

        if ($group->playlist?->prefer_media_server_sources) {
            $this->markMediaMatchedDropped($group, $now);
        }
    }

    /**
     * Stamp dropped_at on undropped pivot rows whose file's cacheable now
     * has an ELIGIBLE media-server match — local media always wins, so the
     * redundant group-cached copy is released under its retention snapshot,
     * exactly as if the item had left the group. The item itself stays in
     * the dynamic group and plays from the media server.
     *
     * This is the set-based mirror of
     * MediaSourcePreferenceService::eligibleMediaItem(): media item
     * enabled, integration enabled, integration type supported. Keep the
     * two in sync. Like hasEligibleMatch(), there is NO reachability
     * check — a briefly-down media server must not release (and later
     * re-download) everything.
     */
    private function markMediaMatchedDropped(DynamicGroup $group, Carbon $now): void
    {
        // VOD files with a matched, eligible media channel.
        DB::table('cached_content_file_dynamic_groups')
            ->where('dynamic_group_id', $group->id)
            ->whereNull('dropped_at')
            ->whereExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('cached_content_files')
                    ->whereColumn('cached_content_files.id', 'cached_content_file_dynamic_groups.cached_content_file_id')
                    ->where('cached_content_files.cacheable_type', (new Channel)->getMorphClass())
                    ->whereExists(function (QueryBuilder $match): void {
                        $match->selectRaw('1')
                            ->from('media_source_matches as msm')
                            ->join('channels as media', 'media.id', '=', 'msm.media_channel_id')
                            ->join('media_server_integrations as integration', 'integration.id', '=', 'msm.media_server_integration_id')
                            ->whereColumn('msm.channel_id', 'cached_content_files.cacheable_id')
                            ->where('media.enabled', true)
                            ->where('integration.enabled', true)
                            ->whereIn('integration.type', MediaSourceMatchService::SUPPORTED_INTEGRATION_TYPES);
                    });
            })
            ->update(['dropped_at' => $now]);

        // Episode files with a matched, eligible media episode.
        DB::table('cached_content_file_dynamic_groups')
            ->where('dynamic_group_id', $group->id)
            ->whereNull('dropped_at')
            ->whereExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('cached_content_files')
                    ->whereColumn('cached_content_files.id', 'cached_content_file_dynamic_groups.cached_content_file_id')
                    ->where('cached_content_files.cacheable_type', (new Episode)->getMorphClass())
                    ->whereExists(function (QueryBuilder $match): void {
                        $match->selectRaw('1')
                            ->from('media_source_matches as msm')
                            ->join('episodes as media', 'media.id', '=', 'msm.media_episode_id')
                            ->join('media_server_integrations as integration', 'integration.id', '=', 'msm.media_server_integration_id')
                            ->whereColumn('msm.episode_id', 'cached_content_files.cacheable_id')
                            ->where('media.enabled', true)
                            ->where('integration.enabled', true)
                            ->whereIn('integration.type', MediaSourceMatchService::SUPPORTED_INTEGRATION_TYPES);
                    });
            })
            ->update(['dropped_at' => $now]);
    }

    /**
     * Run dynamic-group retention and release the files it evicts. Called
     * by the daily cache:cleanup command before the "source row missing"
     * sweep.
     *
     * Order:
     *  1. markDroppedForGroup() on every group that has provenance rows,
     *     catching rules edited/disabled since the last materialize.
     *  2. Stamp NULL-group rows (their group was deleted by
     *     SyncDynamicGroups' stale cleanup) so the grace period starts now.
     *     Skipped while TMDB is unconfigured — an expired key deletes every
     *     group, which must not start a countdown on everything.
     *  3. Delete provenance rows whose SNAPSHOT retention (the pivot's
     *     values, not the live rule) says release. NULL-group rows are
     *     never released while TMDB is unconfigured, and in_group NULL-group
     *     rows get a minimum 24h grace (see deleteReleasedPivotRows).
     *  4. Delete managed files with no remaining provenance row (their
     *     last group released them).
     *
     * @return int Number of cached files deleted.
     */
    public function releaseDynamicGroupCaches(): int
    {
        $tmdbConfigured = app(TmdbService::class)->isConfigured();

        DynamicGroup::whereHas('cachedContentFiles')
            ->cursor()
            ->each(fn (DynamicGroup $group) => $this->markDroppedForGroup($group));

        if ($tmdbConfigured) {
            DB::table('cached_content_file_dynamic_groups')
                ->whereNull('dynamic_group_id')
                ->whereNull('dropped_at')
                ->update(['dropped_at' => now()]);
        }

        $this->deleteReleasedPivotRows($tmdbConfigured);

        $ids = CachedContentFile::query()
            ->where('managed_by', CachedContentManagedBy::DynamicGroup->value)
            ->whereIn('status', [
                CachedContentFileStatus::Completed->value,
                CachedContentFileStatus::Failed->value,
            ])
            // No remaining PIVOT row. Deliberately not whereDoesntHave():
            // the BelongsToMany relation joins dynamic_groups, which would
            // make NULL-group pivot rows (group deleted, grace period
            // still running) invisible and release their files early.
            ->whereNotExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('cached_content_file_dynamic_groups')
                    ->whereColumn('cached_content_file_dynamic_groups.cached_content_file_id', 'cached_content_files.id');
            })
            ->select('id')
            ->toBase()
            ->cursor()
            ->map(fn (object $row): int => (int) $row->id)
            ->collect();

        return $this->deleteIds($ids);
    }

    /**
     * Delete provenance rows whose snapshot retention says release:
     * `in_group` once dropped, `in_group_plus_days` N days after dropping
     * (N floored at 1), `never_expire` never.
     *
     * NULL-group rows (their group was deleted) get a minimum 24h grace
     * before an `in_group` release, whatever the stamp says: if TMDB's key
     * expires, every group is deleted and the rows go NULL unstamped; when
     * the key is restored the 03:00 sweep stamps them and would delete
     * every `in_group` file in the same pass — hours before the 04:15
     * refresh re-creates the groups and re-adopts the files. The floor
     * costs nothing in normal use and closes that delete-then-redownload
     * gap. The per-row retention_days cutoff is resolved in PHP (portable
     * across Postgres/SQLite) over the bounded candidate set.
     */
    private function deleteReleasedPivotRows(bool $tmdbConfigured): void
    {
        DB::table('cached_content_file_dynamic_groups')
            ->where('retention', 'in_group')
            ->whereNotNull('dropped_at')
            ->where(function (QueryBuilder $q): void {
                $q->whereNotNull('dynamic_group_id')
                    ->orWhere('dropped_at', '<', now()->subDay());
            })
            ->when(! $tmdbConfigured, fn (QueryBuilder $q) => $q->whereNotNull('dynamic_group_id'))
            ->delete();

        $candidates = DB::table('cached_content_file_dynamic_groups')
            ->where('retention', 'in_group_plus_days')
            ->whereNotNull('dropped_at')
            ->when(! $tmdbConfigured, fn (QueryBuilder $q) => $q->whereNotNull('dynamic_group_id'))
            ->select(['id', 'retention_days', 'dropped_at'])
            ->cursor();

        $expiredIds = $candidates
            ->filter(fn (object $row): bool => Carbon::parse($row->dropped_at)->lt(
                now()->subDays(max(1, (int) ($row->retention_days ?? 1))),
            ))
            ->pluck('id')
            ->all();

        if ($expiredIds !== []) {
            DB::table('cached_content_file_dynamic_groups')->whereIn('id', $expiredIds)->delete();
        }
    }
}
