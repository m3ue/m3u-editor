<?php

namespace App\Filament\Resources\VodGroups\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\VodGroups\VodGroupResource;
use App\Models\Group;
use App\Models\Playlist;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListVodGroups extends ListRecords
{
    protected static string $resource = VodGroupResource::class;

    protected ?string $subheading = 'Manage VOD groups.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data, string $model): Model {
                    $data['user_id'] = auth()->id();
                    $data['custom'] = true;
                    $data['type'] = 'vod';

                    return $model::create($data);
                })
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title('Group created')
                        ->body('You can now assign channels to this group from the Channels section.'),
                )->slideOver()
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id())
            ->where('type', 'vod');
    }

    public function getTabs(): array
    {
        return self::setupTabs();
    }

    public static function setupTabs($relationId = null): array
    {
        $where = [
            ['user_id', auth()->id()],
        ];

        // Fetch all the playlists for the current user, these will be our grouping tabs
        $playlists = Playlist::where($where)
            ->orderBy('name')
            ->get();

        // Return tabs
        return $playlists->mapWithKeys(fn($playlist) => [
            $playlist->id => Tab::make($playlist->name)
                ->modifyQueryUsing(fn($query) => $query->where([
                    ['playlist_id', $playlist->id],
                    ['type', 'vod']
                ]))
                ->badge($playlist->vodGroups()->count())
        ])->toArray();
    }
}
