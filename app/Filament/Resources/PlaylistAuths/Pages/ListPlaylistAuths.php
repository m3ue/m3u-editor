<?php

namespace App\Filament\Resources\PlaylistAuths\Pages;

use App\Filament\Resources\PlaylistAuths\PlaylistAuthResource;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListPlaylistAuths extends ListRecords
{
    protected static string $resource = PlaylistAuthResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Create credentials and assign them to your Playlist for simple authentication. They can also be used to access the Xtream API for the assigned Playlists.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data, string $model): Model {
                    $assignedPlaylist = $data['assigned_playlist'] ?? null;
                    unset($data['assigned_playlist']);

                    $data['user_id'] = auth()->id();
                    $record = $model::create($data);

                    if ($assignedPlaylist) {
                        [$modelClass, $modelId] = explode('|', $assignedPlaylist, 2);
                        $playlistModel = $modelClass::find($modelId);
                        if ($playlistModel) {
                            $record->assignTo($playlistModel);
                        }
                    }

                    return $record;
                })
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('Playlist Auth created')),
                ),
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
    }
}
