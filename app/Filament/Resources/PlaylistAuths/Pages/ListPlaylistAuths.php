<?php

namespace App\Filament\Resources\PlaylistAuths\Pages;

use App\Filament\Resources\PlaylistAuths\PlaylistAuthResource;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListPlaylistAuths extends ListRecords
{
    protected static string $resource = PlaylistAuthResource::class;

    protected ?string $subheading = 'Create credentials and assign them to your Playlist for simple authentication. They can also be used to access the Xtream API for the assigned Playlists.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data, string $model): Model {
                    $data['user_id'] = auth()->id();

                    return $model::create($data);
                })
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title('Playlist Auth created')
                        ->body('You can now assign Playlists to this Auth.'),
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
