<?php

namespace App\Jobs;

use App\Enums\SyncRunPhase;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\DynamicGroupItemSnapshot;
use App\Models\Playlist;
use App\Models\Series;
use App\Services\SyncPipelineService;
use App\Services\TmdbService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
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

        if ($staleIds !== []) {
            // PR #1500 Bug 3: a rule with cache_retention_mode=never_expire
            // should pin its cached files for the lifetime of the file, not
            // for the lifetime of the rule. If the user disables (or, less
            // commonly, replaces) the rule, the DynamicGroup row gets
            // hard-deleted, the pivot FKs cascade-delete, and the file
            // appears orphaned to the next retention pass — which then
            // hard-deletes the file. Stamp never_expire=true on every
            // referenced CachedContentFile before the delete fires so the
            // file is preserved by the retention short-circuit.
            //
            // We look up the rule from the FULL playlist config (not the
            // `enabled`-filtered $rules above) so a still-present-but-
            // disabled rule is matched. Rules the user has fully removed
            // (true rename / replacement) fall through unmarked — the
            // retention policy travels with the rule in that case, but a
            // fully-removed rule is a deliberate "this content shouldn't be
            // pinned forever anymore" signal and a strict-interpretation
            // hard-delete is acceptable.
            $this->pinNeverExpireFilesForStaleGroups($playlist, $existing, $staleIds);
            DynamicGroup::whereIn('id', $staleIds)->delete();
        }
    }

    /**
     * For each stale DynamicGroup whose matching rule (in the playlist's
     * current `dynamic_groups_config`, even if disabled) has
     * `cache_retention_mode=never_expire`, stamp `never_expire=true` on
     * every CachedContentFile it currently references via the pivot.
     *
     * Runs BEFORE the DynamicGroup::whereIn()->delete() so the pivot
     * rows still exist for the file lookup — the cascade is the
     * destructive step we're guarding against.
     */
    private function pinNeverExpireFilesForStaleGroups(
        Playlist $playlist,
        Collection $existing,
        array $staleIds,
    ): void {
        $config = $playlist->dynamic_groups_config;
        if (! is_array($config) || $config === []) {
            return;
        }

        $staleByTriple = $existing
            ->filter(fn (DynamicGroup $dg): bool => in_array((int) $dg->id, $staleIds, true))
            ->keyBy(fn (DynamicGroup $dg): string => $dg->type.':'.$dg->source.':'.$dg->name);

        foreach ($staleByTriple as $triple => $dg) {
            // Find the matching rule — the rule may be present in the
            // current config but disabled (the common case for "user turned
            // the rule off"). It can also be absent (the user deleted the
            // rule entirely) — for that case we can't recover the
            // retention mode, so we leave the file unmarked and let
            // retention delete it as before.
            $mode = null;
            foreach ($config as $rule) {
                if (! is_array($rule)) {
                    continue;
                }
                if (($rule['type'] ?? null) === $dg->type
                    && ($rule['source'] ?? null) === $dg->source
                    && trim((string) ($rule['name'] ?? '')) === $dg->name
                ) {
                    $mode = $rule['cache_retention_mode'] ?? 'match_group_lifetime';
                    break;
                }
            }

            if ($mode !== 'never_expire') {
                continue;
            }

            $fileIds = DB::table('cached_content_file_dynamic_groups')
                ->where('dynamic_group_id', $dg->id)
                ->pluck('cached_content_file_id');

            if ($fileIds->isEmpty()) {
                continue;
            }

            // Marked rows are NOT updated when the user re-enables the rule
            // in a future sync — that's an explicit "I want this to expire
            // normally now" gesture. (Re-enabling syncs the same files in
            // via the normal pivot path; retention sees them as live and
            // keeps them, never_expire stays true as a harmless flag.)
            CachedContentFile::query()
                ->whereIn('id', $fileIds->all())
                ->update(['never_expire' => true]);
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
     *    TMDB returned no ids AND no pre-existing DynamicGroup row for this
     *    (playlist, type, source, name) tuple. The latter matches the
     *    existing batch-job behavior: a rule with empty TMDB results is a
     *    no-op for new rules (the next sync will retry once TMDB is healthy)
     *    but is also a no-op for existing rules (keeps the Xtream category id
     *    stable).
     *  - the DynamicGroup row otherwise. `last_synced_at` is set, sort_order
     *    is recorded, and membership is rewritten from the current TMDB
     *    snapshot. `enabled` is forced true because the caller already
     *    filtered disabled rules upstream.
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

        $tmdbIds = $this->collectTmdbIds($tmdb, $type, $source, $params);
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

        $this->syncMembership($group, $type, $playlist->id, $tmdbIds, $this->syncRunId);

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
