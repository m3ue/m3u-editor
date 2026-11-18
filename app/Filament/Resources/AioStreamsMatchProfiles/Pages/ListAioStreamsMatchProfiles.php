<?php

namespace App\Filament\Resources\AioStreamsMatchProfiles\Pages;

use App\Filament\Resources\AioStreamsMatchProfiles\AioStreamsMatchProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListAioStreamsMatchProfiles extends ListRecords
{
    protected static string $resource = AioStreamsMatchProfileResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Profiles control which streams AIOStreams auto-selection picks for a title, and in what order. Assign them as the movie and series defaults on an AIOStreams integration, or to a single title from its rescan dialog. Titles without a profile use the built-in ranking.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('New Profile')),
        ];
    }
}
