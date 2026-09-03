<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Series;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Re-routes each individual channel / series into a per-playlist group / category
 * whose name matches that item's own genre, sourced from TMDB's canonical genre
 * list. Items with no genre data (or a genre that doesn't resolve to a canonical
 * name) land in a per-playlist "Uncategorized" group / category.
 *
 * Original group rows are never deleted — they just end up empty if every member
 * moved out. This includes the "Uncategorized" group itself: it is a legitimate
 * source group, since content already sitting in Uncategorized (e.g. moved there
 * by an earlier buggy reclassify pass) gets re-evaluated and routed out to the
 * right genre group when its `info['genre']` / `genre` data is populated.
 *
 * **Scope:** only `enabled = true` content is reclassified. `Channel.enabled` /
 * `Series.enabled` is this app's "exclude from Xtream output" gate; deliberately-
 * disabled items are left exactly where they are so the user's existing organizational
 * grouping for "stuff I've turned off" survives untouched.
 *
 * **New groups/categories:** when a genre match requires a row that doesn't yet
 * exist, the service creates it with `enabled = true` so future auto-imported
 * content (via `ProcessM3uImport.php:166-167`) defaults to enabled when assigned
 * to it. Existing groups/categories found via the in-memory lookup have their
 * `enabled` state left exactly as-is — reclassify never silently flips a user's
 * prior configuration.
 *
 * Protected from reclassification (channel / series-level, regardless of own genre):
 *   - The item's current group is merged (is_merged = true) — never touch merged-group
 *     membership.
 *   - The item's current group_id is referenced by an enabled rule in the playlist's
 *     auto_sync_to_custom_config with type 'vod_groups' / 'series_categories' AND
 *     group_filter = 'selected'. group_filter = 'new_only' rules carry no static
 *     group list and are out of scope (documented gap).
 *
 * Performance: iteration is via `chunkById(100, ...)` (mirrors FetchTmdbIds.php's
 * own pattern — `cursor()` would risk "database is locked" on SQLite), and groups /
 * categories for the playlist are loaded **once** outside the chunk loop and looked
 * up in-memory, avoiding the per-row query explosion on large playlists.
 *
 * If TMDB is not configured the service is a no-op — we cannot know the canonical
 * genre list, so we refuse to make destructive changes.
 */
class GenreGroupReclassifyService
{
    /**
     * Re-route each individual enabled VOD channel for the given playlist to a group
     * matching its own TMDB genre (or "Uncategorized" if no genre data resolves).
     *
     * @return array{moved: int, protected: int, no_op: bool}
     */
    public static function reclassifyVodGroups(Playlist $playlist): array
    {
        $tmdb = app(TmdbService::class);
        if (! $tmdb->isConfigured()) {
            return ['moved' => 0, 'protected' => 0, 'no_op' => true];
        }

        // Canonical-cased list (id => name) — used to look up proper casing for new groups.
        $genres = $tmdb->getMovieGenres();
        $canonicalNamesLower = collect($genres)
            ->map(fn (array $g) => mb_strtolower(trim($g['name'])))
            ->all();
        $canonicalCaseLookup = [];
        foreach ($genres as $g) {
            $canonicalCaseLookup[mb_strtolower(trim($g['name']))] = trim($g['name']);
        }

        $protectedIds = self::protectedGroupIds($playlist);

        // Load all of this playlist's VOD groups **once** — used for both current-
        // group lookup (in-memory by id) and target-group case-insensitive name match.
        // Mutated as new groups are created within the chunk loop, so subsequent chunks
        // can reuse the in-memory entries instead of re-querying.
        $groupsById = Group::query()
            ->where('playlist_id', $playlist->id)
            ->where('type', 'vod')
            ->get()
            ->keyBy('id');

        $moved = 0;
        $protected = 0;

        // Iterate enabled VOD channels in chunks. Uncategorized is an eligible source
        // group: channels already parked there with genre data get re-evaluated and
        // routed out. Disabled channels are deliberately skipped — see class docblock.
        Channel::query()
            ->where('playlist_id', $playlist->id)
            ->where('is_vod', true)
            ->where('enabled', true)
            ->whereNotNull('group_id')
            ->select(['id', 'playlist_id', 'group_id', 'group', 'group_internal', 'info'])
            ->orderBy('id')
            ->chunkById(100, function ($channels) use (
                $playlist,
                $canonicalNamesLower,
                $canonicalCaseLookup,
                $protectedIds,
                &$groupsById,
                &$moved,
                &$protected,
            ): void {
                foreach ($channels as $channel) {
                    $currentGroup = $groupsById->get($channel->group_id);

                    // Skip if the channel's current group is protected by an enabled
                    // auto_sync_to_custom_config rule.
                    if ($currentGroup && in_array((int) $currentGroup->id, $protectedIds, true)) {
                        $protected++;

                        continue;
                    }

                    // Skip if the channel's current group is merged — merged groups
                    // never have their membership touched programmatically. Read
                    // is_merged from the raw attributes (loaded by the bulk query)
                    // to avoid any possibility of a per-row lazy-load.
                    if ($currentGroup && (bool) ($currentGroup->getAttributes()['is_merged'] ?? false)) {
                        $protected++;

                        continue;
                    }

                    // Determine the channel's own primary genre from info['genre'].
                    $info = $channel->info ?? [];
                    $rawGenre = $info['genre'] ?? null;

                    $primaryLower = self::primaryGenreLower($rawGenre);

                    if ($primaryLower !== null && in_array($primaryLower, $canonicalNamesLower, true)) {
                        // Use the canonical casing for the target group name.
                        $targetName = $canonicalCaseLookup[$primaryLower];
                    } else {
                        $targetName = 'Uncategorized';
                    }

                    // No-op if the channel is already in a group whose name matches the
                    // target (case-insensitive, trimmed). Prevents churn on every
                    // channel every run.
                    if ($currentGroup && mb_strtolower(trim((string) $currentGroup->name)) === mb_strtolower($targetName)) {

                        continue;
                    }

                    // Look for an existing group for this playlist whose name matches the
                    // target (case-insensitive). Search $groupsById in-memory first;
                    // fall back to a single bulk query for any target name we haven't
                    // seen yet this run.
                    $targetGroup = self::findTargetGroup(
                        $groupsById,
                        $playlist->id,
                        'vod',
                        $targetName,
                    );

                    if (! $targetGroup) {
                        $targetGroup = Group::create([
                            'playlist_id' => $playlist->id,
                            'name' => $targetName,
                            'name_internal' => $targetName,
                            'user_id' => $playlist->user_id,
                            'type' => 'vod',
                            // Genre groups we create ourselves must default to enabled.
                            // `Group.enabled` gates the default-enabled state of future
                            // auto-imported channels assigned to this group (see
                            // ProcessM3uImport.php:166-167). Without this, content would
                            // silently land disabled by default and never be reclassified
                            // (since reclassify only touches enabled content). Pre-existing
                            // groups' enabled state is the user's own prior configuration
                            // and is NEVER overwritten here — see findTargetGroup().
                            'enabled' => true,
                        ]);
                        $groupsById->put($targetGroup->id, $targetGroup);
                    }

                    $channel->update([
                        'group_id' => $targetGroup->id,
                        'group' => $targetName,
                        'group_internal' => $targetName,
                    ]);

                    $moved++;
                }
            });

        return ['moved' => $moved, 'protected' => $protected, 'no_op' => false];
    }

