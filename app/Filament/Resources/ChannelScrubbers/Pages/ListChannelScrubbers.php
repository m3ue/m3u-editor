<?php

namespace App\Filament\Resources\ChannelScrubbers\Pages;

use App\Filament\Resources\ChannelScrubbers\ChannelScrubberResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChannelScrubbers extends ListRecords
{
    protected static string $resource = ChannelScrubberResource::class;

    protected ?string $subheading = 'Scrubber tasks run after Playlist sync to check for dead URLs and automatically disable failing channels based on the configuration.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
