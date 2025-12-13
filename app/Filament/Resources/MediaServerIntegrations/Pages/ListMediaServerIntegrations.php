<?php

namespace App\Filament\Resources\MediaServerIntegrations\Pages;

use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMediaServerIntegrations extends ListRecords
{
    protected static string $resource = MediaServerIntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add Media Server'),
        ];
    }
}