    /**
     * Re-route each individual enabled Series for the given playlist to a category
     * matching its own TMDB genre (or "Uncategorized" if no genre data resolves).
     *
     * @return array{moved: int, protected: int, no_op: bool}
     */
    public static function reclassifyCategories(Playlist $playlist): array
    {
        $tmdb = app(TmdbService::class);
        if (! $tmdb->isConfigured()) {
            return ['moved' => 0, 'protected' => 0, 'no_op' => true];
        }

        $genres = $tmdb->getTvGenres();
        $canonicalNamesLower = collect($genres)
            ->map(fn (array $g) => mb_strtolower(trim($g['name'])))
            ->all();
        $canonicalCaseLookup = [];
        foreach ($genres as $g) {
            $canonicalCaseLookup[mb_strtolower(trim($g['name']))] = trim($g['name']);
        }

        $protectedIds = self::protectedCategoryIds($playlist);

        // Categories for this playlist, loaded once.
        $categoriesById = Category::query()
            ->where('playlist_id', $playlist->id)
            ->get()
            ->keyBy('id');

        $moved = 0;
        $protected = 0;

        Series::query()
            ->where('playlist_id', $playlist->id)
            ->where('enabled', true)
            ->whereNotNull('category_id')
            ->select(['id', 'playlist_id', 'category_id', 'genre'])
            ->orderBy('id')
            ->chunkById(100, function ($seriesItems) use (
                $playlist,
                $canonicalNamesLower,
                $canonicalCaseLookup,
                $protectedIds,
                &$categoriesById,
                &$moved,
                &$protected,
            ): void {
                foreach ($seriesItems as $series) {
                    $currentCategory = $categoriesById->get($series->category_id);

                    if ($currentCategory && in_array((int) $currentCategory->id, $protectedIds, true)) {
                        $protected++;

                        continue;
                    }

                    if ($currentCategory && $currentCategory->is_merged) {
                        $protected++;

                        continue;
                    }

                    // Series.genre is a plain column.
                    $rawGenre = $series->genre;
                    $primaryLower = self::primaryGenreLower($rawGenre);

                    if ($primaryLower !== null && in_array($primaryLower, $canonicalNamesLower, true)) {
                        $targetName = $canonicalCaseLookup[$primaryLower];
                    } else {
                        $targetName = 'Uncategorized';
                    }

                    // No-op if already in a category whose name matches the target.
                    if ($currentCategory && mb_strtolower(trim((string) $currentCategory->name)) === mb_strtolower($targetName)) {

                        continue;
                    }

                    $targetCategory = self::findTargetCategory(
                        $categoriesById,
                        $playlist->id,
                        $targetName,
                    );

                    if (! $targetCategory) {
                        $targetCategory = Category::create([
                            'playlist_id' => $playlist->id,
                            'name' => $targetName,
                            'name_internal' => $targetName,
                            'user_id' => $playlist->user_id,
                            // See VOD note above on Category.enabled semantics.
                            // Pre-existing categories' enabled state is never touched.
                            'enabled' => true,
                        ]);
                        $categoriesById->put($targetCategory->id, $targetCategory);
                    }

                    // Mirror the existing "Move Series to Category" bulk action's
                    // field scope: only the FK is rewritten, never Series.genre or
                    // Series.source_category_id.
                    $series->update(['category_id' => $targetCategory->id]);

                    $moved++;
                }
            });

        return ['moved' => $moved, 'protected' => $protected, 'no_op' => false];
    }

