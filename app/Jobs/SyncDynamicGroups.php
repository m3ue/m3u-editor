<?php

namespace App\Jobs;

use App\Enums\SyncRunPhase;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\DynamicGroupItemSnapshot;
use App\Models\Playlist;
use App\Models\Series;
use App\Services\CachedContentDispatchService;
use App\Services\SyncPipelineService;
use App\Services\TmdbService;
use App\Support\DynamicGroupThemes;
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

        // TMDB-backed sources need a configured API key; theme sources match the
        // local library only and run even when TMDB is unset (see the per-rule skip).
        if ($rules->isNotEmpty()) {
            foreach ($rules as $index => $rule) {
                ['type' => $type, 'source' => $source, 'name' => $name] = DynamicGroup::ruleIdentity($rule);
                $params = (array) ($rule['tmdb_params'] ?? []);

                if (! in_array($type, ['vod', 'series'], true) || $source === '' || $name === '') {
                    continue;
                }

                // TMDB-backed rule with no API key: skipped, so - as before theme
                // sources existed - the cleanup pass below drops its row.
                if ($source !== 'theme' && ! $tmdb->isConfigured()) {
                    continue;
                }

                $triple = $type.':'.$source.':'.$name;
                $group = $this->materializeRule($playlist, $type, $source, $name, $params, $index, $tmdb);

                if ($group !== null) {
                    $validKeys[] = $triple;
                }
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

        // Must stay a query-builder delete: DynamicGroup's `deleted` model
        // hook strips the matching rule from dynamic_groups_config, which is
        // only correct for user-initiated deletes. Firing it here would wipe
        // disabled rules, or every rule when TMDB is unconfigured.
        if ($staleIds !== []) {
            DynamicGroup::whereIn('id', $staleIds)->delete();
        }
    }

    /**
     * Materialize one Dynamic Group rule into a DynamicGroup row + its
     * membership. Public so the CreateDynamicGroup header action on the
     * VOD / Series Dynamic Groups listing pages can call it synchronously
     * after appending a rule to a playlist's `dynamic_groups_config`.
     *
     * Returns:
     *  - null when the rule is invalid (no usable type/source/name), or when
     *    the source is TMDB-backed and TMDB returned no ids AND no
     *    pre-existing DynamicGroup row for this (playlist, type, source,
     *    name) tuple. The latter matches the existing batch-job behavior: a
     *    TMDB rule with empty results is a no-op for new rules (the next sync
     *    will retry once TMDB is healthy) but is also a no-op for existing
     *    rules (keeps the Xtream category id stable). Theme sources never
     *    return early on empty results — empty membership is a real signal,
     *    so the row is created/updated and `syncMembership()` runs with []
     *    to clear any prior membership.
     *  - the DynamicGroup row otherwise. `last_synced_at` is set, sort_order
     *    is recorded, and membership is rewritten from the current snapshot.
     *    `enabled` reflects the seasonal window for theme sources (true when
     *    inside, false when outside) and is forced true otherwise.
     */
    public function materializeRule(
        Playlist $playlist,
        string $type,
        string $source,
        string $name,
        array $params,
        int $sortOrder,
        TmdbService $tmdb,
    ): ?DynamicGroup {
        if (! in_array($type, ['vod', 'series'], true) || $source === '' || $name === '') {
            return null;
        }

        if ($source === 'theme') {
            return $this->materializeThemeRule($playlist, $type, $name, $params, $sortOrder);
        }

        $tmdbIds = $this->collectTmdbIds($tmdb, $type, $source, $params);
        $existingForTriple = DynamicGroup::where('playlist_id', $playlist->id)
            ->where('type', $type)
            ->where('source', $source)
            ->where('name', $name)
            ->first();

        if ($tmdbIds === []) {
            // TMDB returned no ids for this rule. TmdbService returns
            // [] on any error (timeout, non-2xx, rate-limit - see its
            // catch blocks), so this is also the transient-failure
            // path. If we already have a DynamicGroup row for this
            // triple, treat the run as a no-op for it: keep the row
            // and its membership intact so the Xtream category id
            // (offset + id) stays stable. Only skip the create when
            // there's no row yet.
            return $existingForTriple;
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
                'sort_order' => $sortOrder,
                'enabled' => true,
                'last_synced_at' => now(),
            ],
        );

        // TMDB returns ids best-first; keep each id's first rank so members
        // list in TMDB order (trending rank, popularity, ...).
        $rankByTmdbId = [];
        foreach ($tmdbIds as $rank => $tmdbId) {
            $rankByTmdbId[$tmdbId] ??= $rank;
        }

        $tmdbIdByItemId = DynamicGroup::itemsMatchingTmdbIds($type, $playlist->id, $tmdbIds)
            ->pluck('tmdb_id', 'id')
            ->all();
        $itemIds = array_map('intval', array_keys($tmdbIdByItemId));

        $positionByItemId = [];
        foreach ($itemIds as $itemId) {
            $positionByItemId[$itemId] = $rankByTmdbId[(string) $tmdbIdByItemId[$itemId]] ?? 0;
        }

        $this->syncMembership($group, $type, $itemIds, $this->syncRunId, $positionByItemId);

        $this->queueCacheDownloads($group, $playlist);

        return $group;
    }

    /**
     * Materialize a single theme rule. Membership is computed locally via
     * DynamicGroup::itemsMatchingTheme() and the seasonal window decides
     * whether the row's `enabled` flag flips on or off; the row itself
     * (and therefore its Xtream category id) survives either way.
     *
     * @param  array<string, mixed>  $params
     */
    private function materializeThemeRule(
        Playlist $playlist,
        string $type,
        string $name,
        array $params,
        int $sortOrder,
    ): ?DynamicGroup {
        $lists = DynamicGroupThemes::resolveLists($params);
        $from = $params['active_from'] ?? null;
        $until = $params['active_until'] ?? null;
        $inSeason = DynamicGroupThemes::isWithinWindow(
            is_string($from) ? $from : null,
            is_string($until) ? $until : null,
            now(),
        );

        // Out of season: dynamicCategories() already hides a disabled row, but
        // applyDynamicGroupFilter() doesn't check `enabled`, so membership is
        // cleared too - a client holding the cached category id gets nothing.
        $itemIds = $inSeason
            ? DynamicGroup::itemsMatchingTheme($type, $playlist->id, $lists['keywords'], $lists['terms'])
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all()
            : [];

        $group = DynamicGroup::updateOrCreate(
            [
                'playlist_id' => $playlist->id,
                'type' => $type,
                'source' => 'theme',
                'name' => $name,
            ],
            [
                'user_id' => $playlist->user_id,
                'tmdb_params' => $params,
                'sort_order' => $sortOrder,
                'enabled' => $inSeason,
                'last_synced_at' => now(),
            ],
        );

        // An empty local match is a real result (unlike an empty TMDB response),
        // so membership is always rewritten, clearing members that no longer match.
        $this->syncMembership($group, $type, $itemIds, $this->syncRunId);

        $this->queueCacheDownloads($group, $playlist);

        return $group;
    }

    /**
     * Rules that cache their members queue the downloads in their own job so
     * a large series group can't hold up this pipeline phase.
     */
    private function queueCacheDownloads(DynamicGroup $group, Playlist $playlist): void
    {
        $group->setRelation('playlist', $playlist);
        if (($group->cacheSettings()['enabled'] ?? false) && app(CachedContentDispatchService::class)->isEnabled()) {
            dispatch(new QueueDynamicGroupCacheDownloads($group->id));
        }
    }

    /**
     * Resolve the TMDB id list for a single rule.
     *
     * Returns an array of string ids matching the column type
     * (`channels.tmdb_id` / `series.tmdb_id` are varchar per the 2025-06-18
     * migration, so we stringify before the DB-side whereIn).
     *
     * @return array<int, string>
     */
    private function collectTmdbIds(TmdbService $tmdb, string $type, string $source, array $params): array
    {
        return array_map(
            fn (array $item): string => (string) ($item['tmdb_id'] ?? 0),
            $tmdb->collectDynamicGroupResults($type, $source, $params),
        );
    }

    /**
     * Replace the dynamic_group_items rows for a group with the current
     * matching item ids, in chunks. Set-based — never hydrates models.
     *
     * When `$syncRunId` is set, also append the new membership to
     * `dynamic_group_item_snapshots` so the View page can render a
     * "what changed since last sync" diff. Cron runs (syncRunId = null)
     * skip capture — diff display is only meaningful for pipeline-attributable
     * runs, and the cron path runs multiple times per day so its snapshots
     * would dominate storage with low signal.
     *
     * @param  array<int, int>  $itemIds
     * @param  array<int, int>  $positionByItemId  TMDB-rank position per item id; items
     *                                             without a rank (theme rules) default to 0.
     */
    private function syncMembership(DynamicGroup $group, string $type, array $itemIds, ?int $syncRunId = null, array $positionByItemId = []): void
    {
        $morphClass = $type === 'vod' ? Channel::class : Series::class;

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
            // Empty membership is still worth snapshotting if a prior run had
            // rows — the diff view will then show everything as removed. Skip
            // when there's no run to attribute to (cron) or when the snapshot
            // would be empty regardless (first run, no prior membership).
            if ($syncRunId === null) {
                return;
            }
            $this->writeSnapshot($group->id, $morphClass, [], $syncRunId);

            return;
        }

        // Upsert (not insertOrIgnore) so surviving members pick up their new rank.
        foreach (array_chunk($itemIds, self::MEMBERSHIP_CHUNK_SIZE) as $chunk) {
            DB::table('dynamic_group_items')->upsert(
                array_map(fn (int $id): array => [
                    'dynamic_group_id' => $group->id,
                    'item_type' => $morphClass,
                    'item_id' => $id,
                    'position' => $positionByItemId[$id] ?? 0,
                ], $chunk),
                ['dynamic_group_id', 'item_type', 'item_id'],
                ['position'],
            );
        }

        if ($syncRunId !== null) {
            $this->writeSnapshot($group->id, $morphClass, $itemIds, $syncRunId);
        }
    }

    /**
     * Append the freshly-computed membership to the snapshot table in chunks.
     * Set-based — never hydrates models. The table is narrow and indexed on
     * `(dynamic_group_id, sync_run_id)` so this stays cheap even at the
     * 30-day / ~9k-rows-per-playlist steady state.
     *
     * @param  array<int, int>  $itemIds
     */
    private function writeSnapshot(int $groupId, string $itemType, array $itemIds, int $syncRunId): void
    {
        $now = now();
        foreach (array_chunk($itemIds, self::MEMBERSHIP_CHUNK_SIZE) as $chunk) {
            DynamicGroupItemSnapshot::insert(
                array_map(fn (int $id): array => [
                    'dynamic_group_id' => $groupId,
                    'sync_run_id' => $syncRunId,
                    'item_type' => $itemType,
                    'item_id' => $id,
                    'captured_at' => $now,
                ], $chunk),
            );
        }
    }
}
