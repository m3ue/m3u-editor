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
}
