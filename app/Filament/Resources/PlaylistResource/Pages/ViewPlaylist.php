<?php

namespace App\Filament\Resources\PlaylistResource\Pages;

use App\Filament\Resources\PlaylistResource;
use App\Filament\Resources\PlaylistResource\Widgets;
use App\Models\Playlist;
use Filament\Actions;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPlaylist extends ViewRecord
{
    protected static string $resource = PlaylistResource::class;

    public function getVisibleHeaderWidgets(): array
    {
        return [
            Widgets\ImportProgress::class
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->label('Edit Playlist')
                ->icon('heroicon-m-pencil')
                ->color('gray')
                ->action(function () {
                    $this->redirect($this->getRecord()->getUrl('edit'));
                }),
            Actions\Action::make('Sync Logs')
                ->label('Sync Logs')
                ->color('gray')
                ->icon('heroicon-m-arrows-right-left')
                ->url(
                    fn(): string => PlaylistResource::getUrl(
                        name: 'playlist-sync-statuses.index',
                        parameters: [
                            'parent' => $this->getRecord()->id,
                        ]
                    )
                ),
            //...PlaylistResource::getHeaderActions()
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        $record = $this->getRecord();
        $record->loadCount('enabled_channels');
        return PlaylistResource::infolist($infolist);
    }
}
