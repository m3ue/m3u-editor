<?php

namespace App\Filament\Resources\Playlists\Pages;

use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\Playlists\Widgets\LatestSyncRun;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewPlaylist extends ViewRecord
{
    protected static string $resource = PlaylistResource::class;

    protected function getFooterWidgets(): array
    {
        return [
            LatestSyncRun::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label(__('Edit Playlist'))
                ->icon('heroicon-m-pencil')
                ->color('gray')
                ->action(function () {
                    $this->redirect($this->getRecord()->getUrl('edit'));
                }),
            Action::make('view_sync_runs')
                ->label(__('Sync History'))
                ->color('gray')
                ->icon('heroicon-m-arrows-right-left')
                ->url(fn (): string => "/playlists/{$this->getRecord()->id}/playlist-sync-runs"),
            Action::make('view_sync_logs')
                ->label(__('View Sync Logs'))
                ->color('gray')
                ->icon('heroicon-m-document-text')
                ->url(function (): string {
                    return "/playlists/{$this->getRecord()->id}/playlist-sync-statuses";
                }),
            // ...PlaylistResource::getHeaderActions()
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $record = $this->getRecord();
        $record->loadCount('enabled_channels');

        return PlaylistResource::infolist($schema);
    }
}
