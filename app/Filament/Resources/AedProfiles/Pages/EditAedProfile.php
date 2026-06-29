<?php

namespace App\Filament\Resources\AedProfiles\Pages;

use App\Filament\Resources\AedProfiles\AedProfileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAedProfile extends EditRecord
{
    protected static string $resource = AedProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
