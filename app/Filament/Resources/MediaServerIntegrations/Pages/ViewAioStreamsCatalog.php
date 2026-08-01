<?php

namespace App\Filament\Resources\MediaServerIntegrations\Pages;

use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewAioStreamsCatalog extends ViewRecord
{
    protected static string $resource = MediaServerIntegrationResource::class;

    protected string $view = 'filament.resources.media-server-integrations.pages.view-aiostreams-catalog';

    public function getTitle(): string|Htmlable
    {
        return $this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('Back to Media Server'))
                ->url(MediaServerIntegrationResource::getUrl('edit', ['record' => $this->record]))
                ->icon('heroicon-s-arrow-left')
                ->color('gray'),
        ];
    }
}
