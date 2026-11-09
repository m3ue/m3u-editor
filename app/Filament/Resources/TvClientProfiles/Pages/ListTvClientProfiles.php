<?php

namespace App\Filament\Resources\TvClientProfiles\Pages;

use App\Filament\Resources\TvClientProfiles\TvClientProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListTvClientProfiles extends ListRecords
{
    protected static string $resource = TvClientProfileResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Manage M3U TV app settings from here. Assign a profile to a Playlist Auth, or mark one as the default for every other login. Defaults can be changed by the user; enforced settings are locked in the app.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('New Profile'))
                ->slideOver(),
        ];
    }
}
