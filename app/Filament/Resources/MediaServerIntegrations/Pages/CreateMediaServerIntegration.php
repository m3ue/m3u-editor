<?php

namespace App\Filament\Resources\MediaServerIntegrations\Pages;

use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Illuminate\Support\Facades\Auth;

class CreateMediaServerIntegration extends CreateRecord
{
    use HasWizard;

    protected static string $resource = MediaServerIntegrationResource::class;

    protected function getSteps(): array
    {
        return MediaServerIntegrationResource::getFormSteps();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = Auth::id();
        $data['status'] = 'processing'; // Set initial status to processing

        // Local media integrations don't need network fields
        if (($data['type'] ?? '') === 'local') {
            $data['host'] = $data['host'] ?? null;
            $data['api_key'] = $data['api_key'] ?? null;
            $data['port'] = $data['port'] ?? 0;
            $data['ssl'] = $data['ssl'] ?? false;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
