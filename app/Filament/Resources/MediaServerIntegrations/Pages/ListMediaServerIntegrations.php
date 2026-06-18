<?php

namespace App\Filament\Resources\MediaServerIntegrations\Pages;

use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use App\Filament\Resources\MediaServerIntegrations\Widgets\ArrIntegrationsWidget;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListMediaServerIntegrations extends ListRecords
{
    protected static string $resource = MediaServerIntegrationResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Connect media servers (Emby, Jellyfin, Plex), local media libraries, WebDAV shares, and download servers (Sonarr, Radarr) — all from one place.');
    }

    protected function getFooterWidgets(): array
    {
        return [
            ArrIntegrationsWidget::class,
        ];
    }
}
