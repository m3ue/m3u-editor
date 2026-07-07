<?php

namespace App\Services;

use App\Interfaces\MediaServer;
use App\Models\MediaServerIntegration;
use InvalidArgumentException;
use App\Services\AIOStreamsService;

class MediaServerService
{
    public static function make(MediaServerIntegration $integration): MediaServer
    {
        return match ($integration->type) {
            'emby', 'jellyfin' => new EmbyJellyfinService($integration),
            'plex' => new PlexService($integration),
            'local' => new LocalMediaService($integration),
            'webdav' => new WebDavMediaService($integration),
            'aiostreams' => new AIOStreamsService($integration),
            default => throw new InvalidArgumentException("Unsupported media server type: {$integration->type}"),
        };
    }
}
