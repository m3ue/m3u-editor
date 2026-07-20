<?php

namespace App\Filament\Resources\MergedPlaylists\Pages;

use App\Filament\Resources\MergedPlaylists\MergedPlaylistResource;
use App\Services\EpgCacheService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditMergedPlaylist extends EditRecord
{
    protected static string $resource = MergedPlaylistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label(__('View Playlist'))
                ->icon('heroicon-m-eye'),
            DeleteAction::make(),
        ];
    }

    public function clearEpgFileCache()
    {
        $cleared = EpgCacheService::clearPlaylistEpgCacheFile($this->record);
        if ($cleared) {
            Notification::make()
                ->title(__('EPG File Cache Cleared'))
                ->body(__('The EPG file cache has been successfully cleared.'))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(__('EPG File Cache Not Found'))
                ->body(__('No EPG cache files found.'))
                ->warning()
                ->send();
        }

        // Close the modal
        $this->dispatch('close-modal', id: 'epg-url-modal-'.$this->record->getKey());
    }
}
