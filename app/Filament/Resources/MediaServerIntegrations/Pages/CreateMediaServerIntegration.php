<?php

namespace App\Filament\Resources\MediaServerIntegrations\Pages;

use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateMediaServerIntegration extends CreateRecord
{
    protected static string $resource = MediaServerIntegrationResource::class;

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
