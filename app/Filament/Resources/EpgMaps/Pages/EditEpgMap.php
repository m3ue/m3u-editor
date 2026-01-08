<?php

namespace App\Filament\Resources\EpgMaps\Pages;

use App\Filament\Resources\EpgMaps\EpgMapResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEpgMap extends EditRecord
{
    protected static string $resource = EpgMapResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
