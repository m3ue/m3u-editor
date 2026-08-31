<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\Series;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for mapping between m3u-editor groups/categories and the
 * flat category list the Xtream API exposes for a standard Playlist,
 * MergedPlaylist or PlaylistAlias.
 *
 * This folds merged groups/categories (see Group/Category `is_merged` +
 * `parent_id`) into their parent for both the category listings and the
 * stream-filter/echo paths, and projects a source playlist's TMDB-derived
 * DynamicGroup rows (see DynamicGroup::XTREAM_CATEGORY_ID_OFFSET) into the same
 * surface. Any future feature that adds more "virtual" categories should extend
 * the methods here rather than re-branching inside XtreamApiController, so the
 * listing/filter/echo call sites stay in sync.
 *
 * CustomPlaylist categories are tag-driven and unrelated; that branch stays in
 * the controller.
 */
class XtreamCategoryService
{
    /**
     * Xtream category entries for a standard playlist's live or VOD list. A
     * merged group stands in for its children (which never appear on their own),
     * and a child group's channels are what make the parent category eligible.
     *
     * @param  Playlist|MergedPlaylist|PlaylistAlias  $playlist
     * @param  array<int, string>  $aliasGroupFilter  provider group names an alias is limited to
     * @return array<int, array{category_id: string, category_name: string, parent_id: int}>
     */
    public static function groupCategories($playlist, bool $isVod, array $aliasGroupFilter = []): array
    {
        $channelConstraint = function ($query) use ($isVod, $aliasGroupFilter): void {
            $query->where('enabled', true)->where('is_vod', $isVod);
            if (! empty($aliasGroupFilter)) {
                $query->whereIn('group_internal', $aliasGroupFilter);
            }
        };

        $groups = $playlist->groups()
            ->with('parent')
            ->where(function ($query) use ($channelConstraint): void {
                $query->whereHas('channels', $channelConstraint)
                    ->orWhereHas('children.channels', $channelConstraint);
            })
            ->get();

        $categories = [];
        foreach ($groups as $group) {
            $id = (string) ($group->parent_id ?? $group->id);
            if (isset($categories[$id])) {
                continue;
            }
            $categories[$id] = [
                'category_id' => $id,
                'category_name' => $group->parent?->name ?? $group->name,
                'parent_id' => 0,
                '_sort' => $group->parent?->sort_order ?? $group->sort_order ?? 999999,
            ];
        }

        return self::sortAndStripSortKey(array_values($categories));
    }

    /**
     * Xtream category entries for a standard playlist's series list. A merged
     * category stands in for its children, which never appear alone.
     *
     * @param  Playlist|MergedPlaylist|PlaylistAlias  $playlist
     * @return array<int, array{category_id: string, category_name: string, parent_id: int}>
     */
    public static function seriesCategories($playlist): array
    {
        $categories = $playlist->series()
            ->where('enabled', true)
            ->with('category.parent')
            ->get()
            ->pluck('category')
            ->filter()
            ->map(fn (Category $category): array => [
                'category_id' => (string) ($category->parent_id ?? $category->id),
                'category_name' => $category->parent?->name ?? $category->name,
                'parent_id' => 0,
                '_sort' => $category->parent?->sort_order ?? $category->sort_order ?? 999999,
            ])
            ->unique('category_id')
            ->values()
            ->all();

        return self::sortAndStripSortKey($categories);
    }

    /**
     * Every group id a requested live/VOD category_id resolves to: the id itself
     * plus, when it is a merged group, every child folded into it.
     *
     * @return array<int, int|string>
     */
    public static function resolveGroupFilterIds(int|string $categoryId): array
    {
        return array_merge(
            [$categoryId],
            Group::query()->where('parent_id', $categoryId)->pluck('id')->all(),
        );
    }

    /**
     * The series-category equivalent of {@see resolveGroupFilterIds()}: a merged
     * category id also resolves to every child category folded into it.
     *
     * @return array<int, int|string>
     */
    public static function resolveSeriesCategoryFilterIds(int|string $categoryId): array
    {
        return array_merge(
            [$categoryId],
            Category::query()->where('parent_id', $categoryId)->pluck('id')->all(),
        );
    }

    /**
     * The category_id a live/VOD stream row should report for a standard
     * playlist: the merged group's id when the channel's group is folded into
     * one, otherwise its own group_id, falling back to 'all'.
     *
     * Relies on the `merged_group_id` column projected by
     * PlaylistGenerateController::getChannelQuery().
     */
    public static function channelStreamCategoryId(object $channel): string
    {
        $id = $channel->merged_group_id ?? $channel->group_id ?? null;

        return $id !== null ? (string) $id : 'all';
    }

    /**
     * The category_id a series row should report for a standard playlist: the
     * merged category's id when the series' category is folded into one,
     * otherwise its own category_id, falling back to 'all'. Requires the
     * `category.parent` relation to be eager-loaded on the series.
     */
    public static function seriesStreamCategoryId(object $seriesItem): string
    {
        $id = $seriesItem->category?->parent_id ?? $seriesItem->category_id ?? null;

        return $id !== null ? (string) $id : 'all';
    }

