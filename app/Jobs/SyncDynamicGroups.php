<?php

namespace App\Jobs;

use App\Enums\SyncRunPhase;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\DynamicGroupItemSnapshot;
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

                $this->syncMembership($group, $type, $playlist->id, $tmdbIds, $this->syncRunId);

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
        $itemIds = DynamicGroup::itemsMatchingTmdbIds($type, $playlistId, $tmdbIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

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

        foreach (array_chunk($itemIds, self::MEMBERSHIP_CHUNK_SIZE) as $chunk) {
            DB::table('dynamic_group_items')->insertOrIgnore(
                array_map(fn (int $id): array => [
                    'dynamic_group_id' => $group->id,
                    'item_type' => $morphClass,
                    'item_id' => $id,
                ], $chunk),
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
