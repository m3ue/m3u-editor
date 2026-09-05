<?php

namespace App\Models;

use App\Pivots\BouquetPlaylistAlias;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Bouquet extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'group_selections' => 'array',
        'auto_include_new_live' => 'boolean',
        'auto_include_new_vod' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function customPlaylist(): BelongsTo
    {
        return $this->belongsTo(CustomPlaylist::class);
    }

    public function mergedPlaylist(): BelongsTo
    {
        return $this->belongsTo(MergedPlaylist::class);
    }

    public function playlistAliases(): BelongsToMany
    {
        return $this->belongsToMany(PlaylistAlias::class, 'bouquet_playlist_alias')
            ->using(BouquetPlaylistAlias::class);
    }

    /**
     * Whether this bouquet targets a merged playlist, whose selections are stored
     * as {playlist_id, name} pairs rather than bare names.
     */
    public function isMergedTarget(): bool
    {
        return $this->merged_playlist_id !== null;
    }

    /**
     * @return array<string>
     */
    public function getSelectedLiveGroupNames(): array
    {
        return PlaylistAlias::selectionNames($this->group_selections['selected_groups'] ?? []);
    }

    /**
     * @return array<string>
     */
    public function getSelectedVodGroupNames(): array
    {
        return PlaylistAlias::selectionNames($this->group_selections['selected_vod_groups'] ?? []);
    }

    /**
     * @return array<string>
     */
    public function getSelectedCategoryNames(): array
    {
        return PlaylistAlias::selectionNames($this->group_selections['selected_categories'] ?? []);
    }

    /**
     * Source-scoped {playlist_id, name} pairs for a merged-target bouquet (empty
     * for the other target types, which carry no source playlist).
     *
     * @return array<int, array{playlist_id: int, name: string}>
     */
    public function getSelectedLiveGroupSelections(): array
    {
        return PlaylistAlias::selectionPairs($this->group_selections['selected_groups'] ?? []);
    }

    /**
     * @return array<int, array{playlist_id: int, name: string}>
     */
    public function getSelectedVodGroupSelections(): array
    {
        return PlaylistAlias::selectionPairs($this->group_selections['selected_vod_groups'] ?? []);
    }

    /**
     * @return array<int, array{playlist_id: int, name: string}>
     */
    public function getSelectedCategorySelections(): array
    {
        return PlaylistAlias::selectionPairs($this->group_selections['selected_categories'] ?? []);
    }

    /**
     * Rewrite provider group renames (old name => new name) into the group
     * selections of every bouquet the renamed playlist feeds: standard-target
     * bouquets of that playlist, and merged-target bouquets whose merged playlist
     * lists it as a source (only the pairs scoped to that playlist_id change).
     * Called from the sync pipeline's rename-detection pass, the same one that
     * already rewrites import_prefs. Saves through Eloquent so the EPG-cache
     * invalidation hook fires for attached aliases.
     *
     * @param  array<string, string>  $renames
     */
    public static function applyProviderRenames(int $playlistId, string $type, array $renames): void
    {
        if (empty($renames)) {
            return;
        }

        $key = $type === 'vod' ? 'selected_vod_groups' : 'selected_groups';

        self::where('playlist_id', $playlistId)->cursor()->each(function (self $bouquet) use ($key, $renames): void {
            $current = $bouquet->group_selections[$key] ?? [];
            if (empty($current)) {
                return;
            }

            $updated = array_values(array_unique(
                array_map(fn (string $name): string => $renames[$name] ?? $name, $current)
            ));

            if ($updated !== $current) {
                $bouquet->update([
                    'group_selections' => array_merge($bouquet->group_selections ?? [], [$key => $updated]),
                ]);
            }
        });

        self::query()
            ->whereNotNull('merged_playlist_id')
            ->whereHas('mergedPlaylist.playlists', fn ($query) => $query->whereKey($playlistId))
            ->cursor()
            ->each(function (self $bouquet) use ($key, $playlistId, $renames): void {
                $current = PlaylistAlias::selectionPairs($bouquet->group_selections[$key] ?? []);
                if (empty($current)) {
                    return;
                }

                $updated = self::dedupePairs(array_map(function (array $pair) use ($playlistId, $renames): array {
                    if ($pair['playlist_id'] === $playlistId && isset($renames[$pair['name']])) {
                        return ['playlist_id' => $playlistId, 'name' => $renames[$pair['name']]];
                    }

                    return $pair;
                }, $current));

                if ($updated !== $current) {
                    $bouquet->update([
                        'group_selections' => array_merge($bouquet->group_selections ?? [], [$key => $updated]),
                    ]);
                }
            });
    }

    /**
     * Append newly-appeared provider group names to bouquets that opted in via
     * the per-type auto-include flag: bare names for standard-target bouquets of
     * the playlist, {playlist_id, name} pairs for merged-target bouquets whose
     * merged playlist lists it as a source.
     *
     * @param  array<string>  $newNames
     */
    public static function appendNewGroupNames(int $playlistId, string $type, array $newNames): void
    {
        if (empty($newNames)) {
            return;
        }

        $flag = $type === 'vod' ? 'auto_include_new_vod' : 'auto_include_new_live';
        $key = $type === 'vod' ? 'selected_vod_groups' : 'selected_groups';

        self::where('playlist_id', $playlistId)->where($flag, true)->cursor()->each(function (self $bouquet) use ($key, $newNames): void {
            $current = $bouquet->group_selections[$key] ?? [];
            $updated = array_values(array_unique(array_merge($current, $newNames)));

            if ($updated !== $current) {
                $bouquet->update([
                    'group_selections' => array_merge($bouquet->group_selections ?? [], [$key => $updated]),
                ]);
            }
        });

        $newPairs = array_map(fn (string $name): array => ['playlist_id' => $playlistId, 'name' => $name], $newNames);

        self::query()
            ->whereNotNull('merged_playlist_id')
            ->where($flag, true)
            ->whereHas('mergedPlaylist.playlists', fn ($query) => $query->whereKey($playlistId))
            ->cursor()
            ->each(function (self $bouquet) use ($key, $newPairs): void {
                $current = PlaylistAlias::selectionPairs($bouquet->group_selections[$key] ?? []);
                $updated = self::dedupePairs(array_merge($current, $newPairs));

                if ($updated !== $current) {
                    $bouquet->update([
                        'group_selections' => array_merge($bouquet->group_selections ?? [], [$key => $updated]),
                    ]);
                }
            });
    }

    /**
     * Distinct {playlist_id, name} pairs, order preserved.
     *
     * @param  array<int, array{playlist_id: int, name: string}>  $pairs
     * @return array<int, array{playlist_id: int, name: string}>
     */
    private static function dedupePairs(array $pairs): array
    {
        $seen = [];
        $out = [];
        foreach ($pairs as $pair) {
            $token = $pair['playlist_id'].':'.$pair['name'];
            if (! isset($seen[$token])) {
                $seen[$token] = true;
                $out[] = ['playlist_id' => (int) $pair['playlist_id'], 'name' => $pair['name']];
            }
        }

        return $out;
    }

    /**
     * Stored selections that no longer resolve to a selectable group/category on
     * the target, per selection key. Provider churn (standard and merged targets)
     * or tag deletion/re-tagging (custom targets) makes entries stale; they are
     * kept, never auto-pruned - this powers the UI staleness callout and the
     * explicit cleanup action only. Entries keep their stored shape: bare names
     * for standard/custom targets, {playlist_id, name} pairs for merged targets.
     *
     * @return array<string, array<int, string|array{playlist_id: int, name: string}>>
     */
    public function staleSelectionsByKey(): array
    {
        $selections = $this->group_selections ?? [];
        $stale = [];

        if ($this->merged_playlist_id) {
            $this->collectStaleMergedPairs('selected_groups', 'live', $selections, $stale);
            $this->collectStaleMergedPairs('selected_vod_groups', 'vod', $selections, $stale);
            $this->collectStaleMergedPairs('selected_categories', 'category', $selections, $stale);

            return $stale;
        }

        $resolve = function (string $key, callable $resolvableFor) use ($selections, &$stale): void {
            $names = $selections[$key] ?? [];
            if (empty($names)) {
                return;
            }
            $missing = array_values(array_diff($names, $resolvableFor($names)));
            if (! empty($missing)) {
                $stale[$key] = $missing;
            }
        };

        if ($this->playlist_id) {
            $resolve('selected_groups', fn (array $names) => SourceGroup::where('playlist_id', $this->playlist_id)
                ->where('type', 'live')->whereIn('name', $names)->pluck('name')->all());
            $resolve('selected_vod_groups', fn (array $names) => SourceGroup::where('playlist_id', $this->playlist_id)
                ->where('type', 'vod')->whereIn('name', $names)->pluck('name')->all());
            $resolve('selected_categories', fn (array $names) => SourceCategory::where('playlist_id', $this->playlist_id)
                ->whereIn('name', $names)->pluck('name')->all());
        } elseif ($this->custom_playlist_id && $this->customPlaylist) {
            $resolve('selected_groups', fn (array $names) => $this->customPlaylist->filterableGroupsQuery(false)
                ->whereIn('name', $names)->pluck('name')->all());
            $resolve('selected_vod_groups', fn (array $names) => $this->customPlaylist->filterableGroupsQuery(true)
                ->whereIn('name', $names)->pluck('name')->all());
            $resolve('selected_categories', fn (array $names) => $this->customPlaylist->filterableCategoriesQuery()
                ->whereIn('name', $names)->pluck('name')->all());
        }

        return $stale;
    }

    /**
     * Collect the {playlist_id, name} pairs of a merged-target selection key that
     * no longer match a source group / category, grouped by playlist for one query.
     *
     * @param  array<string, mixed>  $selections
     * @param  array<string, array<int, array{playlist_id: int, name: string}>>  $stale
     */
    private function collectStaleMergedPairs(string $key, string $type, array $selections, array &$stale): void
    {
        $pairs = PlaylistAlias::selectionPairs($selections[$key] ?? []);
        if (empty($pairs)) {
            return;
        }

        $namesByPlaylist = [];
        foreach ($pairs as $pair) {
            $namesByPlaylist[$pair['playlist_id']][] = $pair['name'];
        }

        $query = $type === 'category'
            ? SourceCategory::query()
            : SourceGroup::query()->where('type', $type);

        $resolvable = $query->where(function ($query) use ($namesByPlaylist): void {
            foreach ($namesByPlaylist as $playlistId => $names) {
                $query->orWhere(fn ($query) => $query->where('playlist_id', $playlistId)->whereIn('name', $names));
            }
        })->get(['playlist_id', 'name'])
            ->map(fn ($row) => ((int) $row->playlist_id).':'.$row->name)
            ->all();

        $missing = array_values(array_filter(
            $pairs,
            fn (array $pair) => ! in_array($pair['playlist_id'].':'.$pair['name'], $resolvable, true),
        ));

        if (! empty($missing)) {
            $stale[$key] = $missing;
        }
    }

    /**
     * Flattened unique stale names for display (pair entries reduced to names).
     *
     * @return array<string>
     */
    public function staleSelectionNames(): array
    {
        $entries = array_merge(...array_values($this->staleSelectionsByKey()) ?: [[]]);

        return PlaylistAlias::selectionNames($entries);
    }

    /**
     * Remove stale entries per key (a name stale for live but valid for VOD is
     * only removed from the live list). Shape-aware: a merged target's pairs are
     * removed by exact {playlist_id, name} match.
     */
    public function removeStaleSelectionNames(): void
    {
        $staleByKey = $this->staleSelectionsByKey();
        if ($staleByKey === []) {
            return;
        }

        $selections = $this->group_selections ?? [];
        foreach ($staleByKey as $key => $staleEntries) {
            $current = $selections[$key] ?? [];

            if ($this->merged_playlist_id) {
                $staleTokens = array_map(
                    fn (array $pair) => $pair['playlist_id'].':'.$pair['name'],
                    $staleEntries,
                );
                $selections[$key] = array_values(array_filter(
                    PlaylistAlias::selectionPairs($current),
                    fn (array $pair) => ! in_array($pair['playlist_id'].':'.$pair['name'], $staleTokens, true),
                ));
            } else {
                $selections[$key] = array_values(array_diff($current, $staleEntries));
            }
        }

        $this->update(['group_selections' => $selections]);
    }
}
