<?php

namespace App\Services;

use App\Interfaces\MediaServer;
use App\Models\MediaServerIntegration;
use InvalidArgumentException;

class MediaServerService
{
    public static function make(MediaServerIntegration $integration): MediaServer
    {
        return match ($integration->type) {
            'emby', 'jellyfin' => new EmbyJellyfinService($integration),
            'plex' => new PlexService($integration),
            default => throw new InvalidArgumentException("Unsupported media server type: {$integration->type}"),
        };
    }
}
