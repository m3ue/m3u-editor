<?php

namespace App\Filament\Resources\Playlists\Resources\PlaylistSyncStatuses\Pages;

use App\Filament\Resources\Playlists\Resources\PlaylistSyncStatuses\PlaylistSyncStatusResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlaylistSyncStatuses extends ListRecords
{
    protected static string $resource = PlaylistSyncStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_playlist')
                ->label('Back to Playlist')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(function (): string {
                    return "/playlists/{$this->getParentRecord()->id}";
                }),
            // Sync statuses are typically created automatically by the system
            // Actions\CreateAction::make(),
        ];
    }

    public function getTitle(): string
    {
        $playlist = $this->getParentRecord();

        return "Sync Logs for {$playlist->name}";
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }
}
