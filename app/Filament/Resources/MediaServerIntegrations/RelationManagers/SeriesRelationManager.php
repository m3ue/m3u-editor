<?php

namespace App\Filament\Resources\MediaServerIntegrations\RelationManagers;

use App\Filament\Resources\Series\SeriesResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SeriesRelationManager extends RelationManager
{
    protected static string $relationship = 'series';

    protected static ?string $label = 'Series';

    protected static ?string $pluralLabel = 'Series';

    protected static ?string $title = 'Series';

    protected static ?string $navigationLabel = 'Series';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTabComponent(Model $ownerRecord, string $pageClass): Tab
    {
        return Tab::make('Series')
            ->badge($ownerRecord->series()->count())
            ->icon('heroicon-m-video-camera');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return SeriesResource::infolist($schema);
    }

    public function table(Table $table): Table
    {
        return $table
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->recordTitleAttribute('name')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('name', 'asc')
            ->columns(SeriesResource::getTableColumns(showCategory: true, showPlaylist: false))
            ->filters(SeriesResource::getTableFilters(showPlaylist: false))
            ->recordActions(
                SeriesResource::getTableActions(),
                position: RecordActionsPosition::BeforeCells,
            )
            ->toolbarActions(SeriesResource::getTableBulkActions(addToCustom: true));
    }
}
