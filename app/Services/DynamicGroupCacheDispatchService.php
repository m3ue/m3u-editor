<?php

namespace App\Services;

use App\Enums\CachedContentFileStatus;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\Playlist;
use App\Settings\GeneralSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared dispatch + lookup helpers for the Dynamic Group Cache feature.
 *
 * Extracted from `CacheDynamicGroupContent` (Phase 2) so the scheduled
 * dispatcher and the playback-time lazy trigger (Phase 3) share one
 * implementation of: dedup/cooldown checks, identity+fingerprint build,
 * source-URL resolution, and job dispatch.
 *
 * Stateless — all dependencies are static (PlaylistUrlService, the job's
 * `dispatch` factory, the model's `fingerprintFor` static, and the
 * `GeneralSettings` singleton via the `app()` helper to match the
 * convention already used in CacheDynamicGroupContent).
 */
class DynamicGroupCacheDispatchService
{
    /**
     * Look up a Completed CachedContentFile by content identity only —
     * quality is deliberately ignored at playback time (Phase 3 limitation:
     * a single Channel/Episode being played doesn't carry a queryable
     * "which quality variant" attribute, so the first Completed match wins).
     *
     * Returns null if no Completed match exists.
     */
    public function findCompletedCache(
        string $contentType,
        ?string $tmdbId,
        ?string $tvdbId,
        ?int $seasonNumber,
        ?int $episodeNumber,
    ): ?CachedContentFile {
        return CachedContentFile::query()
            ->where('content_type', $contentType)
            ->where('tmdb_id', $tmdbId)
            ->where('tvdb_id', $tvdbId)
            ->where('season_number', $seasonNumber)
            ->where('episode_number', $episodeNumber)
            ->where('status', CachedContentFileStatus::Completed)
            ->first();
    }

    /**
     * Find a Dynamic Group + cache rule applicable to a Channel at play time.
     *
     * Iterates the channel's already-materialized DynamicGroup memberships
     * (via the polymorphic `dynamic_group_items` pivot), then resolves each
     * group's owning rule from its playlist's `dynamic_groups_config`
     * (matched by rule `name`). Returns the first rule where
     * `cache_enabled === true`.
     *
     * Used by the lazy trigger to decide whether the play request should
     * enqueue a download for missing content.
     *
     * @return array{group: DynamicGroup, rule: array<string, mixed>}|null
     */
    public function findCacheableRuleForChannel(Channel $channel): ?array
    {
        foreach ($channel->dynamicGroups as $group) {
            $rule = $this->resolveRuleForGroup($group);
            if ($rule !== null && ($rule['cache_enabled'] ?? false) === true) {
                return ['group' => $group, 'rule' => $rule];
            }
        }

        return null;
    }

    /**
     * Find a Dynamic Group + cache rule applicable to an Episode at play time.
     *
     * Episodes don't have their own DynamicGroup membership — they go
     * through their parent Series. Mirror what the scheduled dispatcher
     * does at `CacheDynamicGroupContent::dispatchForRule()` so a play
     * and the next scheduled run agree on which group "owns" this content.
     *
     * @return array{group: DynamicGroup, rule: array<string, mixed>}|null
     */
    public function findCacheableRuleForEpisode(Episode $episode): ?array
    {
        $series = $episode->series;
        if (! $series) {
            return null;
        }

        foreach ($series->dynamicGroups as $group) {
            $rule = $this->resolveRuleForGroup($group);
            if ($rule !== null && ($rule['cache_enabled'] ?? false) === true) {
                return ['group' => $group, 'rule' => $rule];
            }
        }

        return null;
    }

