<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;

/**
 * Single entry point for mapping between m3u-editor groups/categories and the
 * flat category list the Xtream API exposes for a standard Playlist,
 * MergedPlaylist or PlaylistAlias.
 *
 * Today this folds merged groups/categories (see Group/Category `is_merged` +
 * `parent_id`) into their parent for both the category listings and the
 * stream-filter/echo paths. Any future feature that projects extra "virtual"
 * categories into the Xtream surface (e.g. TMDB-derived dynamic groups) should
 * extend the methods here rather than re-branching inside XtreamApiController,
 * so the four listing/filter/echo call sites stay in sync.
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
