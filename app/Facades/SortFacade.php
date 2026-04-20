<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static null bulkSortGroupChannels(\App\Models\Group $record, string $order = 'ASC', ?string $column = null)
 * @method static null bulkRecountGroupChannels(\App\Models\Group $record, int $start = 1)
 * @method static null bulkRecountChannels(\Illuminate\Database\Eloquent\Collection $channels, int $start = 1)
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