    /**
     * Build a DownloadCachedContentFile dispatch for a Channel, with all
     * the standard skip-cooldown / fingerprint / url-resolve checks applied.
     *
     * Does NOT apply the recency filter (`cache_content_selection === 'recent'`)
     * — that is a scheduled-dispatch-only concern. At lazy-trigger time the
     * user is explicitly watching this content, so "within X days" doesn't
     * apply to "the user is watching it right now."
     *
     * Returns true if a DownloadCachedContentFile job was actually queued,
     * false if skipped (already completed / failed-in-cooldown / no URL).
     * Callers use this for accurate "dispatched N" reporting from group-
     * scoped manual dispatches.
     */
    public function dispatchForChannel(Playlist $playlist, DynamicGroup $group, Channel $channel, array $rule): bool
    {
        $tmdbId = $channel->tmdb_id !== null ? (string) $channel->tmdb_id : null;
        $quality = $this->resolveQuality($rule);

        $fingerprint = CachedContentFile::fingerprintFor([
            'content_type' => 'movie',
            'tmdb_id' => $tmdbId,
            'quality' => $quality,
        ]);

        if ($this->shouldSkip($fingerprint)) {
            return false;
        }

        $url = PlaylistUrlService::getChannelUrl($channel, $playlist);
        if (! $url) {
            return false;
        }

        return $this->dispatchJob($group, $fingerprint, 'movie', $tmdbId, null, null, null, $quality, $url);
    }

    /**
     * Same as dispatchForChannel but for Episode content. Uses the *series'*
     * tmdb_id (matches Phase 2's `CacheDynamicGroupContent::maybeDispatchForEpisode`
     * convention so a fingerprint built here matches what's already cached).
     *
     * Returns true if a DownloadCachedContentFile job was actually queued
     * (see dispatchForChannel() docblock).
     */
    public function dispatchForEpisode(Playlist $playlist, DynamicGroup $group, Episode $episode, array $rule): bool
    {
        $series = $episode->series;
        $tmdbId = ($series && $series->tmdb_id !== null) ? (string) $series->tmdb_id : null;
        $quality = $this->resolveQuality($rule);

        $fingerprint = CachedContentFile::fingerprintFor([
            'content_type' => 'episode',
            'tmdb_id' => $tmdbId,
            'season_number' => $episode->season,
            'episode_number' => $episode->episode_number,
            'quality' => $quality,
        ]);

        if ($this->shouldSkip($fingerprint)) {
            return false;
        }

        $url = PlaylistUrlService::getEpisodeUrl($episode, $playlist);
        if (! $url) {
            return false;
        }

        return $this->dispatchJob($group, $fingerprint, 'episode', $tmdbId, null, $episode->season, $episode->episode_number, $quality, $url);
    }

    /**
     * Quality preference read from the per-rule config. Free-text tag
     * (e.g. "4K", "1080p") folded into the content fingerprint so
     * different rules with different quality preferences produce
     * different fingerprint buckets.
     */
    public function resolveQuality(array $rule): ?string
    {
        return $rule['cache_prefer_quality_keyword'] ?? null;
    }

    /**
     * Returns true if we should NOT dispatch — already completed, or
     * failed within its cooldown window.
     */
    public function shouldSkip(string $fingerprint): bool
    {
        $existing = CachedContentFile::where('content_fingerprint', $fingerprint)->first();
        if (! $existing) {
            return false;
        }

        if ($existing->status === CachedContentFileStatus::Completed) {
            return true; // cross-playlist dedup
        }

        if ($existing->status === CachedContentFileStatus::Failed) {
            // Cooldown: short (retry_cooldown_minutes) for low failure_count,
            // long (failure_cooldown_hours) once we cross 3 failures.
            $settings = app(GeneralSettings::class);
            $cooldownSeconds = (int) $existing->failure_count < 3
                ? ((int) $settings->dynamic_group_cache_retry_cooldown_minutes * 60)
                : ((int) $settings->dynamic_group_cache_failure_cooldown_hours * 3600);

            return $existing->last_failed_at && $existing->last_failed_at->addSeconds($cooldownSeconds)->isFuture();
        }

        return false; // Pending or Downloading — proceed (no harm, job handles concurrency)
    }

