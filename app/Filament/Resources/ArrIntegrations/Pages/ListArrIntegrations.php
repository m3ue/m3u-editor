<?php

namespace App\Filament\Resources\ArrIntegrations\Pages;

use App\Filament\Resources\ArrIntegrations\ArrIntegrationResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListArrIntegrations extends ListRecords
{
    protected static string $resource = ArrIntegrationResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Connect your Sonarr (TV) and Radarr (Movies) servers to request content, and optionally to cache titles through them.');
    }
}
