<?php

namespace App\Filament\Resources\CustomPlaylists\RelationManagers;

use App\Filament\Resources\Vods\VodResource;
use App\Models\Channel;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class VodRelationManager extends RelationManager
{
    protected static string $relationship = 'channels';

    protected static ?string $label = 'VOD Channels';

    protected static ?string $pluralLabel = 'VOD Channels';

    protected static ?string $title = 'VOD Channels';

    protected static ?string $navigationLabel = 'VOD Channels';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTabComponent(Model $ownerRecord, string $pageClass): Tab
    {
        return Tab::make('VOD Channels')
            ->badge($ownerRecord->channels()->where('is_vod', true)->count())
            ->icon('heroicon-m-film');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return VodResource::infolist($schema);
    }

    public function table(Table $table): Table
    {
        $ownerRecord = $this->ownerRecord;

        $groupColumn = SpatieTagsColumn::make('tags')
            ->label('Playlist Group')
            ->type($ownerRecord->uuid)
            ->toggleable()->searchable(query: function (Builder $query, string $search) use ($ownerRecord): Builder {
                return $query->whereHas('tags', function (Builder $query) use ($search, $ownerRecord) {
                    $query->where('tags.type', $ownerRecord->uuid);

                    // Cross-database compatible JSON search
                    $connection = $query->getConnection();
                    $driver = $connection->getDriverName();

                    switch ($driver) {
                        case 'pgsql':
                            // PostgreSQL uses ->> operator for JSON
                            $query->whereRaw('LOWER(tags.name->>\'$\') LIKE ?', ['%'.strtolower($search).'%']);
                            break;
                        case 'mysql':
                            // MySQL uses JSON_EXTRACT
                            $query->whereRaw('LOWER(JSON_EXTRACT(tags.name, "$")) LIKE ?', ['%'.strtolower($search).'%']);
                            break;
                        case 'sqlite':
                            // SQLite uses json_extract
                            $query->whereRaw('LOWER(json_extract(tags.name, "$")) LIKE ?', ['%'.strtolower($search).'%']);
                            break;
                        default:
                            // Fallback - try to search the JSON as text
                            $query->where(DB::raw('LOWER(CAST(tags.name AS TEXT))'), 'LIKE', '%'.strtolower($search).'%');
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
                        $join->on('channels.id', '=', 'taggables.taggable_id')
                            ->where('taggables.taggable_type', '=', Channel::class);
                    })
                    ->leftJoin('tags', function ($join) use ($ownerRecord) {
                        $join->on('taggables.tag_id', '=', 'tags.id')
                            ->where('tags.type', '=', $ownerRecord->uuid);
                    })
                    ->orderByRaw("{$orderByClause} {$direction}")
                    ->select('channels.*', DB::raw("{$orderByClause} as tag_name_sort"))
                    ->distinct();
            });
        $defaultColumns = VodResource::getTableColumns(showGroup: true, showPlaylist: true);

        // Inject the custom group column after the group column
        array_splice($defaultColumns, 13, 0, [$groupColumn]);

        return $table->persistFiltersInSession()
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->recordTitleAttribute('title')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->reorderRecordsTriggerAction(function ($action) {
                return $action->button()->label('Sort');
            })
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['tags', 'epgChannel', 'playlist'])
                    ->withCount(['failovers'])
                    ->where('is_vod', true); // Only show VOD content
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('sort', 'asc')
            ->reorderable('sort')
            ->columns($defaultColumns)
            ->filters([
                ...VodResource::getTableFilters(showPlaylist: true),
                SelectFilter::make('playlist_group')
                    ->label('Custom Group')
                    ->options(function () use ($ownerRecord) {
                        return $ownerRecord->tags()
                            ->where('type', $ownerRecord->uuid)
                            ->get()
                            ->mapWithKeys(fn ($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
                            ->toArray();
                    })
                    ->query(function (Builder $query, array $data) use ($ownerRecord): Builder {
                        if (empty($data['values'])) {
                            return $query;
                        }

                        return $query->where(function ($query) use ($data, $ownerRecord) {
                            foreach ($data['values'] as $groupName) {
                                $query->orWhereHas('tags', function ($tagQuery) use ($groupName, $ownerRecord) {
                                    $tagQuery->where('type', $ownerRecord->uuid)
                                        ->where('name->en', $groupName);
                                });
                            }
                        });
                    })
                    ->multiple()
                    ->searchable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Create Custom VOD')
                    ->schema(VodResource::getForm(customPlaylist: $ownerRecord))
                    ->modalHeading('New Custom VOD')
                    ->modalDescription('NOTE: Custom VOD need to be associated with a Playlist or Custom Playlist.')
                    ->using(fn (array $data, string $model): Model => VodResource::createCustomChannel(
                        data: $data,
                        model: $model,
                    ))
                    ->slideOver(),
                AttachAction::make()
                    ->schema(fn (AttachAction $action): array => [
                        $action
                            ->getRecordSelect()
                            ->getSearchResultsUsing(function (string $search) {
                                $searchLower = strtolower($search);
                                $vods = auth()->user()->channels()
                                    ->withoutEagerLoads()
                                    ->with('playlist')
                                    ->where('is_vod', true) // Only VOD content
                                    ->where(function ($query) use ($searchLower) {
                                        $query->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(title_custom) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(name_custom) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(stream_id) LIKE ?', ["%{$searchLower}%"])
                                            ->orWhereRaw('LOWER(stream_id_custom) LIKE ?', ["%{$searchLower}%"]);
                                    })
                                    ->limit(50)
                                    ->get();

                                // Create options array
                                $options = [];
                                foreach ($vods as $vod) {
                                    $displayTitle = $vod->title_custom ?: $vod->title;
                                    $playlistName = $vod->getEffectivePlaylist()->name ?? 'Unknown';
                                    $options[$vod->id] = "{$displayTitle} [{$playlistName}]";
                                }

                                return $options;
                            })
                            ->getOptionLabelFromRecordUsing(function ($record) {
                                $displayTitle = $record->title_custom ?: $record->title;
                                $playlistName = $record->getEffectivePlaylist()->name ?? 'Unknown';
                                $options[$record->id] = "{$displayTitle} [{$playlistName}]";

                                return "{$displayTitle} [{$playlistName}]";
                            }),
                    ])
                    ->after(function () use ($ownerRecord): void {
                        // Auto-enable proxy if the custom playlist now contains channels from pooled playlists
                        if ($ownerRecord->hasPooledSourcePlaylists() && ! $ownerRecord->enable_proxy) {
                            $ownerRecord->update(['enable_proxy' => true]);

                            Notification::make()
                                ->title('Proxy Enabled')
                                ->body('Proxy mode was automatically enabled because this playlist now contains channels from source playlists with Provider Profiles enabled.')
                                ->info()
                                ->persistent()
                                ->send();
                        }
                    }),
            ])
            ->recordActions([
                DetachAction::make()
                    ->color('danger')
                    ->button()->hiddenLabel()
                    ->icon('heroicon-o-x-circle')
                    ->action(function (Model $record) use ($ownerRecord): void {
                        $tags = $ownerRecord->groupTags()->get();
                        $record->detachTags($tags);
                        $ownerRecord->channels()->detach($record->id);
                    })
                    ->size('sm'),
                ...VodResource::getTableActions(),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                ...VodResource::getTableBulkActions(addToCustom: false),
                BulkAction::make('detach')
                    ->label('Detach Selected')
                    ->action(function (Collection $records) use ($ownerRecord): void {
                        $tags = $ownerRecord->groupTags()->get();
                        foreach ($records as $record) {
                            $record->detachTags($tags);
                        }
                        $ownerRecord->channels()->detach($records->pluck('id'));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Detached from playlist')
                            ->body('The selected channels have been detached from the custom playlist.')
                            ->send();
                    })
                    ->color('danger')
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-x-mark')
                    ->modalIcon('heroicon-o-x-mark')
                    ->modalDescription('Detach selected channels from custom playlist')
                    ->modalSubmitActionLabel('Detach Selected'),
                BulkAction::make('add_to_group')
                    ->label('Add to custom group')
                    ->schema([
                        Select::make('group')
                            ->label('Select group')
                            ->native(false)
                            ->options(
                                $ownerRecord->groupTags()->get()
                                    ->map(fn ($name) => [
                                        'id' => $name->getAttributeValue('name'),
                                        'name' => $name->getAttributeValue('name'),
                                    ])->pluck('id', 'name')
                            )->required(),
                    ])
                    ->action(function (Collection $records, $data) use ($ownerRecord): void {
                        $tags = $ownerRecord->groupTags()->get();
                        $tag = $ownerRecord->groupTags()->where('name->en', $data['group'])->first();
                        foreach ($records as $record) {
                            // Need to detach any existing tags from this playlist first
                            $record->detachTags($tags);
                            $record->attachTag($tag);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Added to group')
                            ->body('The selected VOD have been added to the custom group.')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-squares-plus')
                    ->modalIcon('heroicon-o-squares-plus')
                    ->modalDescription('Add to group')
                    ->modalSubmitActionLabel('Yes, add to group'),
            ]);
    }

    public function getTabs(): array
    {
        // Lets group the tabs by Custom Playlist tags
        $ownerRecord = $this->ownerRecord;
        $tags = $ownerRecord->tags()->where('type', $ownerRecord->uuid)->get();
        $tabs = $tags->map(
            fn ($tag) => Tab::make($tag->name)
                ->modifyQueryUsing(fn ($query) => $query->where('is_vod', true)->whereHas('tags', function ($tagQuery) use ($tag) {
                    $tagQuery->where('type', $tag->type)
                        ->where('name->en', $tag->name);
                }))
                ->badge($ownerRecord->channels()->where('is_vod', true)->withAnyTags([$tag], $tag->type)->count())
        )->toArray();

        // Add an "All" tab to show all channels
        array_unshift(
            $tabs,
            Tab::make('All')
                ->modifyQueryUsing(fn ($query) => $query->where('is_vod', true))
                ->badge($ownerRecord->channels()->where('is_vod', true)->count())
        );
        array_push(
            $tabs,
            Tab::make('Uncategorized')
                ->modifyQueryUsing(fn ($query) => $query->where('is_vod', true)->whereDoesntHave('tags', function ($tagQuery) use ($ownerRecord) {
                    $tagQuery->where('type', $ownerRecord->uuid);
                }))
                ->badge($ownerRecord->channels()->where('is_vod', true)->whereDoesntHave('tags', function ($tagQuery) use ($ownerRecord) {
                    $tagQuery->where('type', $ownerRecord->uuid);
                })->count())
        );

        return $tabs;
    }
}
