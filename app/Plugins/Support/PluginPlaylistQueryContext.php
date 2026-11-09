<?php

namespace App\Plugins\Support;

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;

final readonly class PluginPlaylistQueryContext
{
    public object $playlist;

    public ?object $playlistAuth;

    /** @param array<string, mixed> $settings */
    public function __construct(
        Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias $playlist,
        ?PlaylistAuth $playlistAuth,
        public array $settings,
    ) {
        $this->playlist = (object) [
            'id' => $playlist->id,
            'type' => $playlist::class,
            'user_id' => $playlist->user_id,
        ];
        $this->playlistAuth = $playlistAuth ? (object) [
            'id' => $playlistAuth->id,
            'user_id' => $playlistAuth->user_id,
        ] : null;
    }
}
