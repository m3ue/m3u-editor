<?php

namespace App\Filament\Resources\Playlists\Resources\PlaylistSyncRuns\Pages;

use App\Filament\Resources\Playlists\Resources\PlaylistSyncRuns\PlaylistSyncRunResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewPlaylistSyncRun extends ViewRecord
{
    protected static string $resource = PlaylistSyncRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_sync_runs')
                ->label(__('Back to Sync History'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => "/playlists/{$this->getParentRecord()->id}/playlist-sync-runs"),
        ];
    }
}
