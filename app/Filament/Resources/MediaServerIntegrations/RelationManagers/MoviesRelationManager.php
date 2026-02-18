<?php

namespace App\Filament\Resources\MediaServerIntegrations\RelationManagers;

use App\Filament\Resources\Vods\VodResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MoviesRelationManager extends RelationManager
{
    protected static string $relationship = 'channels';

    protected static ?string $label = 'Movies';

    protected static ?string $pluralLabel = 'Movies';

    protected static ?string $title = 'Movies';

    protected static ?string $navigationLabel = 'Movies';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTabComponent(Model $ownerRecord, string $pageClass): Tab
    {
        return Tab::make('Movies')
            ->badge($ownerRecord->channels()->where('is_vod', true)->count())
            ->icon('heroicon-m-film');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return VodResource::infolist($schema);
    }

    public function table(Table $table): Table
    {
        return $table
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->recordTitleAttribute('title')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['tags', 'epgChannel', 'playlist'])
                    ->withCount(['failovers'])
                    ->where('is_vod', true);
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('title', 'asc')
            ->columns(VodResource::getTableColumns(showGroup: true, showPlaylist: false))
            ->filters(VodResource::getTableFilters(showPlaylist: false))
            ->recordActions(
                VodResource::getTableActions(),
                position: RecordActionsPosition::BeforeCells,
            )
            ->toolbarActions(VodResource::getTableBulkActions(addToCustom: true));
    }
}
