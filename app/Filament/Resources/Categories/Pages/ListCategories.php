<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Models\Playlist;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCategories extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    protected ?string $subheading = 'Manage series categories. Only enabled series will be automatically updated on Playlist sync, this includes fetching episodes and metadata. You can also manually sync series to update episodes and metadata.';

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
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
        return $playlists->mapWithKeys(fn ($playlist) => [
            $playlist->id => Tab::make($playlist->name)
                ->modifyQueryUsing(fn ($query) => $query->where('playlist_id', $playlist->id))
                ->badge($playlist->categories()->count()),
        ])->toArray();
    }
}
