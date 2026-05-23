<?php

namespace App\Filament\GuestPanel\Resources\DvrRecordings\Pages;

use App\Filament\GuestPanel\Pages\Concerns\HasPlaylist;
use App\Filament\GuestPanel\Resources\DvrRecordings\GuestDvrRecordingResource;
use App\Filament\GuestPanel\Widgets\GuestScheduledSeriesWidget;
use Filament\Resources\Pages\ListRecords;

class ListGuestDvrRecordings extends ListRecords
{
    use HasPlaylist;

    protected static string $resource = GuestDvrRecordingResource::class;

    protected static ?string $title = '';

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            GuestScheduledSeriesWidget::class,
        ];
    }
}
