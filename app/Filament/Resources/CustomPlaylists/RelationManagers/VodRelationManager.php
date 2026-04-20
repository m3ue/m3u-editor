<?php

namespace App\Filament\Resources\CustomPlaylists\RelationManagers;

use App\Facades\SortFacade;
use App\Filament\Resources\Vods\VodResource;
use App\Jobs\SyncPlexDvrJob;
use App\Models\Channel;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Columns\TextInputColumn;
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

    public static function getNavigationLabel(): string
    {
        return __('VOD Channels');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTabComponent(Model $ownerRecord, string $pageClass): Tab
    {
        return Tab::make(__('VOD Channels'))
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
            ->label(__('Playlist Group'))
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

        // Replace the global editable "channel" column with a custom-playlist pivot channel number column
        foreach ($defaultColumns as $i => $column) {
            if (method_exists($column, 'getName') && $column->getName() === 'channel') {
                $defaultColumns[$i] = TextInputColumn::make('custom_channel_number')
                    ->label(__('Channel'))
                    ->type('number')
                    ->rules(['nullable', 'numeric', 'min:0'])
                    ->placeholder(fn ($record) => (string) $record->channel)
                    ->getStateUsing(function ($record) {
                        return $record->pivot?->channel_number ?? null;
                    })
                    ->updateStateUsing(function ($record, $state) use ($ownerRecord): void {
                        $ownerRecord->channels()->updateExistingPivot(
                            $record->id,
                            ['channel_number' => ($state !== '' && $state !== null) ? (int) $state : null]
                        );
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('channel_custom_playlist.channel_number', $direction);
                    });
            }
            if (method_exists($column, 'getName') && $column->getName() === 'sort') {
                $defaultColumns[$i] = TextInputColumn::make('sort')
                    ->label(__('Sort Order'))
                    ->type('number')
                    ->rules(['nullable', 'numeric', 'min:0'])
                    ->placeholder(fn ($record) => (string) $record->sort)
                    ->getStateUsing(function ($record) {
                        return $record->pivot?->sort ?? null;
                    })
                    ->updateStateUsing(function ($record, $state) use ($ownerRecord): void {
                        $ownerRecord->channels()->updateExistingPivot(
                            $record->id,
                            ['sort' => ($state !== '' && $state !== null) ? (int) $state : null]
                        );
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('channel_custom_playlist.sort', $direction);
                    });
            }
        }

        // Inject the custom group column after the group column
        array_splice($defaultColumns, 14, 0, [$groupColumn]);

        return $table->persistFiltersInSession()
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->recordTitleAttribute('title')
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->reorderRecordsTriggerAction(function ($action) {
                return $action->button()->label(__('Sort'));
            })
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['tags', 'epgChannel', 'playlist'])
                    ->withCount(['failovers'])
                    ->where('is_vod', true); // Only show VOD content
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort(fn (Builder $query, string $direction): Builder => $query->orderByRaw("COALESCE(channel_custom_playlist.sort, channels.sort) {$direction}"))
            ->reorderable('channel_custom_playlist.sort')
            ->columns($defaultColumns)
            ->filters([
                ...VodResource::getTableFilters(showPlaylist: true),
                SelectFilter::make('playlist_group')
                    ->label(__('Custom Group'))
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
                    ->label(__('Create Custom VOD'))
                    ->schema(VodResource::getForm(customPlaylist: $ownerRecord))
                    ->modalHeading(__('New Custom VOD'))
                    ->modalDescription(__('NOTE: Custom VOD need to be associated with a Playlist or Custom Playlist.'))
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
                                ->title(__('Proxy Enabled'))
                                ->body(__('Proxy mode was automatically enabled because this playlist now contains channels from source playlists with Provider Profiles enabled.'))
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
                ...VodResource::getTableBulkActions(addToCustom: false, includeRecount: false),
                BulkAction::make('recount_custom')
                    ->label(__('Recount Channels'))
                    ->icon('heroicon-o-hashtag')
                    ->schema([
                        TextInput::make('start')
                            ->label(__('Start Number'))
                            ->numeric()
                            ->default(1)
                            ->required(),
                    ])
                    ->action(function (Collection $records, array $data) use ($ownerRecord): void {
                        $start = (int) $data['start'];
                        SortFacade::bulkRecountCustomPlaylistChannels($ownerRecord, $records, $start);
                        dispatch(new SyncPlexDvrJob(trigger: 'custom_playlist_recount'));
                    })
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Custom Playlist Channels Recounted'))
                            ->body(__('The selected items were recounted for this custom playlist only.'))
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-hashtag')
                    ->modalDescription(__('Recount the selected items only inside this custom playlist. The original channel numbers will not change.'))
                    ->modalSubmitActionLabel(__('Recount now')),
                BulkAction::make('sort_alpha_custom')
                    ->label(__('Sort Alpha'))
                    ->icon('heroicon-o-bars-arrow-down')
                    ->schema([
                        Select::make('column')
                            ->label(__('Sort By'))
                            ->options([
                                'title' => 'Title (or override if set)',
                                'name' => 'Name (or override if set)',
                                'stream_id' => 'ID (or override if set)',
                                'channel' => 'Channel No.',
                            ])
                            ->default('title')
                            ->required(),
                        Select::make('sort')
                            ->label(__('Sort Order'))
                            ->options([
                                'ASC' => 'A to Z or 0 to 9',
                                'DESC' => 'Z to A or 9 to 0',
                            ])
                            ->default('ASC')
                            ->required(),
                    ])
                    ->action(function (Collection $records, array $data) use ($ownerRecord): void {
                        $order = $data['sort'] ?? 'ASC';
                        $column = $data['column'] ?? 'title';
                        SortFacade::bulkSortAlphaCustomPlaylistChannels($ownerRecord, $records, $order, $column);
                    })
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('VOD Sorted'))
                            ->body(__('The selected VOD have been sorted alphabetically.'))
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-bars-arrow-down')
                    ->modalDescription(__('Sort the selected VOD alphabetically? This will update their sort order within this custom playlist.'))
                    ->modalSubmitActionLabel(__('Sort now')),
                BulkAction::make('detach')
                    ->label(__('Detach Selected'))
                    ->action(function (Collection $records) use ($ownerRecord): void {
                        $tags = $ownerRecord->groupTags()->get();
                        foreach ($records as $record) {
                            $record->detachTags($tags);
                        }
                        $ownerRecord->channels()->detach($records->pluck('id'));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Detached from playlist'))
                            ->body(__('The selected channels have been detached from the custom playlist.'))
                            ->send();
                    })
                    ->color('danger')
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-x-mark')
                    ->modalIcon('heroicon-o-x-mark')
                    ->modalDescription(__('Detach selected channels from custom playlist'))
                    ->modalSubmitActionLabel(__('Detach Selected')),
                BulkAction::make('add_to_group')
                    ->label(__('Add to custom group'))
                    ->schema([
                        Select::make('group')
                            ->label(__('Select group'))
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
                            ->title(__('Added to group'))
                            ->body(__('The selected VOD have been added to the custom group.'))
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-squares-plus')
                    ->modalIcon('heroicon-o-squares-plus')
                    ->modalDescription(__('Add to group'))
                    ->modalSubmitActionLabel(__('Yes, add to group')),
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
            Tab::make(__('All'))
                ->modifyQueryUsing(fn ($query) => $query->where('is_vod', true))
                ->badge($ownerRecord->channels()->where('is_vod', true)->count())
        );
        array_push(
            $tabs,
            Tab::make(__('Uncategorized'))
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
