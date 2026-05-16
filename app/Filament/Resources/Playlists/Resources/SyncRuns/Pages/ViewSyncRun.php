<?php

namespace App\Filament\Resources\Playlists\Resources\SyncRuns\Pages;

use App\Filament\Resources\Playlists\Resources\SyncRuns\SyncRunResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSyncRun extends ViewRecord
{
    protected static string $resource = SyncRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_sync_runs')
                ->label(__('Back to Sync Runs'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(function (): string {
                    return "/playlists/{$this->getParentRecord()->id}/sync-runs";
                }),
        ];
    }
}
