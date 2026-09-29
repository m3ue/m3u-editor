<?php

namespace App\Http\Resources\User;

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Services\M3uProxyService;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Playlist, Custom Playlist, Merged Playlist or Playlist Alias with its channel counts.
 *
 * Playlists, Custom Playlists and Merged Playlists are expected to carry the counts from
 * UserController::channelCountScopes(). Playlist Aliases resolve their relations
 * dynamically from the playlist they point to, so their counts are queried here.
 *
 * @mixin Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias
 */
#[SchemaName('PlaylistSummary')]
class PlaylistSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $isAlias = $this->resource instanceof PlaylistAlias;
        $isStandardPlaylist = $this->resource instanceof Playlist;

        return [
            'name' => $this->name,
            'uuid' => $this->uuid,
            /** @var 'playlist'|'custom_playlist'|'merged_playlist'|'playlist_alias' */
            'type' => match (true) {
                $isStandardPlaylist => 'playlist',
                $this->resource instanceof CustomPlaylist => 'custom_playlist',
                $this->resource instanceof MergedPlaylist => 'merged_playlist',
                default => 'playlist_alias',
            },
            /** @var int */
            'total_channels' => $isAlias ? $this->channels()->count() : $this->channels_count,
            /** @var int */
            'enabled_channels' => $isAlias ? $this->enabled_channels()->count() : $this->enabled_channels_count,
            /** @var int */
            'live_channels' => $isAlias ? $this->channels()->where('channels.is_vod', false)->count() : $this->live_channels_count,
            /** @var int */
            'vod_channels' => $isAlias ? $this->channels()->where('channels.is_vod', true)->count() : $this->vod_channels_count,
            /** @var int */
            'groups_count' => $isAlias ? $this->groups()->count() : $this->groups_count,
            'proxy_enabled' => (bool) $this->enable_proxy,
            /** Active proxy streams (cached), or 0 when proxying is off. */
            'active_streams' => $this->activeStreamsCount(),
            /**
             * Last sync time. Standard playlists only, null otherwise.
             *
             * @var string|null
             *
             * @format date-time
             */
            'last_sync' => $isStandardPlaylist ? $this->synced?->toIso8601String() : null,
            /**
             * Sync status. Standard playlists only, null otherwise.
             *
             * @var string|null
             */
            'status' => $isStandardPlaylist ? ($this->status?->value ?? 'Unknown') : null,
            /**
             * Source type (e.g. `m3u`, `xtream`). Standard playlists only, null otherwise.
             *
             * @var string|null
             */
            'source_type' => $isStandardPlaylist ? ($this->source_type?->value ?? 'unknown') : null,
        ];
    }

    private function activeStreamsCount(): int
    {
        return $this->enable_proxy
            ? M3uProxyService::getCachedPlaylistActiveStreamsCount($this->resource, 5)
            : 0;
    }
}
