<?php

namespace App\Filament\Resources\EpgChannels\Pages;

use App\Filament\Resources\EpgChannels\EpgChannelResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEpgChannel extends EditRecord
{
    protected static string $resource = EpgChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
