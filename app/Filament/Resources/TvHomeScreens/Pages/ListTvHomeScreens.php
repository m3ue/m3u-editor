<?php

namespace App\Filament\Resources\TvHomeScreens\Pages;

use App\Filament\Resources\TvHomeScreens\TvHomeScreenResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListTvHomeScreens extends ListRecords
{
    protected static string $resource = TvHomeScreenResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Lay out M3U TV\'s nav and Home for a playlist: the order of the sections, which show, and extra rows like a seasonal category or a sports league. Every login to the playlist gets it, Playlist Auths included. Playlists without one keep the app\'s own layout.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('New Home Screen'))
                ->slideOver(),
        ];
    }
}
