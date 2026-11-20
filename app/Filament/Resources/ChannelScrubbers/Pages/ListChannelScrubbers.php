<?php

namespace App\Filament\Resources\ChannelScrubbers\Pages;

use App\Filament\Concerns\RefreshesOnSyncActivity;
use App\Filament\Resources\ChannelScrubbers\ChannelScrubberResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListChannelScrubbers extends ListRecords
{
    use RefreshesOnSyncActivity;

    protected static string $resource = ChannelScrubberResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Scrubber tasks run after Playlist sync to check for dead URLs and automatically disable failing channels based on the configuration.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
