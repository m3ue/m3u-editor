<?php

namespace App\Filament\Resources\MediaServerIntegrations\Pages;

use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMediaServerIntegrations extends ListRecords
{
    protected static string $resource = MediaServerIntegrationResource::class;

    protected ?string $subheading = 'Access your media server content directly within M3U Editor by integrating with popular media servers like Emby and Jellyfin. An associated playlist will be automatically created for each integration to manage content like you would for any other playlist.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add Media Server'),
        ];
    }
}
