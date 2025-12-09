<?php

namespace App\Filament\Resources\Groups\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\Groups\GroupResource;
use App\Models\Group;
use App\Models\Playlist;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListGroups extends ListRecords
{
    protected static string $resource = GroupResource::class;

    protected ?string $subheading = 'Manage live groups.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data, string $model): Model {
                    $data['user_id'] = auth()->id();
                    $data['custom'] = true;
                    $data['type'] = 'live';

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
            ->where('type', 'live');
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
                    ['type', 'live'],
                ]))
                ->badge($playlist->liveGroups()->count())
        ])->toArray();
    }
}
