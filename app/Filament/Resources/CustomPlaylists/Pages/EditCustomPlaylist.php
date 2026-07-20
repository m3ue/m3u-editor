<?php

namespace App\Filament\Resources\CustomPlaylists\Pages;

use App\Filament\Concerns\HasDvrAndRequestFormHooks;
use App\Filament\Resources\CustomPlaylists\CustomPlaylistResource;
use App\Models\CustomPlaylist;
use App\Services\EpgCacheService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCustomPlaylist extends EditRecord
{
    use HasDvrAndRequestFormHooks;

    protected static string $resource = CustomPlaylistResource::class;

    /**
     * Populate dvr_/request_ prefixed fields from their owned relations.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var CustomPlaylist $record */
        $record = $this->getRecord();

        return $this->fillDvrAndRequestFormData($data, $record);
    }

    /**
     * Strip dvr_/request_ prefixed fields so Filament doesn't try to save them to the custom_playlists table.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->stripDvrAndRequestFormData($data);
    }

    /**
     * Save dvr_/request_ prefixed fields back to their respective owned relations.
     */
    protected function afterSave(): void
    {
        /** @var CustomPlaylist $record */
        $record = $this->getRecord();

        $this->saveDvrAndRequestFormData($record, $this->form->getRawState());
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label(__('View Playlist'))
                ->icon('heroicon-m-eye'),
            DeleteAction::make()
                ->icon('heroicon-m-trash'),
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
