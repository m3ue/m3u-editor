<?php

namespace App\Filament\Resources\ChannelScrubbers\Resources\ChannelScrubberLogs\Pages;

use App\Filament\Resources\ChannelScrubbers\Resources\ChannelScrubberLogs\ChannelScrubberLogResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewChannelScrubberLog extends ViewRecord
{
    protected static string $resource = ChannelScrubberLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back to Scrubber')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn () => "/channel-scrubbers/{$this->getParentRecord()->id}"),
            DeleteAction::make(),
        ];
    }
}
