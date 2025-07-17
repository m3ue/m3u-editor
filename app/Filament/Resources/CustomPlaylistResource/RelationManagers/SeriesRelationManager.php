<?php

namespace App\Filament\Resources\CustomPlaylistResource\RelationManagers;

use App\Filament\Resources\SeriesResource;
use App\Models\Series;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\Tag;

class SeriesRelationManager extends RelationManager
{
    protected static string $relationship = 'series';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return SeriesResource::infolist($infolist);
    }

    public function table(Table $table): Table
    {
        $ownerRecord = $this->ownerRecord;

        $groupColumn = SpatieTagsColumn::make('tags')
            ->label('Playlist Category')
            ->type($ownerRecord->uuid . '-category')
            ->toggleable()->searchable(query: function (Builder $query, string $search) use ($ownerRecord): Builder {
                return $query->whereHas('tags', function (Builder $query) use ($search, $ownerRecord) {
                    $query->where('tags.type', $ownerRecord->uuid . '-category');

                    // Cross-database compatible JSON search
                    $connection = $query->getConnection();
                    $driver = $connection->getDriverName();

                    switch ($driver) {
                        case 'pgsql':
                            // PostgreSQL uses ->> operator for JSON
                            $query->whereRaw('LOWER(tags.name->>\'$\') LIKE ?', ['%' . strtolower($search) . '%']);
                            break;
                        case 'mysql':
                            // MySQL uses JSON_EXTRACT
                            $query->whereRaw('LOWER(JSON_EXTRACT(tags.name, "$")) LIKE ?', ['%' . strtolower($search) . '%']);
                            break;
                        case 'sqlite':
                            // SQLite uses json_extract
                            $query->whereRaw('LOWER(json_extract(tags.name, "$")) LIKE ?', ['%' . strtolower($search) . '%']);
                            break;
                        default:
                            // Fallback - try to search the JSON as text
                            $query->where(DB::raw('LOWER(CAST(tags.name AS TEXT))'), 'LIKE', '%' . strtolower($search) . '%');
                            break;
                    }
                });
            })
            ->sortable(query: function (Builder $query, string $direction) use ($ownerRecord): Builder {
                $connection = $query->getConnection();
                $driver = $connection->getDriverName();

                // Build the ORDER BY clause based on database type
                $orderByClause = match ($driver) {
                    'pgsql' => 'tags.name->>\'$\'',
                    'mysql' => 'JSON_EXTRACT(tags.name, "$")',
                    'sqlite' => 'json_extract(tags.name, "$")',
                    default => 'CAST(tags.name AS TEXT)'
                };

                return $query
                    ->leftJoin('taggables', function ($join) {
                        $join->on('series.id', '=', 'taggables.taggable_id')
                            ->where('taggables.taggable_type', '=', Series::class);
                    })
                    ->leftJoin('tags', function ($join) use ($ownerRecord) {
                        $join->on('taggables.tag_id', '=', 'tags.id')
                            ->where('tags.type', '=', $ownerRecord->uuid . '-category');
                    })
                    ->orderByRaw("{$orderByClause} {$direction}")
                    ->select('series.*', DB::raw("{$orderByClause} as tag_name_sort"))
                    ->distinct();
            });
        $defaultColumns = SeriesResource::getTableColumns(showCategory: true, showPlaylist: true);

        // Inject the custom group column after the group column
        array_splice($defaultColumns, 6, 0, [$groupColumn]);

        return $table->persistFiltersInSession()
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->recordTitleAttribute('name')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns($defaultColumns)
            ->filters(SeriesResource::getTableFilters(showPlaylist: true))
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->form(fn(Tables\Actions\AttachAction $action): array => [
                        $action
                            ->getRecordSelect()
                            ->getSearchResultsUsing(function (string $search) {
                                $searchLower = strtolower($search);
                                $series = Auth::user()->series()
                                    ->withoutEagerLoads()
                                    ->with('playlist')
                                    ->where(function ($query) use ($searchLower) {
                                        $query->whereRaw('LOWER(series.name) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(series.cast) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(series.plot) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(series.genre) LIKE ?', ["%{$searchLower}%"]);
                                    })
                                    ->limit(50)
                                    ->get();

                                // Create options array
                                $options = [];
                                foreach ($series as $seriesItem) {
                                    $displayTitle = $seriesItem->name;
                                    $playlistName = $seriesItem->playlist->name ?? 'Unknown';
                                    $options[$seriesItem->id] = "{$displayTitle} [{$playlistName}]";
                                }

                                return $options;
                            })
                            ->getOptionLabelFromRecordUsing(function ($record) {
                                $displayTitle = $record->title_custom ?: $record->title;
                                $playlistName = $record->getEffectivePlaylist()->name ?? 'Unknown';
                                $options[$record->id] = "{$displayTitle} [{$playlistName}]";
                                return "{$displayTitle} [{$playlistName}]";
                            })
                    ])

                // Advanced attach when adding pivot values:
                // Tables\Actions\AttachAction::make()->form(fn(Tables\Actions\AttachAction $action): array => [
                //     $action->getRecordSelect(),
                //     Forms\Components\TextInput::make('title')
                //         ->label('Title')
                //         ->required(),
                // ]),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()
                    ->color('danger')
                    ->button()->hiddenLabel()
                    ->icon('heroicon-o-x-circle')
                    ->size('sm'),
                ...SeriesResource::getTableActions(),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\DetachBulkAction::make()->color('danger'),
                Tables\Actions\BulkAction::make('add_to_category')
                    ->label('Add to custom category')
                    ->form([
                        Forms\Components\Select::make('category')
                            ->label('Select group')
                            ->options(
                                Tag::query()
                                    ->where('type', $ownerRecord->uuid . '-category')
                                    ->get()
                                    ->map(fn($name) => [
                                        'id' => $name->getAttributeValue('name'),
                                        'name' => $name->getAttributeValue('name')
                                    ])->pluck('id', 'name')
                            )->required(),
                    ])
                    ->action(function (Collection $records, $data) use ($ownerRecord): void {
                        foreach ($records as $record) {
                            $record->syncTagsWithType([$data['category']], $ownerRecord->uuid . '-category');
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Added to category')
                            ->body('The selected series have been added to the custom category.')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-squares-plus')
                    ->modalIcon('heroicon-o-squares-plus')
                    ->modalDescription('Add to category')
                    ->modalSubmitActionLabel('Yes, add to category'),
                ...SeriesResource::getTableBulkActions(addToCustom: false),
            ]);
    }
}
