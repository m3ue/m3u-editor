<?php

namespace App\Filament\Resources\Networks\Pages;

use App\Filament\Resources\Networks\NetworkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNetworks extends ListRecords
{
    protected static string $resource = NetworkResource::class;

    protected ?string $subheading = 'Networks are pseudo-TV channels built from media server content (Jellyfin/Emby/etc.) You can create M3U playlists and stream them to your IPTV apps and devices. A media server is required to use networks.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Create Network'),
        ];
    }
}
