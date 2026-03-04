<?php

namespace App\Filament\Resources\Playlists\Pages;

use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use App\Filament\Resources\Networks\NetworkResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\Playlists\Widgets\ImportProgress;
use App\Models\Playlist;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPlaylist extends EditRecord
{
    // use EditRecord\Concerns\HasWizard;

    protected static string $resource = PlaylistResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var Playlist $playlist */
        $playlist = $this->getRecord();

        // If this playlist belongs to a media server integration, redirect to edit that instead
        if ($integration = $playlist->mediaServerIntegration) {
            $this->redirect(MediaServerIntegrationResource::getUrl('edit', ['record' => $integration->id]));

            return;
        }

        // If this playlist has networks (is a network playlist), redirect to the networks list
        if ($playlist->networks()->exists()) {
            $this->redirect(NetworkResource::getUrl('index'));

            return;
        }
    }

    public function hasSkippableSteps(): bool
    {
        return true;
    }

    public function getVisibleHeaderWidgets(): array
    {
        return [
            ImportProgress::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('View Playlist')
                ->icon('heroicon-m-eye')
                ->color('gray')
                ->action(function () {
                    $this->redirect($this->getRecord()->getUrl('view'));
                }),
            ...PlaylistResource::getHeaderActions(),
        ];
    }

    protected function getSteps(): array
    {
        return PlaylistResource::getFormSteps();
    }
}