    /**
     * Resolve the rule config for a single DynamicGroup by matching its
     * `name` against the owning playlist's `dynamic_groups_config` array.
     * Returns null if the playlist has no matching rule (e.g. group
     * materialized from a rule that has since been deleted).
     *
     * Public so the Phase 4 progress UI ("Cached / Total" column on the
     * DynamicGroupsWidget) and the "Select Content" picker can look up a
     * group's rule without duplicating the name-matching logic. Widened
     * from `private` in Phase 4 — pure visibility change, no behavior
     * change.
     */
    public function resolveRuleForGroup(DynamicGroup $group): ?array
    {
        $playlist = $group->playlist;
        if (! $playlist) {
            return null;
        }

        $config = $playlist->dynamic_groups_config;
        if (! is_array($config)) {
            return null;
        }

        foreach ($config as $rule) {
            if (is_array($rule) && ($rule['name'] ?? null) === $group->name) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Reverse of `resolveRuleForGroup()`: find the materialized DynamicGroup
     * row that corresponds to a rule's `name` on a given playlist.
     *
     * Used by the Phase 4 "Select Content" picker UI to scope its backing
     * table to the group's own membership (`$group->channels()` for vod,
     * `$group->series()` for series). The picker lives inside a Repeater
     * item on the Playlist form — it knows the Playlist ID and the rule's
     * `name` from sibling repeater fields but does NOT have a direct handle
     * to the DynamicGroup row, so this lookup is the bridge.
     *
     * Returns null if the rule's group hasn't been materialized yet
     * (e.g. the playlist hasn't been synced) or if the rule's `name` is
     * missing — callers must handle null (the picker is gated on a
     * non-empty `cache_enabled` and a valid `name`, so null means
     * "no membership to browse").
     */
    public function resolveGroupForRule(int $playlistId, array $rule): ?DynamicGroup
    {
        $name = $rule['name'] ?? null;
        if (! is_string($name) || $name === '') {
            return null;
        }

        return DynamicGroup::query()
            ->where('playlist_id', $playlistId)
            ->where('name', $name)
            ->first();
    }

    /**
     * Create the tracking row in Pending status immediately, before the job
     * is even dispatched, so the Cache Activity widget can show it as
     * queued right away — previously the row didn't exist until the job's
     * own handle() cleared the concurrency gate, so anything waiting on a
     * throttled slot was invisible to the UI.
     *
     * Uses firstOrCreate keyed on the unique content_fingerprint: if a row
     * already exists (Failed past cooldown, or Downloading/Pending from a
     * previous dispatch for the same content — shouldSkip() allows those
     * through), it's left untouched here and the job's own reclaim logic
     * (DownloadCachedContentFile::reclaimExistingRow()) governs the
     * transition. Wrapped the same way as the job's own INSERT (DB::transaction
     * + QueryException fallback) to survive a concurrent dispatch racing on
     * the same fingerprint — see DownloadCachedContentFile.php Step 5 for
     * why the savepoint nesting matters on Postgres.
     */
    private function dispatchJob(
        DynamicGroup $group,
        string $fingerprint,
        string $contentType,
        ?string $tmdbId,
        ?string $tvdbId,
        ?int $seasonNumber,
        ?int $episodeNumber,
        ?string $quality,
        string $url,
    ): bool {
        try {
            $file = DB::transaction(fn () => CachedContentFile::create([
                'content_type' => $contentType,
                'tmdb_id' => $tmdbId,
                'tvdb_id' => $tvdbId,
                'season_number' => $seasonNumber,
                'episode_number' => $episodeNumber,
                'quality' => $quality,
                'content_fingerprint' => $fingerprint,
                'status' => CachedContentFileStatus::Pending,
            ]));
        } catch (QueryException) {
            $file = CachedContentFile::where('content_fingerprint', $fingerprint)->first();
        }

        $file?->dynamicGroups()->syncWithoutDetaching([$group->id]);

        DownloadCachedContentFile::dispatch(
            $group,
            $contentType,
            $tmdbId,
            $tvdbId,
            $seasonNumber,
            $episodeNumber,
            $quality,
            $url,
        )->onQueue('dynamic-group-cache');

        return true;
    }

    /**
     * Dispatch DownloadCachedContentFile jobs for every eligible channel
     * (vod) or episode (series) in one DynamicGroup. Bypasses the cron
     * gate + recency filter used by the scheduled `CacheDynamicGroupContent`
     * command — this is the manual-trigger path, useful for one-off kicks
     * from the Edit Group page or test harnesses.
     *
     * Same skip-cooldown / fingerprint / url-resolve checks as the
     * scheduled path apply (shouldSkip), so re-runs on a group where
     * everything is already Completed will return dispatched=0 instead of
     * burning dispatch slots.
     *
     * @return array{dispatched: int, cache_enabled: bool, reason: ?string}
     *                                                                      dispatched: count of DownloadCachedContentFile jobs actually queued
     *                                                                      cache_enabled: whether the rule resolved for this group has cache_enabled=true
     *                                                                      reason: human-readable explanation when dispatched=0 (cache disabled,
     *                                                                      no rule, no playlist, etc.)
     */
    public function dispatchForGroup(DynamicGroup $group): array
    {
        $playlist = $group->playlist;
        if (! $playlist) {
            return ['dispatched' => 0, 'cache_enabled' => false, 'reason' => 'Group has no playlist.'];
        }

        $rule = $this->resolveRuleForGroup($group);
        if ($rule === null) {
            return ['dispatched' => 0, 'cache_enabled' => false, 'reason' => 'No cache rule for this group.'];
        }

        if (! ($rule['cache_enabled'] ?? false)) {
            return ['dispatched' => 0, 'cache_enabled' => false, 'reason' => 'Cache is not enabled for this group (check the rule in Preferences > Dynamic Groups).'];
        }

        $dispatched = 0;

        if ($group->type === 'vod') {
            foreach ($group->channels as $channel) {
                if ($this->dispatchForChannel($playlist, $group, $channel, $rule)) {
                    $dispatched++;
                }
            }
        } elseif ($group->type === 'series') {
            foreach ($group->series as $series) {
                foreach ($series->episodes as $episode) {
                    if ($this->dispatchForEpisode($playlist, $group, $episode, $rule)) {
                        $dispatched++;
                    }
                }
            }
        }

        return [
            'dispatched' => $dispatched,
            'cache_enabled' => true,
            'reason' => $dispatched === 0 ? 'All eligible content is already cached or in cooldown.' : null,
        ];
    }

    /**
     * Bulk dispatch over an arbitrary Collection of DynamicGroup records.
     * Used by the bulk "Cache Now" action on `DynamicGroupsWidget` (the
     * per-row checkbox-driven action menu that mirrors what the Movies
     * relation manager on the Edit Group page exposes for per-channel
     * actions — same operator UX, scoped to the Dynamic Groups grid).
     *
     * Each group runs through `dispatchForGroup()` so the same
     * skip-cooldown / fingerprint / url-resolve checks apply.
     *
     * Counts returned:
     *   - dispatched: total DownloadCachedContentFile jobs actually queued
     *                  across the selected groups
     *   - groups_processed: groups where cache_enabled=true (skipped rules
     *                       are still counted in groups_total)
     *   - groups_total: total groups passed in
     *
     * @return array{dispatched: int, groups_processed: int, groups_total: int}
     */
    public function dispatchForGroups(Collection $groups): array
    {
        $dispatched = 0;
        $processed = 0;

        foreach ($groups as $group) {
            $result = $this->dispatchForGroup($group);
            $dispatched += $result['dispatched'];
            if ($result['cache_enabled']) {
                $processed++;
            }
        }

        return [
            'dispatched' => $dispatched,
            'groups_processed' => $processed,
            'groups_total' => $groups->count(),
        ];
    }
}
