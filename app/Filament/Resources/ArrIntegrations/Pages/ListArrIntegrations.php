<?php

namespace App\Filament\Resources\ArrIntegrations\Pages;

use App\Filament\Resources\ArrIntegrations\ArrIntegrationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListArrIntegrations extends ListRecords
{
    protected static string $resource = ArrIntegrationResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Connect your existing Sonarr (TV) and Radarr (Movies) servers to search and request content directly from m3u-editor. One integration per (playlist, server) is supported.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Add Sonarr/Radarr')),
        ];
    }
}
