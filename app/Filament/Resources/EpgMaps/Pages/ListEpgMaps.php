<?php

namespace App\Filament\Resources\EpgMaps\Pages;

use App\Filament\Resources\EpgMaps\EpgMapResource;
use App\Jobs\MapPlaylistChannelsToEpg;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;

class ListEpgMaps extends ListRecords
{
    protected static string $resource = EpgMapResource::class;

    protected ?string $subheading = 'View the EPG channel mapping jobs and progress here.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('map')
                ->label('Map EPG to Playlist')
                ->schema(EpgMapResource::getForm())
                ->action(function (array $data): void {
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new MapPlaylistChannelsToEpg(
                            epg: (int) $data['epg_id'],
                            playlist: $data['playlist_id'],
                            force: $data['override'],
                            recurring: $data['recurring'],
                            settings: $data['settings'] ?? [],
                        ));
                })->after(function () {
                    Notification::make()
                        ->success()
                        ->title('EPG to Channel mapping')
                        ->body('Channel mapping started, you will be notified when the process is complete.')
                        ->send();
                })
                ->requiresConfirmation()
                ->icon('heroicon-o-link')
                ->modalIcon('heroicon-o-link')
                ->modalWidth(Width::FourExtraLarge)
                ->modalDescription('Map the selected EPG to the selected Playlist channels.')
                ->modalSubmitActionLabel('Map now'),
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id())
            ->orderBy('created_at', 'desc');
    }
}