    /**
     * Prepend a source playlist's enabled TMDB dynamic-group categories to an
     * already-built Xtream category list.
     *
     * No-op unless the request resolves to a standalone Playlist (dynamic
     * groups live on a Playlist, so MergedPlaylist requests and aliases of one
     * get nothing) and no alias group/category filter is narrowing the surface
     * on purpose.
     *
     * @param  array<int, array{category_id: string, category_name: string, parent_id: int}>  $categories
     * @param  Playlist|MergedPlaylist|PlaylistAlias  $playlist
     * @param  array<int, string>  $aliasFilter  alias group/category filter for this content type
     * @return array<int, array{category_id: string, category_name: string, parent_id: int}>
     */
    public static function prependDynamicGroups(array $categories, $playlist, bool $isVod, array $aliasFilter = []): array
    {
        $source = self::dynamicGroupSource($playlist);

        if ($source === null || ! empty($aliasFilter)) {
            return $categories;
        }

        return array_merge(self::dynamicCategories($source, $isVod), $categories);
    }

    /**
     * Constrain a channels/series query to the members of one DynamicGroup,
     * via the polymorphic `dynamic_group_items` pivot. Used by the
     * get_vod_streams / get_series category filter when the requested
     * category_id decodes to a dynamic group (see
     * DynamicGroup::idFromXtreamCategoryId()).
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|Builder|Relation  $query
     */
    public static function applyDynamicGroupFilter($query, int $dynamicGroupId, bool $isVod): void
    {
        $table = $isVod ? 'channels' : 'series';
        $itemType = $isVod ? Channel::class : Series::class;

        $query->whereIn("{$table}.id", function ($sub) use ($dynamicGroupId, $itemType): void {
            $sub->select('item_id')
                ->from('dynamic_group_items')
                ->where('dynamic_group_id', $dynamicGroupId)
                ->where('item_type', $itemType);
        });
    }

    /**
     * Xtream-shaped category entries for a source playlist's enabled
     * TMDB-derived dynamic groups of the given content type. Groups with no
     * enabled members are dropped so the client never sees an empty category.
     *
     * The category_id is DynamicGroup::XTREAM_CATEGORY_ID_OFFSET + $dg->id, so
     * it can never collide with a real groups/categories PK;
     * DynamicGroup::idFromXtreamCategoryId() decodes it again on the stream
     * endpoints.
     *
     * @return array<int, array{category_id: string, category_name: string, parent_id: int}>
     */
    public static function dynamicCategories(Playlist $sourcePlaylist, bool $isVod): array
    {
        $relation = $isVod ? 'channels' : 'series';

        return DynamicGroup::query()
            ->where('playlist_id', $sourcePlaylist->id)
            ->where('type', $isVod ? 'vod' : 'series')
            ->where('enabled', true)
            ->whereHas($relation, function ($query): void {
                $query->where('enabled', true);
            })
            ->orderBy('sort_order')
            ->get()
            ->map(fn (DynamicGroup $dg): array => [
                'category_id' => (string) $dg->xtreamCategoryId(),
                'category_name' => $dg->name,
                'parent_id' => 0,
            ])
            ->all();
    }

    /**
     * Map item id => Xtream dynamic category ids for a source playlist's
     * enabled dynamic groups of the given type.
     *
     * Needed by the full get_vod_streams / get_series listings: most TV apps
     * fetch the whole list once and bucket streams client-side by
     * category_id/category_ids, so without the dynamic ids stamped onto the
     * member rows a dynamic category renders empty in those apps even though
     * the per-category request returns its streams. The map is bounded by the
     * membership table for one playlist (each group holds at most a few TMDB
     * pages worth of items).
     *
     * @return array<int, array<int, int>>
     */
    public static function dynamicCategoryIdsByItem(Playlist $sourcePlaylist, bool $isVod): array
    {
        $itemType = $isVod ? Channel::class : Series::class;

        $map = [];
        $rows = DB::table('dynamic_group_items')
            ->join('dynamic_groups', 'dynamic_groups.id', '=', 'dynamic_group_items.dynamic_group_id')
            ->where('dynamic_groups.playlist_id', $sourcePlaylist->id)
            ->where('dynamic_groups.type', $isVod ? 'vod' : 'series')
            ->where('dynamic_groups.enabled', true)
            ->where('dynamic_group_items.item_type', $itemType)
            ->select(['dynamic_group_items.item_id', 'dynamic_group_items.dynamic_group_id'])
            ->cursor();

        foreach ($rows as $row) {
            $map[(int) $row->item_id][] = DynamicGroup::XTREAM_CATEGORY_ID_OFFSET + (int) $row->dynamic_group_id;
        }

        return $map;
    }

    /**
     * The standalone Playlist a request's dynamic groups live on, or null when
     * there is none (MergedPlaylist request, or a PlaylistAlias that resolves
     * to one). Mirrors the $sourcePlaylist resolution in XtreamApiController.
     *
     * @param  Playlist|MergedPlaylist|PlaylistAlias  $playlist
     */
    private static function dynamicGroupSource($playlist): ?Playlist
    {
        if ($playlist instanceof Playlist) {
            return $playlist;
        }

        return $playlist instanceof PlaylistAlias ? $playlist->playlist : null;
    }

    /**
     * Order category entries by their resolved sort weight and drop the internal
     * `_sort` key used to carry it.
     *
     * @param  array<int, array{category_id: string, category_name: string, parent_id: int, _sort?: int}>  $categories
     * @return array<int, array{category_id: string, category_name: string, parent_id: int}>
     */
    private static function sortAndStripSortKey(array $categories): array
    {
        usort($categories, fn ($a, $b) => ($a['_sort'] ?? 999999) <=> ($b['_sort'] ?? 999999));

        return array_map(function (array $category): array {
            unset($category['_sort']);

            return $category;
        }, $categories);
    }
}
