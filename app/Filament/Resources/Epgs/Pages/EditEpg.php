<?php

namespace App\Filament\Resources\Epgs\Pages;

use App\Filament\Resources\Epgs\EpgResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEpg extends EditRecord
{
    protected static string $resource = EpgResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