    /**
     * Look up a target group by name (case-insensitive) in the in-memory map.
     * Falls back to a single DB query for names we haven't seen yet this run —
     * could happen if a group exists in the DB but wasn't loaded into the map
     * for some reason (defensive — currently the map loads every group for the
     * playlist, so this branch shouldn't fire, but it keeps the algorithm
     * robust against future changes).
     *
     * @param  EloquentCollection<Group>  $groupsById
     */
    private static function findTargetGroup(EloquentCollection $groupsById, int $playlistId, string $type, string $targetName): ?Group
    {
        $targetLower = mb_strtolower($targetName);

        foreach ($groupsById as $group) {
            if ($group->type === $type && mb_strtolower(trim((string) $group->name)) === $targetLower) {
                return $group;
            }
        }

        // Fallback: single query, then cache the result.
        $existing = Group::query()
            ->where('playlist_id', $playlistId)
            ->where('type', $type)
            ->whereRaw('LOWER(name) = ?', [$targetLower])
            ->first();
        if ($existing) {
            $groupsById->put($existing->id, $existing);
        }

        return $existing;
    }

    /**
     * Look up a target category by name (case-insensitive) in the in-memory map.
     * Same fallback semantics as findTargetGroup.
     *
     * @param  EloquentCollection<Category>  $categoriesById
     */
    private static function findTargetCategory(EloquentCollection $categoriesById, int $playlistId, string $targetName): ?Category
    {
        $targetLower = mb_strtolower($targetName);

        foreach ($categoriesById as $category) {
            if (mb_strtolower(trim((string) $category->name)) === $targetLower) {
                return $category;
            }
        }

        $existing = Category::query()
            ->where('playlist_id', $playlistId)
            ->whereRaw('LOWER(name) = ?', [$targetLower])
            ->first();
        if ($existing) {
            $categoriesById->put($existing->id, $existing);
        }

        return $existing;
    }

    /**
     * Extract the lowercased primary genre from a raw genre value.
     * Mirrors FetchTmdbIds.php's extraction logic at :614-616 so behavior stays
     * consistent app-wide. Accepts string (comma-separated), array, or null.
     */
    private static function primaryGenreLower(mixed $rawGenre): ?string
    {
        if ($rawGenre === null || $rawGenre === '') {
            return null;
        }

        $primary = is_string($rawGenre)
            ? explode(', ', $rawGenre)[0]
            : (is_array($rawGenre) ? ($rawGenre[0] ?? null) : null);

        if ($primary === null || $primary === '' || $primary === 'Uncategorized') {
            return null;
        }

        return mb_strtolower(trim((string) $primary));
    }

    /**
     * Group IDs referenced by enabled auto_sync_to_custom_config rules with
     * group_filter = 'selected'. Rules with group_filter = 'new_only' carry no
     * static group list — documented gap, not asserted.
     *
     * @return array<int>
     */
    private static function protectedGroupIds(Playlist $playlist): array
    {
        $config = $playlist->auto_sync_to_custom_config ?? [];

        $ids = [];
        foreach ($config as $rule) {
            if (($rule['enabled'] ?? false) !== true) {
                continue;
            }
            if (($rule['type'] ?? '') !== 'vod_groups') {
                continue;
            }
            if (($rule['group_filter'] ?? 'selected') !== 'selected') {
                continue;
            }
            foreach ((array) ($rule['groups'] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Category IDs referenced by enabled auto_sync_to_custom_config rules with
     * group_filter = 'selected'.
     *
     * @return array<int>
     */
    private static function protectedCategoryIds(Playlist $playlist): array
    {
        $config = $playlist->auto_sync_to_custom_config ?? [];

        $ids = [];
        foreach ($config as $rule) {
            if (($rule['enabled'] ?? false) !== true) {
                continue;
            }
            if (($rule['type'] ?? '') !== 'series_categories') {
                continue;
            }
            if (($rule['group_filter'] ?? 'selected') !== 'selected') {
                continue;
            }
            foreach ((array) ($rule['groups'] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
