<?php

namespace App\Filament\Resources\ArrIntegrations\Pages;

use App\Filament\Resources\ArrIntegrations\ArrIntegrationResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateArrIntegration extends CreateRecord
{
    protected static string $resource = ArrIntegrationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = Auth::id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
