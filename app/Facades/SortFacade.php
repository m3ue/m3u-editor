<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static null bulkSortGroupChannels(\App\Models\Group $record, string $order = 'ASC', ?string $column = null)
 * @method static null bulkSortGroupChannelsByReleaseDate(\App\Models\Group $record, string $order = 'ASC')
 * @method static null bulkSortGroupChannelsByRating(\App\Models\Group $record, string $order = 'DESC')
 * @method static null bulkSortCategorySeriesByReleaseDate(\App\Models\Category $record, string $order = 'ASC')
 * @method static null bulkSortCategorySeriesByRating(\App\Models\Category $record, string $order = 'DESC')
 * @method static null bulkSortPlaylistVodByReleaseDate(\App\Models\Playlist $playlist, string $order = 'DESC')
 * @method static null bulkSortPlaylistVodByRating(\App\Models\Playlist $playlist, string $order = 'DESC')
 * @method static null bulkSortPlaylistSeriesByReleaseDate(\App\Models\Playlist $playlist, string $order = 'DESC')
 * @method static null bulkSortPlaylistSeriesByRating(\App\Models\Playlist $playlist, string $order = 'DESC')
 * @method static null bulkRecountGroupChannels(\App\Models\Group $record, int $start = 1, bool $activeOnly = false)
 * @method static null bulkRecountGroupsByOrder(\Illuminate\Database\Eloquent\Collection $groups, int $start = 1, bool $activeOnly = false)
 * @method static null bulkRecountChannels(\Illuminate\Database\Eloquent\Collection $channels, $start = 1)
 * @method static null bulkRecountCustomPlaylistChannels(\App\Models\CustomPlaylist $playlist, \Illuminate\Database\Eloquent\Collection $channels, int $start = 1)
 * @method static null bulkSortAlphaCustomPlaylistChannels(\App\Models\CustomPlaylist $playlist, \Illuminate\Database\Eloquent\Collection $channels, string $order = 'ASC', string $column = 'title')
 */
class SortFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'sort';
    }
}
