<?php

namespace App\Filament\Resources\AedProfiles\Pages;

use App\Filament\Resources\AedProfiles\AedProfileResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAedProfile extends CreateRecord
{
    protected static string $resource = AedProfileResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }
}
