<?php

namespace App\Filament\Resources\MediaServerIntegrations\Pages;

use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListMediaServerIntegrations extends ListRecords
{
    protected static string $resource = MediaServerIntegrationResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Connect media servers (Emby, Jellyfin, Plex), local media libraries, and WebDAV shares.');
    }
}
