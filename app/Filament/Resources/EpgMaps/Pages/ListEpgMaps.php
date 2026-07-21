<?php

namespace App\Filament\Resources\EpgMaps\Pages;

use App\Filament\Resources\EpgMaps\EpgMapResource;
use App\Jobs\MapPlaylistChannelsToEpg;
use App\Models\Playlist;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListEpgMaps extends ListRecords
{
    protected static string $resource = EpgMapResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('View the EPG channel mapping jobs and progress here.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('map')
                ->label(__('Map EPG to Playlist'))
                ->schema(EpgMapResource::getForm())
                ->action(function (array $data): void {
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new MapPlaylistChannelsToEpg(
                            epg: (int) $data['epg_id'],
                            playlist: $data['playlist_id'],
                            groups: $data['group_ids'] ?? null,
                            force: $data['override'],
                            recurring: $data['recurring'],
                            settings: $data['settings'] ?? [],
                        ));
                })->after(function () {
                    Notification::make()
                        ->success()
                        ->title(__('EPG to Channel mapping'))
                        ->body(__('Channel mapping started, you will be notified when the process is complete.'))
                        ->send();
                })
                ->requiresConfirmation()
                ->icon('heroicon-o-link')
                ->modalIcon('heroicon-o-link')
                ->modalWidth(Width::FourExtraLarge)
                ->modalDescription(__('Map the selected EPG to the selected Playlist channels.'))
                ->modalSubmitActionLabel(__('Map now')),
            Action::make('unmap')
                ->label(__('Undo EPG Map'))
                ->schema([
                    Select::make('playlist_id')
                        ->label(__('Playlist'))
                        ->options(Playlist::where('user_id', auth()->id())->pluck('name', 'id'))
                        ->live()
                        ->required()
                        ->searchable()
                        ->helperText(text: 'Playlist to clear EPG mappings for.'),
                ])
                ->action(function (array $data): void {
                    $playlist = Playlist::find($data['playlist_id']);
                    $playlist->live_channels()->update(['epg_channel_id' => null]);
                })->after(function () {
                    Notification::make()
                        ->success()
                        ->title(__('EPG Channel mapping removed'))
                        ->body(__('Channel mapping removed for the selected Playlist.'))
                        ->send();
                })
                ->requiresConfirmation()
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->modalIcon('heroicon-o-arrow-uturn-left')
                ->modalDescription(__('Clear EPG mappings for all channels of the selected playlist.'))
                ->modalSubmitActionLabel(__('Reset now')),
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
