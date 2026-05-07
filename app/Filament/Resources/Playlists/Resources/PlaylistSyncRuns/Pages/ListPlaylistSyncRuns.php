<?php

namespace App\Filament\Resources\Playlists\Resources\PlaylistSyncRuns\Pages;

use App\Filament\Resources\Playlists\Resources\PlaylistSyncRuns\PlaylistSyncRunResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlaylistSyncRuns extends ListRecords
{
    protected static string $resource = PlaylistSyncRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_playlist')
                ->label(__('Back to Playlist'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => "/playlists/{$this->getParentRecord()->id}"),
        ];
    }

    public function getTitle(): string
    {
        $playlist = $this->getParentRecord();

        return "Sync History for {$playlist->name}";
    }
}
