<?php

namespace App\Enums;

enum PlaylistSourceType: string
{
    case Xtream = 'xtream';
    case M3u = 'm3u';
    case Local = 'local';
    case Emby = 'emby';
    case Jellyfin = 'jellyfin';
    case Plex = 'plex';
    case LocalMedia = 'local_media';
    case AIOStreams = 'aiostreams';

    public function getLabel(): string
    {
        return match ($this) {
            self::Xtream => __('Xtream'),
            self::M3u => __('M3U'),
            self::Local => __('Local File'),
            self::Emby => __('Emby'),
            self::Jellyfin => __('Jellyfin'),
            self::Plex => __('Plex'),
            self::LocalMedia => __('Local Media'),
            self::AIOStreams => __('AIOStreams'),
        };
    }
}
