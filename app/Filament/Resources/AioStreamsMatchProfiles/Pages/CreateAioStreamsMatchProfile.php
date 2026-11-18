<?php

namespace App\Filament\Resources\AioStreamsMatchProfiles\Pages;

use App\Filament\Resources\AioStreamsMatchProfiles\AioStreamsMatchProfileResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAioStreamsMatchProfile extends CreateRecord
{
    protected static string $resource = AioStreamsMatchProfileResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }
}
