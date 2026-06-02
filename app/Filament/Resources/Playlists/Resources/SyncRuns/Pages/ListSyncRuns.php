<?php

namespace App\Filament\Resources\Playlists\Resources\SyncRuns\Pages;

use App\Filament\Resources\Playlists\Resources\SyncRuns\SyncRunResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSyncRuns extends ListRecords
{
    protected static string $resource = SyncRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_playlist')
                ->label(__('Back to Playlist'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(function (): string {
                    return "/playlists/{$this->getParentRecord()->id}";
                }),
        ];
    }

    public function getTitle(): string
    {
        $playlist = $this->getParentRecord();

        return "Sync Runs for {$playlist->name}";
    }
}
