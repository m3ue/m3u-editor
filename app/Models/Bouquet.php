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

    public function playlistAliases(): BelongsToMany
    {
        return $this->belongsToMany(PlaylistAlias::class, 'bouquet_playlist_alias')
            ->using(BouquetPlaylistAlias::class);
    }

    /**
     * @return array<string>
     */
    public function getSelectedLiveGroupNames(): array
    {
        return $this->group_selections['selected_groups'] ?? [];
    }

    /**
     * @return array<string>
     */
    public function getSelectedVodGroupNames(): array
    {
        return $this->group_selections['selected_vod_groups'] ?? [];
    }

    /**
     * @return array<string>
     */
    public function getSelectedCategoryNames(): array
    {
        return $this->group_selections['selected_categories'] ?? [];
    }

    /**
     * Rewrite provider group renames (old name => new name) into every
     * standard-target bouquet of the playlist. Called from the sync pipeline's
     * rename-detection pass, the same one that already rewrites import_prefs, so
     * bouquets keep matching within the same sync. Saves through Eloquent so the
     * EPG-cache invalidation hook fires for attached aliases.
     *
     * @param  array<string, string>  $renames
     */
    public static function applyProviderRenames(int $playlistId, string $type, array $renames): void
    {
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
    }

    /**
     * Append newly-appeared provider group names to bouquets that opted in via
     * the per-type auto-include flag. Custom-target bouquets are structurally
     * excluded (playlist_id is NULL).
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
    }

    /**
     * Stored names that no longer resolve to a selectable group/category on the
     * target playlist, per selection key. Provider churn (standard targets) or
     * tag deletion/re-tagging (custom targets) makes entries stale; they are
     * kept, never auto-pruned - this powers the UI staleness callout and the
     * explicit cleanup action only.
     *
     * @return array<string, array<string>>
     */
    public function staleSelectionsByKey(): array
    {
        $selections = $this->group_selections ?? [];
        $stale = [];

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
     * Flattened unique stale names for display.
     *
     * @return array<string>
     */
    public function staleSelectionNames(): array
    {
        return array_values(array_unique(array_merge(...array_values($this->staleSelectionsByKey()) ?: [[]])));
    }

    /**
     * Remove stale entries per key (a name stale for live but valid for VOD is
     * only removed from the live list).
     */
    public function removeStaleSelectionNames(): void
    {
        $staleByKey = $this->staleSelectionsByKey();
        if ($staleByKey === []) {
            return;
        }

        $selections = $this->group_selections ?? [];
        foreach ($staleByKey as $key => $staleNames) {
            $selections[$key] = array_values(array_diff($selections[$key] ?? [], $staleNames));
        }

        $this->update(['group_selections' => $selections]);
    }
}
