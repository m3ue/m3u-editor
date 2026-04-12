<?php

namespace App\Filament\Resources\DvrRecordings\Pages;

use App\Filament\Resources\DvrRecordings\DvrRecordingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDvrRecordings extends ListRecords
{
    protected static string $resource = DvrRecordingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
