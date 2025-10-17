<?php

namespace App\Enums;

enum PlaylistSourceType: string
{
    case Xtream = 'xtream';
    case Emby = 'emby';
    case M3u = 'm3u';
    case Local = 'local';
}