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
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
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
 * changes independent of any playlist sync. Form saves of the rules also
 * queue one via queueRefresh().
 *
 * Membership is full-sync: stale rows are deleted and the full current set
 * is rewritten in chunks. This is intentionally simpler than a delta — TMDB
 * list endpoints return small fixed-size pages, the membership set is
 * bounded by what's already in the playlist, and full-sync semantics avoid
 * the "member disabled, member row survives" drift that delta would need to
 * reason about.
 */
class SyncDynamicGroups implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Seconds a refresh queued by a form save waits before running, so a
     * burst of edits collapses into one TMDB refresh (see uniqueId()).
     */
    public const REFRESH_DELAY_SECONDS = 30;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900;

    /**
     * Upper bound on how long the unique lock is held, so a refresh that
     * never starts (worker down, queue flushed) can't block later ones.
     */
    public int $uniqueFor = 3600;

    /**
     * Pivot-row insert chunk size (matches AutoSyncGroupsToCustomPlaylist).
     */
    private const MEMBERSHIP_CHUNK_SIZE = 1000;

    /**
     * @param  bool  $refreshMembership  False when a form saves the rules
     *                                   (EditPlaylist): rows are updated from the rules without calling
     *                                   TMDB, and the refresh from queueRefresh() fills in the members.
     *                                   See materializeRule().
     */
    public function __construct(
        public int $playlistId,
        public ?int $syncRunId = null,
        public ?SyncRunPhase $completionPhase = null,
        public bool $refreshMembership = true,
    ) {}

    /**
     * At most one waiting refresh per playlist: a refresh queued while
     * another is still waiting is dropped, since the waiting one reads the
     * latest rules when it starts. Pipeline runs are keyed by their sync run,
     * so they never collapse into a form or cron refresh and always complete
     * their phase.
     */
    public function uniqueId(): string
    {
        return $this->syncRunId !== null
            ? "run:{$this->syncRunId}"
            : "playlist:{$this->playlistId}";
    }

    /**
     * Queue a TMDB membership refresh after a form save has already updated
     * the rows without one (DynamicGroupRuleActions, EditPlaylist), so users
     * don't wait for the next playlist sync or daily refresh.
     */
    public static function queueRefresh(int $playlistId): void
    {
        dispatch(new self($playlistId))->delay(now()->addSeconds(self::REFRESH_DELAY_SECONDS));
    }

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

        $rules = collect($playlist->dynamic_groups_config ?? [])->values();

        $tmdb = app(TmdbService::class);

        // Collect (type, source, name) triples for whatever rules we
        // successfully process this run. Used by the cleanup pass below to
        // remove stale rows whose rule no longer exists.
        $validKeys = [];

        if ($tmdb->isConfigured() && $rules->isNotEmpty()) {
            foreach ($rules as $index => $rule) {
                ['type' => $type, 'source' => $source, 'name' => $name] = DynamicGroup::ruleIdentity($rule);
                $params = (array) ($rule['tmdb_params'] ?? []);

                if (! in_array($type, ['vod', 'series'], true) || $source === '' || $name === '') {
                    continue;
                }

                $triple = $type.':'.$source.':'.$name;
                $group = $this->materializeRule($playlist, $type, $source, $name, $params, $index, $tmdb, (bool) ($rule['enabled'] ?? false), $this->refreshMembership);

                if ($group !== null) {
                    $validKeys[] = $triple;
                }
            }
        }

        // Always run the cleanup pass - even when there are no rules
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
        // every rule when TMDB is unconfigured.
        if ($staleIds !== []) {
            DynamicGroup::whereIn('id', $staleIds)->delete();
        }
    }

    /**
     * Materialize one Dynamic Group rule into a DynamicGroup row + its
     * membership. Public so the create/edit actions on the VOD / Series
     * Dynamic Groups listing pages (DynamicGroupRuleActions) can call it
     * synchronously after writing a rule to a playlist's
     * `dynamic_groups_config`.
     *
     * Returns:
     *  - null when the rule is invalid (no usable type/source/name), or when
     *    TMDB returned no ids AND no pre-existing DynamicGroup row for this
     *    (playlist, type, source, name) tuple. The latter matches the
     *    existing batch-job behavior: a rule with empty TMDB results is a
     *    no-op for new rules (the next sync will retry once TMDB is healthy)
     *    but is also a no-op for existing rules (keeps the Xtream category id
     *    stable).
     *  - the DynamicGroup row otherwise. `last_synced_at` is set, sort_order
     *    is recorded, and membership is rewritten from the current TMDB
     *    snapshot.
     *
     * A disabled rule keeps its row (so it stays listed and editable, and its
     * Xtream category id survives being re-enabled) but skips TMDB and has
     * its membership cleared. Xtream output, the Emby picker and auto-cache
     * already ignore disabled groups.
     *
     * With `$refreshMembership` false (a rule saved from a form), the row is
     * updated from the rule without calling TMDB: a new group starts empty
     * and an edited one keeps its current members until the refresh the
     * save queues (queueRefresh()) rewrites them.
     */
    public function materializeRule(
        Playlist $playlist,
        string $type,
        string $source,
        string $name,
        array $params,
        int $sortOrder,
        TmdbService $tmdb,
        bool $enabled = true,
        bool $refreshMembership = true,
    ): ?DynamicGroup {
        if (! in_array($type, ['vod', 'series'], true) || $source === '' || $name === '') {
            return null;
        }

        $identity = [
            'playlist_id' => $playlist->id,
            'type' => $type,
            'source' => $source,
            'name' => $name,
        ];
        $attributes = [
            'user_id' => $playlist->user_id,
            'tmdb_params' => $params,
            'sort_order' => $sortOrder,
            'enabled' => $enabled,
        ];

        if (! $enabled || ! $refreshMembership) {
            $group = DynamicGroup::updateOrCreate($identity, $attributes);

            if (! $enabled) {
                DB::table('dynamic_group_items')->where('dynamic_group_id', $group->id)->delete();
            }

            return $group;
        }

        $tmdbIds = $this->collectTmdbIds($tmdb, $type, $source, $params);
        $existingForTriple = DynamicGroup::where($identity)->first();

        if ($tmdbIds === []) {
            // TMDB returned no ids for this rule. TmdbService returns
            // [] on any error (timeout, non-2xx, rate-limit - see its
            // catch blocks), so this is also the transient-failure
            // path. If we already have a DynamicGroup row for this
            // triple, treat the run as a no-op for it: keep the row
            // and its membership intact so the Xtream category id
            // (offset + id) stays stable. Only skip the create when
            // there's no row yet. A rule re-enabled while TMDB is failing
            // still shows as enabled.
            $existingForTriple?->update(['enabled' => true]);

            return $existingForTriple;
        }

        $group = DynamicGroup::updateOrCreate($identity, [...$attributes, 'last_synced_at' => now()]);

        $this->syncMembership($group, $type, $playlist->id, $tmdbIds, $this->syncRunId);

        // Rules that cache their members queue the downloads in their own
        // job so a large series group can't hold up this pipeline phase.
        $group->setRelation('playlist', $playlist);
        if (($group->cacheSettings()['enabled'] ?? false) && app(CachedContentDispatchService::class)->isEnabled()) {
            dispatch(new QueueDynamicGroupCacheDownloads($group->id));
        }

        return $group;
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
     * @param  array<int, string>  $tmdbIds
     */
    private function syncMembership(DynamicGroup $group, string $type, int $playlistId, array $tmdbIds, ?int $syncRunId = null): void
    {
        $morphClass = $type === 'vod' ? Channel::class : Series::class;
        // TMDB returns ids best-first; keep each id's first rank so members
        // list in TMDB order (trending rank, popularity, ...).
        $rankByTmdbId = [];
        foreach ($tmdbIds as $rank => $tmdbId) {
            $rankByTmdbId[$tmdbId] ??= $rank;
        }

        $tmdbIdByItemId = DynamicGroup::itemsMatchingTmdbIds($type, $playlistId, $tmdbIds)
            ->pluck('tmdb_id', 'id')
            ->all();
        $itemIds = array_map('intval', array_keys($tmdbIdByItemId));

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
                    'position' => $rankByTmdbId[(string) $tmdbIdByItemId[$id]] ?? 0,
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
