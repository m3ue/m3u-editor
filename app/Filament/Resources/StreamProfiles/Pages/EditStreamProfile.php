<?php

namespace App\Filament\Resources\StreamProfiles\Pages;

use App\Filament\Resources\StreamProfiles\StreamProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStreamProfile extends EditRecord
{
    protected static string $resource = StreamProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
