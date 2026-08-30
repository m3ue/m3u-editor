<?php

namespace App\Jobs;

use App\Enums\SyncRunPhase;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\Series;
use App\Services\SyncPipelineService;
use App\Services\TmdbService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recompute a playlist's TMDB-derived DynamicGroup rows and their membership.
 *
 * Designed to be called both from the SyncPipeline (after TMDB IDs are
 * populated, before the pipeline finalizes) and from a daily cron
 * (`app:refresh-dynamic-groups`) so the lists track TMDB's trending/popular
 * changes independent of any playlist sync.
 *
 * Membership is full-sync: stale rows are deleted and the full current set
 * is rewritten in chunks. This is intentionally simpler than a delta — TMDB
 * list endpoints return small fixed-size pages, the membership set is
 * bounded by what's already in the playlist, and full-sync semantics avoid
 * the "member disabled, member row survives" drift that delta would need to
 * reason about.
 */
class SyncDynamicGroups implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900;

    /**
     * Hard ceiling on the per-rule page count. The TmdbService caches each
     * page individually so this is mostly a safety bound on TMDB API calls,
     * not on member rows.
     */
    private const MAX_PAGES_PER_RULE = 5;

    /**
     * Pivot-row insert chunk size (matches AutoSyncGroupsToCustomPlaylist).
     */
    private const MEMBERSHIP_CHUNK_SIZE = 1000;

    public function __construct(
        public int $playlistId,
        public ?int $syncRunId = null,
        public ?SyncRunPhase $completionPhase = null,
    ) {}

    /**
     * Execute the job.
     *
     * Always completes the pipeline phase (when scheduled via the pipeline)
     * — even on early returns (unconfigured TMDB, no rules) and on
     * exceptions — so the SyncRun timeline can advance past DynamicGroups.
     */
    public function handle(): void
    {
        try {
            $this->runSync();
        } catch (Throwable $e) {
            Log::error('SyncDynamicGroups: unhandled error', [
                'playlist_id' => $this->playlistId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($this->syncRunId !== null && $this->completionPhase !== null) {
                app(SyncPipelineService::class)->completePhase(
                    $this->syncRunId,
                    $this->completionPhase,
                );
            }
        }
    }

    /**
     * Core sync logic, isolated from the phase-complete guarantee so the
     * `finally` block above remains simple.
     */
    private function runSync(): void
    {
        $playlist = Playlist::find($this->playlistId);
        if (! $playlist) {
            Log::warning("SyncDynamicGroups: playlist {$this->playlistId} not found");

            return;
        }

        $rules = collect($playlist->dynamic_groups_config ?? [])
            ->filter(fn (array $rule): bool => (bool) ($rule['enabled'] ?? false))
            ->values();

        $tmdb = app(TmdbService::class);

        // Collect (type, source, name) triples for whatever enabled rules we
        // successfully process this run. Used by the cleanup pass below to
        // remove stale rows whose rule no longer exists.
        $validKeys = [];

        if ($tmdb->isConfigured() && $rules->isNotEmpty()) {
            foreach ($rules as $index => $rule) {
                $type = (string) ($rule['type'] ?? '');
                $source = (string) ($rule['source'] ?? '');
                $name = trim((string) ($rule['name'] ?? ''));
                $params = (array) ($rule['tmdb_params'] ?? []);

                if (! in_array($type, ['vod', 'series'], true) || $source === '' || $name === '') {
                    continue;
                }

                $tmdbIds = $this->collectTmdbIds($tmdb, $type, $source, $params);
                $triple = $type.':'.$source.':'.$name;
                $existingForTriple = DynamicGroup::where('playlist_id', $playlist->id)
                    ->where('type', $type)
                    ->where('source', $source)
                    ->where('name', $name)
                    ->first();

                if ($tmdbIds === []) {
                    // TMDB returned no ids for this rule. TmdbService returns
                    // [] on any error (timeout, non-2xx, rate-limit — see its
                    // catch blocks), so this is also the transient-failure
                    // path. If we already have a DynamicGroup row for this
                    // triple, treat the run as a no-op for it: keep the row
                    // and its membership intact so the Xtream category id
                    // (offset + id) stays stable. Only skip the create when
                    // there's no row yet.
                    if ($existingForTriple !== null) {
                        $validKeys[] = $triple;
                    }

                    continue;
                }

                $group = DynamicGroup::updateOrCreate(
                    [
                        'playlist_id' => $playlist->id,
                        'type' => $type,
                        'source' => $source,
                        'name' => $name,
                    ],
                    [
                        'user_id' => $playlist->user_id,
                        'tmdb_params' => $params,
                        'sort_order' => $index,
                        'enabled' => true,
                        'last_synced_at' => now(),
                    ],
                );

                $this->syncMembership($group, $type, $playlist->id, $tmdbIds);

                $validKeys[] = $triple;
            }
        }

        // Always run the cleanup pass — even when there are no enabled rules
        // this is the path that drops stale rows for removed/renamed rules.
        // Diff is done in PHP (per-playlist row count is tiny) rather than
        // via a dialect-specific string concat — the previous `||` form
        // worked on Postgres/SQLite but not MySQL, and a name containing `:`,
        // however unlikely, would have made the lookup ambiguous.
        $existing = DynamicGroup::where('playlist_id', $playlist->id)
            ->get(['id', 'type', 'source', 'name']);

        $staleIds = $existing
            ->filter(fn (DynamicGroup $dg): bool => ! in_array(
                $dg->type.':'.$dg->source.':'.$dg->name,
                $validKeys,
                true,
            ))
            ->map(fn (DynamicGroup $dg): int => (int) $dg->id)
            ->all();

        if ($staleIds !== []) {
            DynamicGroup::whereIn('id', $staleIds)->delete();
        }
    }

    /**
     * Resolve the TMDB id list for a single rule across all requested pages.
     *
     * Returns an array of string ids matching the column type
     * (`channels.tmdb_id` / `series.tmdb_id` are varchar per the 2025-06-18
     * migration, so we stringify before the DB-side whereIn).
     *
     * @return array<int, string>
     */
    private function collectTmdbIds(TmdbService $tmdb, string $type, string $source, array $params): array
    {
        $pages = (int) ($params['pages'] ?? 3);
        $pages = max(1, min(self::MAX_PAGES_PER_RULE, $pages));
        $results = [];

        switch ($source) {
            case 'trending':
                // Trending already returns the merged list — ignore pages.
                $mediaType = $type === 'series' ? 'tv' : 'movie';
                $window = (string) ($params['time_window'] ?? 'week');
                $results = $tmdb->getTrending($mediaType, $window);
                break;

            case 'popular':
                for ($p = 1; $p <= $pages; $p++) {
                    $page = $type === 'series'
                        ? $tmdb->getPopularTv($p)
                        : $tmdb->getPopularMovies($p);
                    $results = array_merge($results, $page);
                }
                break;

            case 'now_playing':
                if ($type !== 'vod') {
                    break;
                }
                for ($p = 1; $p <= $pages; $p++) {
                    $results = array_merge($results, $tmdb->getNowPlayingMovies($p));
                }
                break;

            case 'upcoming':
                if ($type !== 'vod') {
                    break;
                }
                for ($p = 1; $p <= $pages; $p++) {
                    $results = array_merge($results, $tmdb->getUpcomingMovies($p));
                }
                break;

            case 'top_genre':
                $genreId = (int) ($params['genre_id'] ?? 0);
                if ($genreId <= 0) {
                    break;
                }
                $discoverParams = [
                    'with_genres' => $genreId,
                    'sort_by' => 'vote_average.desc',
                    'vote_count.gte' => 200,
                ];
                $results = $this->collectDiscoverPages(
                    fn (int $p) => $type === 'series'
                        ? $tmdb->discoverTv($discoverParams + ['page' => $p])
                        : $tmdb->discoverMovies($discoverParams + ['page' => $p]),
                    $pages,
                );
                break;

            case 'tmdb_network':
                if ($type !== 'series') {
                    break;
                }
                $networkId = (int) ($params['network_id'] ?? 0);
                if ($networkId <= 0) {
                    break;
                }
                $results = $this->collectDiscoverPages(
                    fn (int $p) => $tmdb->discoverTv([
                        'with_networks' => $networkId,
                        'page' => $p,
                    ]),
                    $pages,
                );
                break;

            case 'provider':
                $providerId = (int) ($params['provider_id'] ?? 0);
                if ($providerId <= 0) {
                    break;
                }
                $region = strtoupper((string) ($params['region'] ?? 'US'));
                $discoverParams = [
                    'with_watch_providers' => $providerId,
                    'watch_region' => $region,
                ];
                $results = $this->collectDiscoverPages(
                    fn (int $p) => $type === 'series'
                        ? $tmdb->discoverTv($discoverParams + ['page' => $p])
                        : $tmdb->discoverMovies($discoverParams + ['page' => $p]),
                    $pages,
                );
                break;

            default:
                // Unknown source — skip silently. Validated at the form layer
                // but defensive coding here means a future source added to
                // the enum without updating this switch fails open (no rows).
                break;
        }

        return array_values(array_unique(array_map(
            fn (array $item): string => (string) ($item['tmdb_id'] ?? 0),
            $results,
        )));
    }

    /**
     * Drive the paged discover calls and stop when TMDB reports it has no
     * more pages.
     *
     * @param  callable(int): array{results: array<int, array<string, mixed>>, total_pages: int}  $discover
     * @return array<int, array<string, mixed>>
     */
    private function collectDiscoverPages(callable $discover, int $maxPages): array
    {
        $all = [];
        $totalPages = PHP_INT_MAX;

        for ($p = 1; $p <= $maxPages && $p <= $totalPages; $p++) {
            $page = $discover($p);
            $all = array_merge($all, $page['results'] ?? []);
            $totalPages = (int) ($page['total_pages'] ?? $p);
        }

        return $all;
    }

    /**
     * Replace the dynamic_group_items rows for a group with the current
     * matching item ids, in chunks. Set-based — never hydrates models.
     *
     * @param  array<int, string>  $tmdbIds
     */
    private function syncMembership(DynamicGroup $group, string $type, int $playlistId, array $tmdbIds): void
    {
        if ($type === 'vod') {
            $itemIds = Channel::query()
                ->where('playlist_id', $playlistId)
                ->where('is_vod', true)
                ->whereIn('tmdb_id', $tmdbIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $morphClass = Channel::class;
        } else {
            $itemIds = Series::query()
                ->where('playlist_id', $playlistId)
                ->whereIn('tmdb_id', $tmdbIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $morphClass = Series::class;
        }

        // Remove stale membership — anything not in the freshly-computed set.
        DB::table('dynamic_group_items')
            ->where('dynamic_group_id', $group->id)
            ->where('item_type', $morphClass)
            ->whereNotIn('item_id', $itemIds ?: [0])
            ->delete();

        // No `enabled` filter at write time — the Xtream read path filters
        // enabled on demand, so toggling an item's enabled flag does not
        // require touching this table.
        if ($itemIds === []) {
            return;
        }

        foreach (array_chunk($itemIds, self::MEMBERSHIP_CHUNK_SIZE) as $chunk) {
            DB::table('dynamic_group_items')->insertOrIgnore(
                array_map(fn (int $id): array => [
                    'dynamic_group_id' => $group->id,
                    'item_type' => $morphClass,
                    'item_id' => $id,
                ], $chunk),
            );
        }
    }
}
