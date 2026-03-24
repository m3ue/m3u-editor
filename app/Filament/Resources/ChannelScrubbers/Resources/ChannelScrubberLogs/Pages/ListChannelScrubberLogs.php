<?php

namespace App\Filament\Resources\ChannelScrubbers\Resources\ChannelScrubberLogs\Pages;

use App\Filament\Resources\ChannelScrubbers\ChannelScrubberResource;
use App\Filament\Resources\ChannelScrubbers\Resources\ChannelScrubberLogs\ChannelScrubberLogResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListChannelScrubberLogs extends ListRecords
{
    protected static string $resource = ChannelScrubberLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back to Scrubbers')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn () => ChannelScrubberResource::getIndexUrl()),
        ];
    }

    public function getTitle(): string
    {
        $scrubber = $this->getParentRecord();

        return "Scrubber Logs for {$scrubber->name}";
    }
}
