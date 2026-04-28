<?php

namespace App\Filament\GuestPanel\Resources\DvrRecordings\Pages;

use App\Filament\GuestPanel\Pages\Concerns\HasPlaylist;
use App\Filament\GuestPanel\Resources\DvrRecordings\GuestDvrRecordingResource;
use Filament\Resources\Pages\ViewRecord;

class ViewGuestDvrRecording extends ViewRecord
{
    use HasPlaylist;

    protected static string $resource = GuestDvrRecordingResource::class;

    protected static ?string $title = '';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
