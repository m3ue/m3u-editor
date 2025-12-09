<?php

namespace App\Filament\Resources\Channels;

use Filament\Schemas\Schema;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Schemas\Components\Grid;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use App\Jobs\MapPlaylistChannelsToEpg;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use App\Jobs\ChannelFindAndReplace;
use App\Jobs\ChannelFindAndReplaceReset;
use App\Filament\Resources\Channels\Pages\ListChannels;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\IconEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Forms\Components\Hidden;
use Exception;
use Filament\Forms\Components\Repeater;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use App\Enums\ChannelLogoType;
use App\Facades\LogoFacade;
use App\Facades\ProxyFacade;
use App\Filament\Resources\ChannelResource\Pages;
use App\Filament\Resources\ChannelResource\RelationManagers;
use App\Filament\Resources\EpgMaps\EpgMapResource;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\Group;
use App\Models\Playlist;
use Filament\Actions\Action;
use Filament\Forms;
use Illuminate\Support\HtmlString;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Infolists;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Tags\Tag;
use App\Traits\HasUserFiltering;
use Filament\Support\Enums\Width;

class ChannelResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = Channel::class;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'title_custom', 'name', 'name_custom', 'url', 'stream_id', 'stream_id_custom'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        $query = parent::getGlobalSearchEloquentQuery()
            ->where('is_vod', false);

        // Filter by user_id for non-admin users
        if (auth()->check() && !auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->where('is_vod', false);

        // Filter by user_id for non-admin users
        if (auth()->check() && !auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    protected static string | \UnitEnum | null $navigationGroup = 'Live Channels';

    protected static ?string $navigationLabel = 'Channels';

    protected static ?string $modelLabel = 'Channel';

    protected static ?string $pluralModelLabel = 'Live Channels';

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return self::setupTable($table);
    }

    public static function setupTable(Table $table, $relationId = null): Table
    {
        // $livewire = $table->getLivewire();
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['epgChannel', 'playlist', 'customPlaylist'])
                    ->withCount(['failovers'])
                    ->where('is_vod', false);
            })
            ->deferLoading()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns(self::getTableColumns(showGroup: !$relationId, showPlaylist: !$relationId))
            ->filters(self::getTableFilters(showPlaylist: !$relationId))
            ->recordActions(self::getTableActions(), position: RecordActionsPosition::BeforeCells)
            ->toolbarActions(self::getTableBulkActions());
    }

    public static function getTableColumns($showGroup = true, $showPlaylist = true): array
    {
        return [
            ImageColumn::make('logo')
                ->label('Logo')
                ->checkFileExistence(false)
                ->size('inherit', 'inherit')
                ->extraImgAttributes(fn($record): array => [
                    'style' => 'height:2.5rem; width:auto; border-radius:4px;', // Live channel style
                ])
                ->getStateUsing(fn($record) => LogoFacade::getChannelLogoUrl($record))
                ->toggleable(),
            TextColumn::make('info')
                ->label('Info')
                ->wrap()
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    return $query
                        ->orderBy('title_custom', $direction)
                        ->orderBy('title', $direction);
                })
                ->getStateUsing(function ($record) {
                    $info = $record->info;
                    $title = $record->title_custom ?: $record->title;
                    $html = "<span class='fi-ta-text-item-label whitespace-normal text-sm leading-6 text-gray-950 dark:text-white'>{$title}</span>";
                    if (is_array($info)) {
                        $description = Str::limit($info['description'] ?? $info['plot'] ?? '', 200);
                        $html .= "<p class='text-sm text-gray-500 dark:text-gray-400 whitespace-normal mt-2'>{$description}</p>";
                    }
                    return new HtmlString($html);
                })
                ->extraAttributes(['style' => 'min-width: 350px;'])
                ->toggleable(),
            TextInputColumn::make('sort')
                ->label('Sort Order')
                ->rules(['min:0'])
                ->type('number')
                ->placeholder('Sort Order')
                ->sortable()
                ->tooltip(fn($record) => !$record->is_custom && $record->playlist?->auto_sort ? 'Playlist auto-sort enabled; any changes will be overwritten on next sync' : 'Channel sort order')
                ->toggleable(),
            ToggleColumn::make('enabled')
                ->toggleable()
                ->tooltip('Toggle channel status')
                ->sortable(),
            TextColumn::make('failovers_count')
                ->label('Failovers')
                ->counts('failovers')
                ->badge()
                ->toggleable()
                ->sortable(),
            TextInputColumn::make('stream_id_custom')
                ->label('ID')
                ->rules(['min:0', 'max:255'])
                ->tooltip(fn($record) => $record->stream_id)
                ->placeholder(fn($record) => $record->stream_id)
                ->searchable()
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    return $query
                        ->orderBy('stream_id_custom', $direction)
                        ->orderBy('stream_id', $direction);
                })
                ->toggleable(),
            TextInputColumn::make('title_custom')
                ->label('Title')
                ->rules(['min:0', 'max:255'])
                ->tooltip(fn($record) => $record->title)
                ->placeholder(fn($record) => $record->title)
                ->searchable()
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    return $query
                        ->orderBy('title_custom', $direction)
                        ->orderBy('title', $direction);
                })
                ->toggleable(),
            TextInputColumn::make('name_custom')
                ->label('Name')
                ->rules(['min:0', 'max:255'])
                ->tooltip(fn($record) => $record->name)
                ->placeholder(fn($record) => $record->name)
                ->searchable(query: function (Builder $query, string $search): Builder {
                    return $query->orWhereRaw('LOWER(channels.name_custom) LIKE ?', ['%' . strtolower($search) . '%']);
                })
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    return $query
                        ->orderBy('name_custom', $direction)
                        ->orderBy('name', $direction);
                })
                ->toggleable(),
            TextInputColumn::make('channel')
                ->rules(['numeric', 'min:0'])
                ->type('number')
                ->placeholder('Channel No.')
                ->tooltip('Channel number')
                ->toggleable()
                ->sortable(),
            TextInputColumn::make('url_custom')
                ->label('URL')
                ->rules(['url'])
                ->type('url')
                ->tooltip('Channel url')
                ->placeholder(fn($record) => $record->url)
                ->searchable()
                ->toggleable(),
            TextInputColumn::make('shift')
                ->label('Time Shift')
                ->rules(['numeric', 'min:0'])
                ->type('number')
                ->placeholder('Time Shift')
                ->tooltip('Time Shift')
                ->toggleable()
                ->sortable(),
            TextColumn::make('group')
                ->hidden(fn() => !$showGroup)
                ->badge()
                ->toggleable()
                ->searchable(query: function ($query, string $search): Builder {
                    $connection = $query->getConnection();
                    $driver = $connection->getDriverName();

                    switch ($driver) {
                        case 'pgsql':
                            return $query->orWhereRaw('LOWER("group"::text) LIKE ?', ["%{$search}%"]);
                        case 'mysql':
                            return $query->orWhereRaw('LOWER(`group`) LIKE ?', ["%{$search}%"]);
                        case 'sqlite':
                            return $query->orWhereRaw('LOWER("group") LIKE ?', ["%{$search}%"]);
                        default:
                            // Fallback using Laravel's database abstraction
                            return $query->orWhere(DB::raw('LOWER(group)'), 'LIKE', "%{$search}%");
                    }
                })
                ->sortable(),
            TextColumn::make('epgChannel.name')
                ->label('EPG Channel')
                ->toggleable()
                ->searchable(query: function (Builder $query, string $search): Builder {
                    return $query->orWhereHas('epgChannel', function (Builder $query) use ($search) {
                        $query->whereRaw('LOWER(epg_channels.name) LIKE ?', ['%' . strtolower($search) . '%']);
                    });
                })
                ->limit(40)
                ->sortable(),
            TextInputColumn::make('tvg_shift')
                ->label('EPG Shift')
                ->rules(['numeric'])
                ->placeholder('EPG Shift')
                ->tooltip('EPG Shift')
                ->toggleable()
                ->sortable(),
            SelectColumn::make('logo_type')
                ->label('Preferred Icon')
                ->options([
                    'channel' => 'Channel',
                    'epg' => 'EPG',
                ])
                ->sortable()
                ->tooltip('Preferred icon source')
                ->toggleable(),
            TextColumn::make('lang')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true)
                ->sortable(),
            TextColumn::make('country')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true)
                ->sortable(),
            TextColumn::make('playlist.name')
                ->hidden(fn() => !$showPlaylist)
                ->numeric()
                ->toggleable()
                ->sortable(),

            TextColumn::make('stream_id')
                ->label('Default ID')
                ->sortable()
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('title')
                ->label('Default Title')
                ->sortable()
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('name')
                ->label('Default Name')
                ->sortable()
                ->searchable(query: function (Builder $query, string $search): Builder {
                    return $query->orWhereRaw('LOWER(channels.name) LIKE ?', ['%' . strtolower($search) . '%']);
                })
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('url')
                ->label('Default URL')
                ->sortable()
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('created_at')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('updated_at')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function getTableFilters($showPlaylist = true): array
    {
        return [
            SelectFilter::make('playlist')
                ->relationship('playlist', 'name')
                ->hidden(fn() => !$showPlaylist)
                ->multiple()
                ->preload()
                ->searchable(),
            Filter::make('enabled')
                ->label('Channel is enabled')
                ->toggle()
                ->query(function ($query) {
                    return $query->where('enabled', true);
                }),
            Filter::make('disabled')
                ->label('Channel is disabled')
                ->toggle()
                ->query(function ($query) {
                    return $query->where('enabled', false);
                }),
            Filter::make('mapped')
                ->label('EPG is mapped')
                ->toggle()
                ->query(function ($query) {
                    return $query->where('epg_channel_id', '!=', null);
                }),
            Filter::make('un_mapped')
                ->label('EPG is not mapped')
                ->toggle()
                ->query(function ($query) {
                    return $query->where('epg_channel_id', '=', null);
                }),
        ];
    }

    public static function getTableActions(): array
    {
        return [
            ActionGroup::make([
                EditAction::make('edit_custom')
                    ->slideOver()
                    ->schema(fn(EditAction $action): array => [
                        Grid::make()
                            ->schema(self::getForm(edit: true))
                            ->columns(2)
                    ]),
                DeleteAction::make()->hidden(fn(Model $record) => !$record->is_custom),
            ])->button()->hiddenLabel()->size('sm')->hidden(fn(Model $record) => !$record->is_custom),
            EditAction::make('edit')
                ->slideOver()
                ->schema(fn(EditAction $action): array => [
                    Grid::make()
                        ->schema(self::getForm(edit: true))
                        ->columns(2)
                ])
                ->button()
                ->hiddenLabel()
                ->disabled(fn(Model $record) => $record->is_custom)
                ->hidden(fn(Model $record) => $record->is_custom),
            Action::make('play')
                ->tooltip('Play Channel')
                ->action(function ($record, $livewire) {
                    $livewire->dispatch('openFloatingStream', $record->getFloatingPlayerAttributes());
                })
                ->icon('heroicon-s-play-circle')
                ->button()
                ->hiddenLabel()
                ->size('sm'),
            ViewAction::make()
                ->button()
                ->icon('heroicon-s-information-circle')
                ->hiddenLabel()
                ->slideOver(),
        ];
    }

    public static function getTableBulkActions($addToCustom = true): array
    {
        return [
            BulkActionGroup::make([
                BulkAction::make('add')
                    ->label('Add to Custom Playlist')
                    ->schema([
                        Select::make('playlist')
                            ->required()
                            ->live()
                            ->label('Custom Playlist')
                            ->helperText('Select the custom playlist you would like to add the selected channel(s) to.')
                            ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->afterStateUpdated(function (Set $set, $state) {
                                if ($state) {
                                    $set('category', null);
                                }
                            })
                            ->searchable(),
                        Select::make('category')
                            ->label('Custom Group')
                            ->disabled(fn(Get $get) => !$get('playlist'))
                            ->helperText(fn(Get $get) => !$get('playlist') ? 'Select a custom playlist first.' : 'Select the group you would like to assign to the selected channel(s) to.')
                            ->options(function ($get) {
                                $customList = CustomPlaylist::find($get('playlist'));
                                return $customList ? $customList->groupTags()->get()
                                    ->mapWithKeys(fn($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
                                    ->toArray() : [];
                            })
                            ->searchable(),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $playlist = CustomPlaylist::findOrFail($data['playlist']);
                        $playlist->channels()->syncWithoutDetaching($records->pluck('id'));
                        if ($data['category']) {
                            $tags = $playlist->groupTags()->get();
                            $tag = $playlist->groupTags()->where('name->en', $data['category'])->first();
                            foreach ($records as $record) {
                                // Need to detach any existing tags from this playlist first
                                $record->detachTags($tags);
                                $record->attachTag($tag);
                            }
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Channels added to custom playlist')
                            ->body('The selected channels have been added to the chosen custom playlist.')
                            ->send();
                    })
                    ->hidden(fn() => !$addToCustom)
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-play')
                    ->modalIcon('heroicon-o-play')
                    ->modalDescription('Add the selected channel(s) to the chosen custom playlist.')
                    ->modalSubmitActionLabel('Add now'),
                BulkAction::make('move')
                    ->label('Move to Group')
                    ->schema([
                        Select::make('playlist')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('group', null);
                            })
                            ->label('Playlist')
                            ->helperText('Select a playlist - only channels in the selected playlist will be moved. Any channels selected from another playlist will be ignored.')
                            ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->searchable(),
                        Select::make('group')
                            ->required()
                            ->live()
                            ->label('Group')
                            ->helperText(fn(Get $get) => $get('playlist') === null ? 'Select a playlist first...' : 'Select the group you would like to move the items to.')
                            ->options(fn(Get $get) => Group::where(['user_id' => auth()->id(), 'playlist_id' => $get('playlist')])->get(['name', 'id'])->pluck('name', 'id'))
                            ->searchable()
                            ->disabled(fn(Get $get) => $get('playlist') === null),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $filtered = $records->where('playlist_id', $data['playlist']);
                        $group = Group::findOrFail($data['group']);
                        foreach ($filtered as $record) {
                            $record->update([
                                'group' => $group->name,
                                'group_id' => $group->id,
                            ]);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Channels moved to group')
                            ->body('The selected channels have been moved to the chosen group.')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-right-left')
                    ->modalIcon('heroicon-o-arrows-right-left')
                    ->modalDescription('Move the selected channel(s) to the chosen group.')
                    ->modalSubmitActionLabel('Move now'),
                BulkAction::make('map')
                    ->label('Map EPG to selected')
                    ->schema(EpgMapResource::getForm(showPlaylist: false, showEpg: true))
                    ->action(function (Collection $records, array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new MapPlaylistChannelsToEpg(
                                epg: (int)$data['epg_id'],
                                channels: $records->pluck('id')->toArray(),
                                force: $data['override'],
                                settings: $data['settings'] ?? [],
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('EPG to Channel mapping')
                            ->body('Mapping started, you will be notified when the process is complete.')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-link')
                    ->modalIcon('heroicon-o-link')
                    ->modalWidth(Width::FourExtraLarge)
                    ->modalDescription('Map the selected EPG to the selected channel(s).')
                    ->modalSubmitActionLabel('Map now'),
                BulkAction::make('preferred_logo')
                    ->label('Update preferred icon')
                    ->schema([
                        Select::make('logo_type')
                            ->label('Preferred Icon')
                            ->helperText('Prefer logo from channel or EPG.')
                            ->options([
                                'channel' => 'Channel',
                                'epg' => 'EPG',
                            ])
                            ->searchable(),

                    ])
                    ->action(function (Collection $records, array $data): void {
                        Channel::whereIn('id', $records->pluck('id')->toArray())
                            ->update([
                                'logo_type' => $data['logo_type'],
                            ]);
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Preferred icon updated')
                            ->body('The preferred icon has been updated.')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-photo')
                    ->modalIcon('heroicon-o-photo')
                    ->modalDescription('Update the preferred icon for the selected channel(s).')
                    ->modalSubmitActionLabel('Update now'),
                BulkAction::make('failover')
                    ->label('Add as failover')
                    ->schema(function (Collection $records) {
                        $existingFailoverIds = $records->pluck('id')->toArray();
                        $initialMasterOptions = [];
                        foreach ($records as $record) {
                            $displayTitle = $record->title_custom ?: $record->title;
                            $playlistName = $record->getEffectivePlaylist()->name ?? 'Unknown';
                            $initialMasterOptions[$record->id] = "{$displayTitle} [{$playlistName}]";
                        }
                        return [
                            ToggleButtons::make('master_source')
                                ->label('Choose master from?')
                                ->options([
                                    'selected' => 'Selected Channels',
                                    'searched' => 'Channel Search',
                                ])
                                ->icons([
                                    'selected' => 'heroicon-o-check',
                                    'searched' => 'heroicon-o-magnifying-glass',
                                ])
                                ->default('selected')
                                ->live()
                                ->grouped(),
                            Select::make('selected_master_id')
                                ->label('Select master channel')
                                ->helperText('From the selected channels')
                                ->options($initialMasterOptions)
                                ->required()
                                ->hidden(fn(Get $get) => $get('master_source') !== 'selected')
                                ->searchable(),
                            Select::make('master_channel_id')
                                ->label('Search for master channel')
                                ->searchable()
                                ->required()
                                ->hidden(fn(Get $get) => $get('master_source') !== 'searched')
                                ->getSearchResultsUsing(function (string $search) use ($existingFailoverIds) {
                                    $searchLower = strtolower($search);
                                    $channels = auth()->user()->channels()
                                        ->withoutEagerLoads()
                                        ->with('playlist')
                                        ->whereNotIn('id', $existingFailoverIds)
                                        ->where(function ($query) use ($searchLower) {
                                            $query->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(title_custom) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(name_custom) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(stream_id) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(stream_id_custom) LIKE ?', ["%{$searchLower}%"]);
                                        })
                                        ->limit(50) // Keep a reasonable limit
                                        ->get();

                                    // Create options array
                                    $options = [];
                                    foreach ($channels as $channel) {
                                        $displayTitle = $channel->title_custom ?: $channel->title;
                                        $playlistName = $channel->getEffectivePlaylist()->name ?? 'Unknown';
                                        $options[$channel->id] = "{$displayTitle} [{$playlistName}]";
                                    }

                                    return $options;
                                })
                                ->helperText('To use as the master for the selected channel.')
                                ->required(),
                        ];
                    })
                    ->action(function (Collection $records, array $data): void {
                        // Filter out the master channel from the records to be added as failovers
                        $masterRecordId = $data['master_source'] === 'selected'
                            ? $data['selected_master_id']
                            : $data['master_channel_id'];
                        $failoverRecords = $records->filter(function ($record) use ($masterRecordId) {
                            return (int)$record->id !== (int)$masterRecordId;
                        });

                        foreach ($failoverRecords as $record) {
                            ChannelFailover::updateOrCreate([
                                'channel_id' => $masterRecordId,
                                'channel_failover_id' => $record->id,
                            ]);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Channels as failover')
                            ->body('The selected channels have been added as failovers.')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->modalIcon('heroicon-o-arrow-path-rounded-square')
                    ->modalDescription('Add the selected channel(s) to the chosen channel as failover sources.')
                    ->modalSubmitActionLabel('Add failovers now'),
                BulkAction::make('find-replace')
                    ->label('Find & Replace')
                    ->schema([
                        Toggle::make('use_regex')
                            ->label('Use Regex')
                            ->live()
                            ->helperText('Use regex patterns to find and replace. If disabled, will use direct string comparison.')
                            ->default(true),
                        Select::make('column')
                            ->label('Column to modify')
                            ->options([
                                'title' => 'Channel Title',
                                'name' => 'Channel Name (tvg-name)',
                            ])
                            ->default('title')
                            ->required()
                            ->columnSpan(1),
                        TextInput::make('find_replace')
                            ->label(fn(Get $get) =>  !$get('use_regex') ? 'String to replace' : 'Pattern to replace')
                            ->required()
                            ->placeholder(
                                fn(Get $get) => $get('use_regex')
                                    ? '^(US- |UK- |CA- )'
                                    : 'US -'
                            )->helperText(
                                fn(Get $get) => !$get('use_regex')
                                    ? 'This is the string you want to find and replace.'
                                    : 'This is the regex pattern you want to find. Make sure to use valid regex syntax.'
                            ),
                        TextInput::make('replace_with')
                            ->label('Replace with (optional)')
                            ->placeholder('Leave empty to remove')

                    ])
                    ->action(function (Collection $records, array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ChannelFindAndReplace(
                                user_id: auth()->id(), // The ID of the user who owns the content
                                use_regex: $data['use_regex'] ?? true,
                                column: $data['column'] ?? 'title',
                                find_replace: $data['find_replace'] ?? null,
                                replace_with: $data['replace_with'] ?? '',
                                channels: $records
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Find & Replace started')
                            ->body('Find & Replace working in the background. You will be notified once the process is complete.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('gray')
                    ->modalIcon('heroicon-o-magnifying-glass')
                    ->modalDescription('Select what you would like to find and replace in the selected channels.')
                    ->modalSubmitActionLabel('Replace now'),
                BulkAction::make('find-replace-reset')
                    ->label('Undo Find & Replace')
                    ->schema([
                        Select::make('column')
                            ->label('Column to reset')
                            ->options([
                                'title' => 'Channel Title',
                                'name' => 'Channel Name (tvg-name)',
                                'logo' => 'Channel Logo (tvg-logo)',
                                'url' => 'Custom URL (tvg-url)',
                            ])
                            ->default('title')
                            ->required()
                            ->columnSpan(1),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ChannelFindAndReplaceReset(
                                user_id: auth()->id(), // The ID of the user who owns the content
                                column: $data['column'] ?? 'title',
                                channels: $records
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Find & Replace reset started')
                            ->body('Find & Replace reset working in the background. You will be notified once the process is complete.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->modalIcon('heroicon-o-arrow-uturn-left')
                    ->modalDescription('Reset Find & Replace results back to playlist defaults for the selected channels. This will remove any custom values set in the selected column.')
                    ->modalSubmitActionLabel('Reset now'),
                BulkAction::make('enable')
                    ->label('Enable selected')
                    ->action(function (Collection $records): void {
                        foreach ($records->chunk(100) as $chunk) {
                            Channel::whereIn('id', $chunk->pluck('id'))->update(['enabled' => true]);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Selected channels enabled')
                            ->body('The selected channels have been enabled.')
                            ->send();
                    })
                    ->color('success')
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-check-circle')
                    ->modalIcon('heroicon-o-check-circle')
                    ->modalDescription('Enable the selected channel(s) now?')
                    ->modalSubmitActionLabel('Yes, enable now'),
                BulkAction::make('disable')
                    ->label('Disable selected')
                    ->action(function (Collection $records): void {
                        foreach ($records->chunk(100) as $chunk) {
                            Channel::whereIn('id', $chunk->pluck('id'))->update(['enabled' => false]);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Selected channels disabled')
                            ->body('The selected channels have been disabled.')
                            ->send();
                    })
                    ->color('danger')
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-x-circle')
                    ->modalIcon('heroicon-o-x-circle')
                    ->modalDescription('Disable the selected channel(s) now?')
                    ->modalSubmitActionLabel('Yes, disable now')
            ]),
        ];
    }

    public static function getRelations(): array
    {
        return [
            // 
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChannels::route('/'),
            //'create' => Pages\CreateChannel::route('/create'),
            //'view' => Pages\ViewChannel::route('/{record}'),
            // 'edit' => Pages\EditChannel::route('/{record}/edit'),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Channel Details')
                    ->collapsible()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('url')
                            ->label('URL')->columnSpanFull(),
                        TextEntry::make('proxy_url')
                            ->label('Proxy URL')->columnSpanFull(),
                        TextEntry::make('stream_id')
                            ->label('ID'),
                        TextEntry::make('title')
                            ->label('Title'),
                        TextEntry::make('name')
                            ->label('Name'),
                        TextEntry::make('channel')
                            ->label('Channel'),
                        TextEntry::make('group')
                            ->label('Group'),
                        IconEntry::make('catchup')
                            ->label('Catchup')
                            ->boolean()
                            ->trueColor('success')
                            ->falseColor('danger'),
                    ]),
            ]);
    }

    public static function getForm($customPlaylist = null, $edit = false): array
    {
        return [
            // Customizable channel fields
            Toggle::make('enabled')
                ->default(true)
                ->columnSpan('full'),
            Fieldset::make('Playlist Type (choose one)')
                ->schema([
                    Toggle::make('is_custom')
                        ->default(true)
                        ->hidden()
                        ->columnSpan('full'),
                    Select::make('playlist_id')
                        ->label('Playlist')
                        ->options(fn() => Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Set $set, $state) {
                            if ($state) {
                                $set('custom_playlist_id', null);
                                $set('group', null);
                                $set('group_id', null);
                            }
                        })
                        ->requiredWithout('custom_playlist_id')
                        ->hidden($customPlaylist !== null)
                        ->validationMessages([
                            'required_without' => 'Playlist is required if not using a custom playlist.',
                        ])
                        ->rules(['exists:playlists,id']),
                    Select::make('custom_playlist_id')
                        ->label('Custom Playlist')
                        ->options(fn() => CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                        ->searchable()
                        ->disabled($customPlaylist !== null)
                        ->default($customPlaylist ? $customPlaylist->id : null)
                        ->live()
                        ->afterStateUpdated(function (Set $set, $state) {
                            if ($state) {
                                $set('playlist_id', null);
                                $set('group', null);
                                $set('group_id', null);
                            }
                        })
                        ->requiredWithout('playlist_id')
                        ->validationMessages([
                            'required_without' => 'Custom Playlist is required if not using a standard playlist.',
                        ])
                        ->dehydrated(true)
                        ->rules(['exists:custom_playlists,id'])
                ])->hidden($edit),
            Fieldset::make('General Settings')
                ->schema([
                    TextInput::make('title')
                        ->label('Title')
                        ->columnSpan(1)
                        ->required()
                        ->hidden($edit)
                        ->rules(['min:1', 'max:255']),
                    TextInput::make('title_custom')
                        ->label('Title')
                        ->placeholder(fn(Get $get) => $get('title'))
                        ->helperText("Leave empty to use default value.")
                        ->columnSpan(1)
                        ->rules(['min:1', 'max:255'])
                        ->hidden(!$edit),
                    TextInput::make('name_custom')
                        ->label('Name')
                        ->hint('tvg-name')
                        ->placeholder(fn(Get $get) => $get('name'))
                        ->helperText(fn(Get $get) => $get('is_custom') ? "" : "Leave empty to use default value.")
                        ->columnSpan(1)
                        ->rules(['min:1', 'max:255']),
                    TextInput::make('stream_id_custom')
                        ->label('ID')
                        ->hint('tvg-id')
                        ->columnSpan(1)
                        ->placeholder(fn(Get $get) => $get('stream_id'))
                        ->helperText(fn(Get $get) => $get('is_custom') ? "" : "Leave empty to use default value.")
                        ->rules(['min:1', 'max:255']),
                    TextInput::make('station_id')
                        ->label('Station ID')
                        ->hint('tvc-guide-stationid')
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Gracenote station ID is a unique identifier for a TV channel in the Gracenote database. It is used to associate the channel with its metadata, such as program listings and other information.'
                        )
                        ->columnSpan(1)
                        ->helperText("Gracenote station ID")
                        ->type('number')
                        ->rules(['numeric', 'min:0']),
                    TextInput::make('channel')
                        ->label('Channel No.')
                        ->hint('tvg-chno')
                        ->columnSpan(1)
                        ->rules(['numeric', 'min:0']),
                    TextInput::make('shift')
                        ->label("Time Shift")
                        ->hint('timeshift')
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Time-shift is features that enable you to access content that has already been broadcast or is currently being broadcast, but at a different time than the original schedule. Time-shift allows you to pause, rewind, or fast-forward live TV, giving you more control over your viewing experience. Your provider must support this feature for it to work.'
                        )
                        ->type('number')
                        ->placeholder(0)
                        ->columnSpan(1)
                        ->rules(['numeric', 'min:0']),
                    Grid::make()
                        ->columnSpanFull()
                        ->schema([
                            Hidden::make('group'),
                            Select::make('group_id')
                                ->label('Group')
                                ->hint('group-title')
                                ->options(fn(Get $get) => Group::where('playlist_id', $get('playlist_id'))->get(['name', 'id'])->pluck('name', 'id'))
                                ->columnSpanFull()
                                ->placeholder('Select a group')
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set) {
                                    $group = Group::find($get('group_id'));
                                    $set('group', $group->name ?? null);
                                })
                                ->rules(['numeric', 'min:0']),
                        ])->hidden(fn(Get $get) => !$get('playlist_id')),
                    TextInput::make('group')
                        ->columnSpanFull()
                        ->placeholder('Enter a group title')
                        ->hint('group-title')
                        ->hidden(!$edit)
                        ->rules(['min:1', 'max:255'])
                        ->hidden(fn(Get $get) => !$get('custom_playlist_id')),
                ]),
            Fieldset::make('URL Settings')
                ->schema([
                    TextInput::make('url')
                        ->label(fn(Get $get) => $get('is_custom') ? 'URL' : 'Provider URL')
                        ->columnSpan(1)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hintIcon(
                            icon: fn(Get $get) => $get('is_custom') ? null : 'heroicon-m-question-mark-circle',
                            tooltip: fn(Get $get) => $get('is_custom') ? null : 'The original URL from the playlist provider. This is read-only and cannot be modified. This URL is automatically updated on Playlist sync.'
                        )
                        ->formatStateUsing(fn($record) => $record?->url)
                        ->disabled(fn(Get $get) => !$get('is_custom')) // make it read-only but copyable for non-custom channels
                        ->dehydrated(fn(Get $get) => $get('is_custom')) // don't save the value in the database for custom channels
                        ->type('url'),
                    TextInput::make('url_custom')
                        ->label('URL Override')
                        ->columnSpan(1)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Override the provider URL with your own custom URL. This URL will be used instead of the provider URL.'
                        )
                        ->helperText("Leave empty to use provider URL.")
                        ->rules(['min:1'])
                        ->type('url')
                        ->hidden(fn(Get $get) => $get('is_custom')),
                    TextInput::make('logo_internal')
                        ->label(fn(Get $get) => $get('is_custom') ? 'Logo' : 'Provider Logo')
                        ->columnSpan(1)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hint('tvg-logo')
                        ->hintIcon(
                            icon: fn(Get $get) => $get('is_custom') ? null : 'heroicon-m-question-mark-circle',
                            tooltip: fn(Get $get) => $get('is_custom') ? null : 'The original logo from the playlist provider. This is read-only and cannot be modified. This URL is automatically updated on Playlist sync.'
                        )
                        ->formatStateUsing(fn($record) => $record?->logo_internal)
                        ->disabled(fn(Get $get) => !$get('is_custom')) // make it read-only but copyable for non-custom channels
                        ->dehydrated(fn(Get $get) => $get('is_custom')) // don't save the value in the database for custom channels
                        ->type('url'),
                    TextInput::make('logo')
                        ->label('Logo Override')
                        ->columnSpan(1)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hint('tvg-logo')
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Override the provider logo with your own custom logo. This logo will be used instead of the provider logo.'
                        )
                        ->helperText("Leave empty to use provider logo.")
                        ->rules(['min:1'])
                        ->type('url')
                        ->hidden(fn(Get $get) => $get('is_custom')),
                    TextInput::make('url_proxy')
                        ->label('Proxy URL')
                        ->columnSpan(2)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Use m3u editor proxy to access this channel.'
                        )
                        ->formatStateUsing(function ($record) {
                            if (!$record || !$record->id) {
                                return null;
                            }
                            try {
                                return ProxyFacade::getProxyUrlForChannel(
                                    $record->id,
                                );
                            } catch (Exception $e) {
                                return null;
                            }
                        })
                        ->helperText("m3u editor proxy url.")
                        ->disabled() // make it read-only but copyable
                        ->dehydrated(false) // don't save the value in the database
                        ->type('url')
                        ->hiddenOn('create'),

                ]),
            Fieldset::make('EPG Settings')
                ->schema([
                    Select::make('epg_channel_id')
                        ->label('EPG Channel')
                        ->helperText('Select an associated EPG channel for this channel.')
                        ->relationship('epgChannel', 'name')
                        ->getOptionLabelFromRecordUsing(fn($record) => "$record->name [{$record->epg->name}]")
                        ->getSearchResultsUsing(function (string $search) {
                            $searchLower = strtolower($search);
                            $channels = auth()->user()->epgChannels()
                                ->withoutEagerLoads()
                                ->with('epg')
                                ->where(function ($query) use ($searchLower) {
                                    $query->whereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                                        ->orWhereRaw('LOWER(display_name) LIKE ?', ["%{$searchLower}%"])
                                        ->orWhereRaw('LOWER(channel_id) LIKE ?', ["%{$searchLower}%"]);
                                })
                                ->limit(50) // Keep a reasonable limit
                                ->get();

                            // Create options array
                            $options = [];
                            foreach ($channels as $channel) {
                                $displayTitle = $channel->name;
                                $epgName = $channel->epg->name ?? 'Unknown';
                                $options[$channel->id] = "{$displayTitle} [{$epgName}]";
                            }
                            return $options;
                        })
                        ->searchable()
                        ->columnSpan(1),
                    Select::make('logo_type')
                        ->label('Preferred Icon')
                        ->helperText('Prefer icon from channel or EPG.')
                        ->options([
                            'channel' => 'Channel',
                            'epg' => 'EPG',
                        ])
                        ->columnSpan(1),
                    TextInput::make('tvg_shift')
                        ->label('EPG Shift')
                        ->hint('tvg-shift')
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'The "tvg-shift" attribute is used in your generated M3U playlist to shift the EPG (Electronic Program Guide) time for specific channels by a certain number of hours. This allows for adjusting the EPG data for individual channels rather than applying a global shift.'
                        )
                        ->columnSpan(1)
                        ->placeholder('0')
                        ->type('number')
                        ->helperText('Indicates the shift of the program schedule, use the values -2,-1,0,1,2,.. and so on.')
                        ->rules(['nullable', 'numeric']),
                ]),
            Fieldset::make('Failover Channels')
                ->schema([
                    Repeater::make('failovers')
                        ->relationship()
                        ->label('')
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->orderColumn('sort')
                        ->simple(
                            Select::make('channel_failover_id')
                                ->label('Failover Channel')
                                ->options(function ($state, $record) {
                                    // Get the current channel ID to exclude it from options
                                    if (!$state) {
                                        return [];
                                    }
                                    $channel = Channel::find($state);
                                    if (!$channel) {
                                        return [];
                                    }

                                    // Return the single channel as the only results if not searching
                                    $displayTitle = $channel->title_custom ?: $channel->title;
                                    $playlistName = $channel->getEffectivePlaylist()->name ?? 'Unknown';
                                    return [$channel->id => "{$displayTitle} [{$playlistName}]"];
                                })
                                ->searchable()
                                ->getSearchResultsUsing(function (string $search, $get, $livewire) {
                                    $existingFailoverIds = collect($get('../../failovers') ?? [])
                                        ->filter(fn($failover) => $failover['channel_failover_id'] ?? null)
                                        ->pluck('channel_failover_id')
                                        ->toArray();

                                    // Get parent record ID to exclude it from search results
                                    $parentRecordId = $livewire->mountedTableActionsData[0]['id'] ?? null;
                                    if ($parentRecordId) {
                                        $existingFailoverIds[] = $parentRecordId;
                                    }

                                    // Always include the selected value if it exists
                                    $searchLower = strtolower($search);
                                    $channels = auth()->user()->channels()
                                        ->withoutEagerLoads()
                                        ->with('playlist')
                                        ->whereNotIn('id', $existingFailoverIds)
                                        ->where(function ($query) use ($searchLower) {
                                            $query->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(title_custom) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(name_custom) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(stream_id) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(stream_id_custom) LIKE ?', ["%{$searchLower}%"]);
                                        })
                                        ->limit(50) // Keep a reasonable limit
                                        ->get();

                                    // Create options array
                                    $options = [];
                                    foreach ($channels as $channel) {
                                        $displayTitle = $channel->title_custom ?: $channel->title;
                                        $playlistName = $channel->getEffectivePlaylist()->name ?? 'Unknown';
                                        $options[$channel->id] = "{$displayTitle} [{$playlistName}]";
                                    }

                                    return $options;
                                })->required()
                        )
                        ->distinct()
                        ->columns(1)
                        ->addActionLabel('Add failover channel')
                        ->columnSpanFull()
                        ->defaultItems(0)
                ])
        ];
    }

    /**
     * Create a custom channel with the provided data.
     *
     * This method is used to create a channel with custom data, typically for a Custom Playlist.
     *
     * @param array $data The data for the channel.
     * @param string $model The model class to use for creating the channel.
     * @return Model The created channel model.
     * @throws ValidationException
     * @throws ModelNotFoundException
     * @throws QueryException
     * @throws Exception
     */
    public static function createCustomChannel(array $data, string $model): Model
    {
        $data['user_id'] = auth()->id();
        $data['is_custom'] = true;
        if (!$data['shift']) {
            $data['shift'] = 0; // Default shift to 0 if not provided
        }
        if (!$data['logo_type']) {
            $data['logo_type'] = 'channel'; // Default to channel if not provided
        }
        $channel = $model::create($data);

        // If the channel is created for a Custom Playlist, we need to associate it with the Custom Playlist
        if (isset($data['custom_playlist_id']) && $data['custom_playlist_id']) {
            $channel->customPlaylists()
                ->syncWithoutDetaching([$data['custom_playlist_id']]);

            $channel->save();
        }
        return $channel;
    }
}
