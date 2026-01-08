<?php

namespace App\Filament\Resources\Playlists\Pages;

use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\Playlists\Widgets\ImportProgress;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPlaylist extends EditRecord
{
    // use EditRecord\Concerns\HasWizard;

    protected static string $resource = PlaylistResource::class;

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
