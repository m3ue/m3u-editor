<?php

namespace App\Filament\Resources\MediaServerIntegrations\Pages;

use App\Filament\Concerns\RefreshesOnSyncActivity;
use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListMediaServerIntegrations extends ListRecords
{
    use RefreshesOnSyncActivity;

    protected static string $resource = MediaServerIntegrationResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Connect media servers (Emby, Jellyfin, Plex), local media libraries, and WebDAV shares.');
    }

    /**
     * Media server integrations track sync state as a plain string, not the Status enum.
     */
    protected function scopeActiveSyncs(Builder $query): Builder
    {
        return $query->where('status', 'processing');
    }
}
