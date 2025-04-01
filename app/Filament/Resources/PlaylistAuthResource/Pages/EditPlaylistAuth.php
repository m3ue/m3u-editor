<?php

namespace App\Filament\Resources\PlaylistAuthResource\Pages;

use App\Filament\Resources\PlaylistAuthResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPlaylistAuth extends EditRecord
{
    protected static string $resource = PlaylistAuthResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
