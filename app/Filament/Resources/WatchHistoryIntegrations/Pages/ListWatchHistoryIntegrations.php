<?php

namespace App\Filament\Resources\WatchHistoryIntegrations\Pages;

use App\Filament\Resources\WatchHistoryIntegrations\WatchHistoryIntegrationResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListWatchHistoryIntegrations extends ListRecords
{
    protected static string $resource = WatchHistoryIntegrationResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Send watch progress from Playlist Auths to Trakt, SIMKL or MDBList, and import or export existing watch history.');
    }
}
