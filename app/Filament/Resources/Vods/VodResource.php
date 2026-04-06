<?php

namespace App\Filament\Resources\Vods;

use App\Facades\LogoFacade;
use App\Facades\SortFacade;
use App\Filament\Actions\AssetPickerAction;
use App\Filament\Actions\BulkModalActionGroup;
use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\VodResource\Pages;
use App\Filament\Resources\Vods\Pages\ListVod;
use App\Filament\Resources\Vods\Pages\ViewVod;
use App\Forms\Components\TmdbSearchResults;
use App\Jobs\ChannelFindAndReplace;
use App\Jobs\ChannelFindAndReplaceReset;
use App\Jobs\FetchTmdbIds;
use App\Jobs\ProcessVodChannels;
use App\Jobs\SyncPlexDvrJob;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Playlist;
use App\Rules\CheckIfUrlOrLocalPath;
use App\Services\DateFormatService;
use App\Services\LogoCacheService;
use App\Services\PlaylistService;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use App\Traits\HasUserFiltering;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VodResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;
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
            ->where('is_vod', true);

        // Filter by user_id for non-admin users
        if (auth()->check() && ! auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->where('is_vod', true);

        // Filter by user_id for non-admin users
        if (auth()->check() && ! auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('VOD Channels');
    }

    public static function getNavigationLabel(): string
    {
        return __('Channels');
    }

    public static function getModelLabel(): string
    {
        return __('Channel');
    }

    public static function getPluralModelLabel(): string
    {
        return __('VOD Channels');
    }

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
            ->reorderableColumns()
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->modifyQueryUsing(function (Builder $query) {
                $query->with([
                    'epgChannel' => fn ($q) => $q->select('id', 'name', 'icon', 'icon_custom'),
                    'playlist' => fn ($q) => $q->select('id', 'name', 'uuid', 'auto_sort'),
                ])
                    ->withCount(['failovers'])
                    ->where('is_vod', true);
            })
            ->deferLoading()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('sort')
            ->columns(self::getTableColumns(showGroup: ! $relationId, showPlaylist: ! $relationId))
            ->filters(self::getTableFilters(showPlaylist: ! $relationId))
            ->recordActions(self::getTableActions(), position: RecordActionsPosition::BeforeCells)
            ->toolbarActions(self::getTableBulkActions());
    }

    public static function getTableColumns($showGroup = true, $showPlaylist = true): array
    {
        return [
            ImageColumn::make('logo')
                ->label(__('Logo'))
                ->checkFileExistence(false)
                ->size('inherit', 'inherit')
                ->extraImgAttributes(fn ($record): array => [
                    'style' => 'width:80px; height:120px;', // VOD channel style
                ])
                ->getStateUsing(fn ($record) => LogoFacade::getChannelLogoUrl($record))
                ->toggleable(),
            TextColumn::make('info')
                ->label(__('Info'))
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
                        if (! empty($description)) {
                            $html .= "<p class='text-sm text-gray-500 dark:text-gray-400 whitespace-normal mt-2'>{$description}</p>";
                        }
                    }

                    return new HtmlString($html);
                })
                ->extraAttributes(['style' => 'min-width: 350px;'])
                ->toggleable(),
            TextInputColumn::make('sort')
                ->label(__('Sort Order'))
                ->rules(['min:0'])
                ->type('number')
                ->placeholder(__('Sort Order'))
                ->sortable()
                ->tooltip(fn ($record) => ! $record->is_custom && $record->playlist?->auto_sort ? 'Playlist auto-sort enabled; any changes will be overwritten on next sync' : 'Channel sort order')
                ->toggleable(),
            ToggleColumn::make('enabled')
                ->toggleable()
                ->sortable(),
            ToggleColumn::make('can_merge')
                ->label(__('Merge Enabled'))
                ->toggleable()
                ->sortable(),
            TextColumn::make('failovers_count')
                ->label(__('Failovers'))
                ->counts('failovers')
                ->badge()
                ->toggleable()
                ->sortable(),
            IconColumn::make('has_metadata')
                ->label(__('Metadata'))
                ->icon(function ($record): string {
                    if ($record->has_metadata) {
                        return 'heroicon-o-check-circle';
                    }

                    return 'heroicon-o-minus';
                })
                ->color(fn ($record): string => $record->has_metadata ? 'success' : 'gray'),
            IconColumn::make('has_tmdb_id')
                ->label(__('TMDB'))
                ->boolean()
                ->trueIcon('heroicon-m-check-circle')
                ->falseIcon('heroicon-m-minus-circle')
                ->trueColor('success')
                ->falseColor('gray')
                ->tooltip(function ($record): string {
                    $tmdbId = $record->getTmdbId();
                    $imdbId = $record->getImdbId();
                    if ($tmdbId || $imdbId) {
                        $ids = [];
                        if ($tmdbId) {
                            $ids[] = 'TMDB: '.$tmdbId;
                        }
                        if ($imdbId) {
                            $ids[] = 'IMDB: '.$imdbId;
                        }

                        return implode(' | ', $ids);
                    }

                    return 'No TMDB/IMDB ID available';
                })
                ->getStateUsing(fn ($record) => $record->hasMovieId())
                ->toggleable(),
            TextInputColumn::make('stream_id_custom')
                ->label(__('ID'))
                ->rules(['min:0', 'max:255'])
                ->placeholder(fn ($record) => $record->stream_id)
                ->searchable()
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    return $query
                        ->orderBy('stream_id_custom', $direction)
                        ->orderBy('stream_id', $direction);
                })
                ->toggleable(),
            TextInputColumn::make('title_custom')
                ->label(__('Title'))
                ->rules(['min:0', 'max:255'])
                ->placeholder(fn ($record) => $record->title)
                ->searchable()
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    return $query
                        ->orderBy('title_custom', $direction)
                        ->orderBy('title', $direction);
                })
                ->toggleable(),
            TextInputColumn::make('name_custom')
                ->label(__('Name'))
                ->rules(['min:0', 'max:255'])
                ->placeholder(fn ($record) => $record->name)
                ->searchable(query: function (Builder $query, string $search): Builder {
                    return $query->orWhereRaw('LOWER(channels.name_custom) LIKE ?', ['%'.strtolower($search).'%']);
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
                ->placeholder(__('Channel No.'))
                ->toggleable()
                ->sortable(),
            TextInputColumn::make('url_custom')
                ->label(__('URL'))
                ->rules(['url'])
                ->type('url')
                ->placeholder(fn ($record) => $record->url)
                ->searchable()
                ->toggleable(),
            TextInputColumn::make('shift')
                ->label(__('Time Shift'))
                ->rules(['numeric', 'min:0'])
                ->type('number')
                ->placeholder(__('Time Shift'))
                ->toggleable()
                ->sortable(),
            TextColumn::make('group')
                ->hidden(fn () => ! $showGroup)
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
            TextInputColumn::make('tvg_shift')
                ->label(__('EPG Shift'))
                ->rules(['numeric'])
                ->placeholder(__('EPG Shift'))
                ->toggleable()
                ->sortable(),
            SelectColumn::make('logo_type')
                ->label(__('Preferred Icon'))
                ->options([
                    'channel' => 'Channel',
                    'epg' => 'EPG',
                ])
                ->sortable()
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
                ->hidden(fn () => ! $showPlaylist)
                ->numeric()
                ->toggleable()
                ->sortable(),

            TextColumn::make('stream_id')
                ->label(__('Default ID'))
                ->sortable()
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('title')
                ->label(__('Default Title'))
                ->sortable()
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('name')
                ->label(__('Default Name'))
                ->sortable()
                ->searchable(query: function (Builder $query, string $search): Builder {
                    return $query->orWhereRaw('LOWER(channels.name) LIKE ?', ['%'.strtolower($search).'%']);
                })
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('url')
                ->label(__('Default URL'))
                ->sortable()
                ->searchable(query: function (Builder $query, string $search): Builder {
                    $urlExpr = DB::getDriverName() === 'sqlite' ? 'channels.url' : 'channels.url::text';

                    return $query->orWhereRaw("LOWER({$urlExpr}) LIKE ?", ['%'.strtolower($search).'%']);
                })
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('created_at')
                ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('updated_at')
                ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function getTableFilters($showPlaylist = true): array
    {
        return [
            Filter::make('has_metadata')
                ->label(__('Has metadata'))
                ->toggle()
                ->query(function ($query) {
                    return $query->where([
                        ['is_vod', '=', true],
                        ['info', '!=', null],
                        ['movie_data', '!=', null],
                    ]);
                }),
            Filter::make('does_not_have_metadata')
                ->label(__('Does not have metadata'))
                ->toggle()
                ->query(function ($query) {
                    return $query->where([
                        ['is_vod', '=', true],
                        ['info', '=', null],
                        ['movie_data', '=', null],
                    ]);
                }),
            Filter::make('has_tmdb_id')
                ->label(__('Has TMDB/IMDB ID'))
                ->toggle()
                ->query(function ($query) {
                    return $query->where('is_vod', true)
                        ->where(function ($q) {
                            $q->whereRaw("info::jsonb ?? 'tmdb_id'")
                                ->orWhereRaw("info::jsonb ?? 'tmdb'")
                                ->orWhereRaw("movie_data::jsonb ?? 'tmdb_id'")
                                ->orWhereRaw("movie_data::jsonb ?? 'tmdb'")
                                ->orWhereRaw("info::jsonb ?? 'imdb_id'")
                                ->orWhereRaw("info::jsonb ?? 'imdb'")
                                ->orWhereRaw("movie_data::jsonb ?? 'imdb_id'")
                                ->orWhereRaw("movie_data::jsonb ?? 'imdb'");
                        });
                }),
            Filter::make('missing_tmdb_id')
                ->label(__('Missing TMDB/IMDB ID'))
                ->toggle()
                ->query(function ($query) {
                    return $query->where('is_vod', true)
                        ->where(function ($q) {
                            $q->where(function ($inner) {
                                $inner->whereNull('info')
                                    ->orWhere(function ($i) {
                                        $i->whereRaw("NOT (info::jsonb ?? 'tmdb_id')")
                                            ->whereRaw("NOT (info::jsonb ?? 'tmdb')")
                                            ->whereRaw("NOT (info::jsonb ?? 'imdb_id')")
                                            ->whereRaw("NOT (info::jsonb ?? 'imdb')");
                                    });
                            })
                                ->where(function ($inner) {
                                    $inner->whereNull('movie_data')
                                        ->orWhere(function ($i) {
                                            $i->whereRaw("NOT (movie_data::jsonb ?? 'tmdb_id')")
                                                ->whereRaw("NOT (movie_data::jsonb ?? 'tmdb')")
                                                ->whereRaw("NOT (movie_data::jsonb ?? 'imdb_id')")
                                                ->whereRaw("NOT (movie_data::jsonb ?? 'imdb')");
                                        });
                                });
                        });
                }),
            Filter::make('mapped')
                ->label(__('EPG is mapped'))
                ->toggle()
                ->query(function ($query) {
                    return $query->where('epg_channel_id', '!=', null);
                }),
            Filter::make('un_mapped')
                ->label(__('EPG is not mapped'))
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
                Action::make('fetch_tmdb_ids')
                    ->label(__('Fetch TMDB/TVDB IDs'))
                    ->icon('heroicon-o-film')
                    ->modalIcon('heroicon-o-film')
                    ->modalDescription(__('Fetch TMDB, TVDB, and IMDB IDs for this series from The Movie Database.'))
                    ->modalSubmitActionLabel(__('Fetch IDs now'))
                    ->action(function ($record) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new FetchTmdbIds(
                                vodChannelIds: [$record->id]
                            ));
                    })
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('TMDB Search Started'))
                            ->body(__('Searching for TMDB/TVDB IDs. Check the logs or refresh the page in a few seconds.'))
                            ->duration(8000)
                            ->send();
                    })
                    ->requiresConfirmation(),
                Action::make('manual_tmdb_search')
                    ->label(__('Manual TMDB Search'))
                    ->icon('heroicon-o-magnifying-glass')
                    ->slideOver()
                    ->modalWidth('4xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->fillForm(fn ($record) => [
                        'search_query' => $record->title_custom ?: $record->title ?: $record->name,
                        'search_year' => $record->year ?? ($record->info['releasedate'] ?? null ? (int) substr($record->info['releasedate'], 0, 4) : null),
                        'vod_id' => $record->id,
                        'current_tmdb_id' => $record->info['tmdb_id'] ?? $record->movie_data['tmdb_id'] ?? null,
                        'current_imdb_id' => $record->info['imdb_id'] ?? $record->movie_data['imdb_id'] ?? null,
                    ])
                    ->schema([
                        Section::make(__('Current IDs'))
                            ->description(__('Currently stored external IDs for this VOD'))
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        TextInput::make('current_tmdb_id')
                                            ->label(__('TMDB ID'))
                                            ->disabled()
                                            ->placeholder(__('Not set')),
                                        TextInput::make('current_imdb_id')
                                            ->label(__('IMDB ID'))
                                            ->disabled()
                                            ->placeholder(__('Not set')),
                                    ]),
                            ])
                            ->collapsible()
                            ->collapsed(),
                        Section::make(__('Search TMDB'))
                            ->description(__('Search The Movie Database for this movie'))
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        TextInput::make('search_query')
                                            ->label(__('Search Query'))
                                            ->placeholder(__('Enter movie name...'))
                                            ->required()
                                            ->columnSpan(2),
                                        TextInput::make('search_year')
                                            ->label(__('Year (optional)'))
                                            ->numeric()
                                            ->minValue(1900)
                                            ->maxValue(2100)
                                            ->placeholder(__('e.g. 2024')),
                                    ]),
                                Actions::make([
                                    Action::make('search_tmdb')
                                        ->label(__('Search TMDB'))
                                        ->icon('heroicon-o-magnifying-glass')
                                        ->action(function (Get $get, Set $set) {
                                            $query = $get('search_query');
                                            $year = $get('search_year');

                                            if (empty($query)) {
                                                Notification::make()
                                                    ->warning()
                                                    ->title(__('Please enter a search query'))
                                                    ->send();

                                                return;
                                            }

                                            try {
                                                $tmdbService = app(TmdbService::class);
                                                $results = $tmdbService->searchMovieManual($query, $year);
                                                $set('search_results', $results);
                                            } catch (Exception $e) {
                                                Notification::make()
                                                    ->danger()
                                                    ->title(__('Search Error'))
                                                    ->body($e->getMessage())
                                                    ->send();
                                            }
                                        }),
                                ])->fullWidth(),
                            ]),
                        Section::make(__('Search Results'))
                            ->description(__('Click on a result to apply the TMDB IDs'))
                            ->schema([
                                Hidden::make('vod_id'),
                                TmdbSearchResults::make('search_results')
                                    ->type('movie')
                                    ->default([]),
                            ]),
                    ]),
                Action::make('process_vod')
                    ->label(__('Fetch Metadata'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->schema([
                        Toggle::make('overwrite_existing')
                            ->label(__('Overwrite Existing Metadata'))
                            ->helperText(__('Overwrite existing metadata? If disabled, it will only fetch and process metadata if it does not already exist.'))
                            ->default(false),
                    ])
                    ->action(function ($record, array $data) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ProcessVodChannels(
                                channel: $record,
                                force: $data['overwrite_existing'] ?? false
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Fetching VOD metadata for channel'))
                            ->body(__('The VOD metadata fetching and processing has been started. You will be notified when it is complete.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription(__('Fetch and process VOD metadata for the selected channel.'))
                    ->modalSubmitActionLabel(__('Yes, process now')),
                Action::make('sync')
                    ->label(__('Sync VOD .strm file'))
                    ->action(function ($record) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new SyncVodStrmFiles(
                                channel: $record,
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('VOD .strm file is being synced now'))
                            ->body(__('You will be notified once complete.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-arrow-down')
                    ->modalIcon('heroicon-o-document-arrow-down')
                    ->modalDescription(__('Sync VOD .strm files now? This will generate .strm files for this VOD channel at the path set for this channel.'))
                    ->modalSubmitActionLabel(__('Yes, sync now')),
                DeleteAction::make()
                    ->modalIcon('heroicon-o-trash')
                    ->modalDescription(__('Are you sure you want to delete this VOD channel? This action cannot be undone.'))
                    ->modalSubmitActionLabel(__('Yes, delete VOD')),
            ])->button()->hiddenLabel()->size('sm'),
            EditAction::make('edit')
                ->slideOver()
                ->schema(fn (EditAction $action): array => [
                    Grid::make()
                        ->schema(self::getForm(edit: true))
                        ->columns(2),
                ])
                ->button()->hiddenLabel()->size('sm')
                    // Refresh table after edit to remove records that no longer match active filters
                ->after(fn ($livewire) => $livewire->dispatch('$refresh')),
            Action::make('play')
                ->tooltip(__('Play Video'))
                ->action(function ($record, $livewire) {
                    $livewire->dispatch('openFloatingStream', $record->getFloatingPlayerAttributes());
                })
                ->icon('heroicon-s-play-circle')
                ->button()
                ->hiddenLabel()
                ->size('sm'),
            ViewAction::make()
                ->url(fn ($record) => static::getUrl('view', ['record' => $record]))
                ->button()
                ->icon('heroicon-s-eye')
                ->hiddenLabel()
                ->tooltip(__('View enhanced details'))
                ->size('sm'),
        ];
    }

    public static function getTableBulkActions($addToCustom = true, bool $includeRecount = true): array
    {
        return [
            BulkModalActionGroup::make('Bulk VOD actions')
                ->modalHeading(__('Bulk VOD actions'))
                ->gridColumns(2)
                ->schema([
                    PlaylistService::getAddToPlaylistBulkAction('add', 'vod')
                        ->hidden(fn () => ! $addToCustom),
                    BulkAction::make('move')
                        ->label(__('Move to Group'))
                        ->schema([
                            Select::make('playlist')
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Set $set) {
                                    $set('group', null);
                                })
                                ->label(__('Playlist'))
                                ->helperText(__('Select a playlist - only VODs in the selected playlist will be moved. Any VODs selected from another playlist will be ignored.'))
                                ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable(),
                            Select::make('group')
                                ->required()
                                ->live()
                                ->label(__('Group'))
                                ->helperText(fn (Get $get) => $get('playlist') === null ? 'Select a playlist first...' : 'Select the group you would like to move the items to.')
                                ->options(fn (Get $get) => Group::where([
                                    'type' => 'vod',
                                    'user_id' => auth()->id(),
                                    'playlist_id' => $get('playlist'),
                                ])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable()
                                ->disabled(fn (Get $get) => $get('playlist') === null),
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
                                ->title(__('VODs moved to group'))
                                ->body(__('The selected VODs have been moved to the chosen group.'))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-right-left')
                        ->modalIcon('heroicon-o-arrows-right-left')
                        ->modalDescription(__('Move the selected VOD(s) to the chosen group.'))
                        ->modalSubmitActionLabel(__('Move now')),
                    BulkAction::make('preferred_logo')
                        ->label(__('Update preferred icon'))
                        ->schema([
                            Select::make('logo_type')
                                ->label(__('Preferred Icon'))
                                ->helperText(__('Prefer logo from channel or EPG.'))
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
                                ->title(__('Preferred icon updated'))
                                ->body(__('The preferred icon has been updated.'))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-photo')
                        ->modalIcon('heroicon-o-photo')
                        ->modalDescription(__('Update the preferred icon for the selected channel(s).'))
                        ->modalSubmitActionLabel(__('Update now')),
                    BulkAction::make('set_logo_override_url')
                        ->label(__('Set logo override URL'))
                        ->schema([
                            TextInput::make('logo')
                                ->label(__('Logo override URL'))
                                ->url()
                                ->nullable()
                                ->helperText(__('Leave empty to remove the custom logo and use provider/EPG logo.'))
                                ->suffixActions([
                                    AssetPickerAction::upload('logo'),
                                    AssetPickerAction::browse('logo'),
                                ]),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            Channel::whereIn('id', $records->pluck('id')->toArray())
                                ->update([
                                    'logo' => empty($data['logo']) ? null : $data['logo'],
                                ]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Logo override updated'))
                                ->body(__('The logo override URL has been updated for the selected VOD channels.'))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-link')
                        ->modalIcon('heroicon-o-link')
                        ->modalDescription(__('Apply a single logo override URL to all selected VOD channels. Leave empty to remove overrides.'))
                        ->modalSubmitActionLabel(__('Apply URL')),
                    BulkAction::make('refresh_logo_cache')
                        ->label(__('Refresh logo cache (selected)'))
                        ->action(function (Collection $records): void {
                            $urls = [];

                            foreach ($records as $record) {
                                $urls[] = $record->logo;
                                $urls[] = $record->logo_internal;
                                $urls[] = $record->epgChannel?->icon_custom;
                                $urls[] = $record->epgChannel?->icon;
                                $urls[] = $record->info['movie_image'] ?? null;
                                $urls[] = $record->info['cover_big'] ?? null;
                            }

                            $cleared = LogoCacheService::clearByUrls($urls);

                            Notification::make()
                                ->success()
                                ->title(__('Selected VOD cache refreshed'))
                                ->body("Removed {$cleared} cache file(s) for selected VOD resources.")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription(__('Clear cached logos and poster images for selected VOD channels so they are fetched again on the next request.'))
                        ->modalSubmitActionLabel(__('Refresh selected cache')),
                    BulkAction::make('failover')
                        ->label(__('Add as failover'))
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
                                    ->label(__('Choose master from?'))
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
                                    ->label(__('Select master channel'))
                                    ->helperText(__('From the selected channels'))
                                    ->options($initialMasterOptions)
                                    ->required()
                                    ->hidden(fn (Get $get) => $get('master_source') !== 'selected')
                                    ->searchable(),
                                Select::make('master_channel_id')
                                    ->label(__('Search for master channel'))
                                    ->searchable()
                                    ->required()
                                    ->hidden(fn (Get $get) => $get('master_source') !== 'searched')
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
                                    ->helperText(__('To use as the master for the selected channel.'))
                                    ->required(),
                            ];
                        })
                        ->action(function (Collection $records, array $data): void {
                            // Filter out the master channel from the records to be added as failovers
                            $masterRecordId = $data['master_source'] === 'selected'
                                ? $data['selected_master_id']
                                : $data['master_channel_id'];
                            $failoverRecords = $records->filter(function ($record) use ($masterRecordId) {
                                return (int) $record->id !== (int) $masterRecordId;
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
                                ->title(__('Channels as failover'))
                                ->body(__('The selected channels have been added as failovers.'))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path-rounded-square')
                        ->modalIcon('heroicon-o-arrow-path-rounded-square')
                        ->modalDescription(__('Add the selected channel(s) to the chosen channel as failover sources.'))
                        ->modalSubmitActionLabel(__('Add failovers now')),
                    ...($includeRecount ? [
                        BulkAction::make('recount')
                            ->label(__('Recount Channels'))
                            ->icon('heroicon-o-hashtag')
                            ->schema([
                                TextInput::make('start')
                                    ->label(__('Start Number'))
                                    ->numeric()
                                    ->default(1)
                                    ->required(),
                            ])
                            ->action(function (Collection $records, array $data): void {
                                $start = (int) $data['start'];
                                SortFacade::bulkRecountChannels($records, $start);
                            })
                            ->after(function ($livewire) {
                                Notification::make()
                                    ->success()
                                    ->title(__('Channels Recounted'))
                                    ->body(__('The selected channels have been recounted.'))
                                    ->send();
                            })
                            ->requiresConfirmation()
                            ->modalIcon('heroicon-o-hashtag')
                            ->modalDescription(__('Recount the selected channels sequentially? Channel numbers will be assigned based on the current sort order.')),
                    ] : []),
                    BulkAction::make('process_vod')
                        ->label(__('Fetch Metadata'))
                        ->icon('heroicon-o-arrow-down-tray')
                        ->schema([
                            Toggle::make('overwrite_existing')
                                ->label(__('Overwrite Existing Metadata'))
                                ->helperText(__('Overwrite existing metadata? If disabled, it will only fetch and process metadata if it does not already exist.'))
                                ->default(false),
                        ])
                        ->action(function ($records, $data) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->is_vod) {
                                    $count++;
                                    app('Illuminate\Contracts\Bus\Dispatcher')
                                        ->dispatch(new ProcessVodChannels(
                                            channel: $record,
                                            force: $data['overwrite_existing'] ?? false
                                        ));
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title("Fetching VOD metadata for {$count} channel(s)")
                                ->body(__('The VOD metadata fetching and processing has been started. You will be notified when it is complete.'))
                                ->duration(10000)
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-down-tray')
                        ->modalIcon('heroicon-o-arrow-down-tray')
                        ->modalDescription(__('Fetch and process VOD metadata for the selected channels? Only enabled VOD channels will be processed.'))
                        ->modalSubmitActionLabel(__('Yes, process now')),
                    BulkAction::make('fetch_tmdb_ids')
                        ->label(__('Fetch TMDB IDs'))
                        ->icon('heroicon-o-magnifying-glass')
                        ->schema([
                            Toggle::make('overwrite_existing')
                                ->label(__('Overwrite Existing IDs'))
                                ->helperText(__('Overwrite existing TMDB/IMDB IDs? If disabled, it will only fetch IDs for items that don\\\'t already have them.'))
                                ->default(false),
                        ])
                        ->action(function ($records, $data) {
                            $settings = app(GeneralSettings::class);
                            if (empty($settings->tmdb_api_key)) {
                                Notification::make()
                                    ->danger()
                                    ->title(__('TMDB API Key Required'))
                                    ->body(__('Please configure your TMDB API key in Settings > TMDB before using this feature.'))
                                    ->duration(10000)
                                    ->send();

                                return;
                            }

                            $vodIds = $records->pluck('id')->toArray();

                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new FetchTmdbIds(
                                    vodChannelIds: $vodIds,
                                    seriesIds: null,
                                    overwriteExisting: $data['overwrite_existing'] ?? false,
                                    user: auth()->user(),
                                ));

                            Notification::make()
                                ->success()
                                ->title('Fetching TMDB IDs for '.count($vodIds).' VOD channel(s)')
                                ->body(__('The TMDB ID lookup has been started. You will be notified when it is complete.'))
                                ->duration(10000)
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-o-magnifying-glass')
                        ->modalDescription(__('Search TMDB for matching movies and populate TMDB/IMDB IDs for the selected VOD channels? This enables Trash Guides compatibility for Radarr/Sonarr.'))
                        ->modalSubmitActionLabel(__('Yes, fetch IDs now')),
                    BulkAction::make('sync')
                        ->label(__('Sync VOD .strm files'))
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new SyncVodStrmFiles(
                                        channel: $record,
                                    ));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('.strm files are being synced for selected VOD channels'))
                                ->body(__('You will be notified once complete.'))
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-document-arrow-down')
                        ->modalIcon('heroicon-o-document-arrow-down')
                        ->modalDescription(__('Sync selected VOD .strm files now? This will generate .strm files for the selected VOD channels at the path set for the channels.'))
                        ->modalSubmitActionLabel(__('Yes, sync now')),
                    BulkAction::make('find-replace')
                        ->label(__('Find & Replace'))
                        ->schema(function (): array {
                            $savedPatterns = [];
                            $savedPatternRules = [];
                            $counter = 0;
                            foreach (Playlist::where('user_id', auth()->id())->get() as $playlist) {
                                foreach ($playlist->find_replace_rules ?? [] as $rule) {
                                    if (is_array($rule) && ($rule['target'] ?? 'channels') === 'channels') {
                                        $savedPatterns[$counter] = "{$playlist->name} - ".($rule['name'] ?? 'Unnamed');
                                        $savedPatternRules[$counter] = $rule;
                                        $counter++;
                                    }
                                }
                            }

                            return [
                                Select::make('saved_pattern')
                                    ->label(__('Load saved pattern'))
                                    ->searchable()
                                    ->placeholder(__('Select a saved pattern...'))
                                    ->options($savedPatterns)
                                    ->hidden(empty($savedPatterns))
                                    ->live()
                                    ->afterStateUpdated(function (?string $state, Set $set) use ($savedPatternRules): void {
                                        if ($state === null || $state === '') {
                                            return;
                                        }
                                        $rule = $savedPatternRules[(int) $state] ?? null;
                                        if (! $rule) {
                                            return;
                                        }
                                        $set('use_regex', $rule['use_regex'] ?? true);
                                        $set('column', $rule['column'] ?? 'title');
                                        $set('find_replace', $rule['find_replace'] ?? '');
                                        $set('replace_with', $rule['replace_with'] ?? '');
                                    })
                                    ->dehydrated(false),
                                Toggle::make('use_regex')
                                    ->label(__('Use Regex'))
                                    ->live()
                                    ->helperText(__('Use regex patterns to find and replace. If disabled, will use direct string comparison.'))
                                    ->default(true),
                                Select::make('column')
                                    ->label(__('Column to modify'))
                                    ->options([
                                        'title' => 'Channel Title',
                                        'name' => 'Channel Name (tvg-name)',
                                        'info->description' => 'Description (metadata)',
                                        'info->genre' => 'Genre (metadata)',
                                    ])
                                    ->default('title')
                                    ->required()
                                    ->columnSpan(1),
                                TextInput::make('find_replace')
                                    ->label(fn (Get $get) => ! $get('use_regex') ? 'String to replace' : 'Pattern to replace')
                                    ->required()
                                    ->placeholder(
                                        fn (Get $get) => $get('use_regex')
                                            ? '^(US- |UK- |CA- )'
                                            : 'US -'
                                    )->helperText(
                                        fn (Get $get) => ! $get('use_regex')
                                            ? 'This is the string you want to find and replace.'
                                            : 'This is the regex pattern you want to find. Make sure to use valid regex syntax.'
                                    ),
                                TextInput::make('replace_with')
                                    ->label(__('Replace with (optional)'))
                                    ->placeholder(__('Leave empty to remove')),
                            ];
                        })
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
                                ->title(__('Find & Replace started'))
                                ->body(__('Find & Replace working in the background. You will be notified once the process is complete.'))
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-magnifying-glass')
                        ->color('gray')
                        ->modalIcon('heroicon-o-magnifying-glass')
                        ->modalDescription(__('Select what you would like to find and replace in the selected channels.'))
                        ->modalSubmitActionLabel(__('Replace now')),
                    BulkAction::make('find-replace-reset')
                        ->label(__('Undo Find & Replace'))
                        ->schema([
                            Select::make('column')
                                ->label(__('Column to reset'))
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
                                ->title(__('Find & Replace reset started'))
                                ->body(__('Find & Replace reset working in the background. You will be notified once the process is complete.'))
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->modalIcon('heroicon-o-arrow-uturn-left')
                        ->modalDescription(__('Reset Find & Replace results back to playlist defaults for the selected channels. This will remove any custom values set in the selected column.'))
                        ->modalSubmitActionLabel(__('Reset now')),
                    BulkAction::make('enable-merge')
                        ->label(__('Enable Merge'))
                        ->action(function (Collection $records, array $data): void {
                            $records->each(fn ($channel) => $channel->update([
                                'can_merge' => true,
                            ]));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Merge re-enabled for selected channels'))
                                ->body(__('The merge has been re-enabled for the selected channels. They can now be merged during "Merge Same ID" jobs.'))
                                ->send();
                        })
                        ->hidden(fn () => ! $addToCustom)
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-pointing-in')
                        ->modalIcon('heroicon-o-arrows-pointing-in')
                        ->modalDescription(__('Allow merging for selected channels when running "Merge Same ID" jobs.'))
                        ->modalSubmitActionLabel(__('Enable now')),
                    BulkAction::make('disable-merge')
                        ->label(__('Disable Merge'))
                        ->color('warning')
                        ->action(function (Collection $records, array $data): void {
                            $records->each(fn ($channel) => $channel->update([
                                'can_merge' => false,
                            ]));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Merge disabled for selected channels'))
                                ->body(__('The merge has been disabled for the selected channels. They will not be merged during "Merge Same ID" jobs.'))
                                ->send();
                        })
                        ->hidden(fn () => ! $addToCustom)
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-pointing-in')
                        ->modalIcon('heroicon-o-arrows-pointing-in')
                        ->modalDescription(__('Don\\\'t allow merging for selected channels when running "Merge Same ID" jobs.'))
                        ->modalSubmitActionLabel(__('Disable now')),
                    BulkAction::make('enable')
                        ->label(__('Enable selected'))
                        ->action(function (Collection $records): void {
                            foreach ($records->chunk(100) as $chunk) {
                                Channel::whereIn('id', $chunk->pluck('id'))->update(['enabled' => true]);
                            }
                        })->after(function () {
                            dispatch(new SyncPlexDvrJob(trigger: 'vod_bulk_enable'));
                            Notification::make()
                                ->success()
                                ->title(__('Selected channels enabled'))
                                ->body(__('The selected channels have been enabled.'))
                                ->send();
                        })
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription(__('Enable the selected channel(s) now?'))
                        ->modalSubmitActionLabel(__('Yes, enable now')),
                    BulkAction::make('disable')
                        ->label(__('Disable selected'))
                        ->action(function (Collection $records): void {
                            foreach ($records->chunk(100) as $chunk) {
                                Channel::whereIn('id', $chunk->pluck('id'))->update(['enabled' => false]);
                            }
                        })->after(function () {
                            dispatch(new SyncPlexDvrJob(trigger: 'vod_bulk_disable'));
                            Notification::make()
                                ->success()
                                ->title(__('Selected channels disabled'))
                                ->body(__('The selected channels have been disabled.'))
                                ->send();
                        })
                        ->color('danger')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription(__('Disable the selected channel(s) now?'))
                        ->modalSubmitActionLabel(__('Yes, disable now')),
                    DeleteBulkAction::make()
                        ->modalIcon('heroicon-o-trash')
                        ->modalDescription(__('Are you sure you want to delete the selected VOD channels? This action cannot be undone.'))
                        ->modalSubmitActionLabel(__('Yes, delete VODs')),
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
            'index' => ListVod::route('/'),
            'view' => ViewVod::route('/{record}'),
            // 'create' => Pages\CreateVod::route('/create'),
            // 'edit' => Pages\EditVod::route('/{record}/edit'),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Channel Details'))
                    ->collapsible()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('url')
                            ->label(__('URL'))->columnSpanFull(),
                        TextEntry::make('proxy_url')
                            ->label(__('Proxy URL'))->columnSpanFull(),
                        TextEntry::make('stream_id')
                            ->label(__('ID')),
                        TextEntry::make('title')
                            ->label(__('Title')),
                        TextEntry::make('name')
                            ->label(__('Name')),
                        TextEntry::make('channel')
                            ->label(__('Channel')),
                        TextEntry::make('group')
                            ->label(__('Group')),
                        IconEntry::make('catchup')
                            ->label(__('Catchup'))
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
                ->columnSpanFull()
                ->default(true),
            Toggle::make('can_merge')
                ->default(true)
                ->helperText(__('Allow this channel to be merged during "Merge Same ID" jobs.')),
            Fieldset::make(__('Playlist Type (choose one)'))
                ->schema([
                    Toggle::make('is_custom')
                        ->default(true)
                        ->hidden()
                        ->columnSpan('full'),
                    Select::make('playlist_id')
                        ->label(__('Playlist'))
                        ->options(fn () => Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
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
                        ->label(__('Custom Playlist'))
                        ->options(fn () => CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
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
                        ->rules(['exists:custom_playlists,id']),
                ])->hidden($edit),
            Fieldset::make(__('General Settings'))
                ->schema([
                    TextInput::make('title')
                        ->label(__('Title'))
                        ->columnSpan(1)
                        ->required()
                        ->hidden($edit)
                        ->rules(['min:1', 'max:255']),
                    TextInput::make('title_custom')
                        ->label(__('Title'))
                        ->placeholder(fn (Get $get) => $get('title'))
                        ->helperText(__('Leave empty to use default value.'))
                        ->columnSpan(1)
                        ->rules(['min:1', 'max:255'])
                        ->hidden(! $edit),
                    TextInput::make('name_custom')
                        ->label(__('Name'))
                        ->hint(__('tvg-name'))
                        ->placeholder(fn (Get $get) => $get('name'))
                        ->helperText(fn (Get $get) => $get('is_custom') ? '' : 'Leave empty to use default value.')
                        ->columnSpan(1)
                        ->rules(['min:1', 'max:255']),
                    TextInput::make('stream_id_custom')
                        ->label(__('ID'))
                        ->hint(__('tvg-id'))
                        ->columnSpan(1)
                        ->placeholder(fn (Get $get) => $get('stream_id'))
                        ->helperText(fn (Get $get) => $get('is_custom') ? '' : 'Leave empty to use default value.')
                        ->rules(['min:1', 'max:255']),
                    TextInput::make('station_id')
                        ->label(__('Station ID'))
                        ->hint(__('tvc-guide-stationid'))
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Gracenote station ID is a unique identifier for a TV channel in the Gracenote database. It is used to associate the channel with its metadata, such as program listings and other information.'
                        )
                        ->columnSpan(1)
                        ->helperText(__('Gracenote station ID'))
                        ->type('number')
                        ->rules(['numeric', 'min:0']),
                    TextInput::make('channel')
                        ->label(__('Channel No.'))
                        ->hint(__('tvg-chno'))
                        ->columnSpan(1)
                        ->rules(['numeric', 'min:0']),
                    TextInput::make('shift')
                        ->label(__('Time Shift'))
                        ->hint(__('timeshift'))
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
                                ->label(__('Group'))
                                ->hint(__('group-title'))
                                ->options(fn (Get $get) => Group::where('playlist_id', $get('playlist_id'))->get(['name', 'id'])->pluck('name', 'id'))
                                ->columnSpanFull()
                                ->placeholder(__('Select a group'))
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set) {
                                    $group = Group::find($get('group_id'));
                                    $set('group', $group->name ?? null);
                                })
                                ->rules(['numeric', 'min:0']),
                        ])->hidden(fn (Get $get) => ! $get('playlist_id')),
                    TextInput::make('group')
                        ->columnSpanFull()
                        ->placeholder(__('Enter a group title'))
                        ->hint(__('group-title'))
                        ->hidden(! $edit)
                        ->rules(['min:1', 'max:255'])
                        ->hidden(fn (Get $get) => ! $get('custom_playlist_id')),
                ]),
            Fieldset::make(__('URL Settings'))
                ->schema([
                    TextInput::make('url')
                        ->label(fn (Get $get) => $get('is_custom') ? 'URL' : 'Provider URL')
                        ->columnSpan(1)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hintIcon(
                            icon: fn (Get $get) => $get('is_custom') ? null : 'heroicon-m-question-mark-circle',
                            tooltip: fn (Get $get) => $get('is_custom') ? null : 'The original URL from the playlist provider. This is read-only and cannot be modified. This URL is automatically updated on Playlist sync.'
                        )
                        ->formatStateUsing(fn ($record) => $record?->url)
                        ->disabled(fn (Get $get) => ! $get('is_custom')) // make it read-only but copyable for non-custom channels
                        ->dehydrated(fn (Get $get) => $get('is_custom')) // don't save the value in the database for custom channels
                        ->type('url'),
                    TextInput::make('url_custom')
                        ->label(__('URL Override'))
                        ->columnSpan(1)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Override the provider URL with your own custom URL. This URL will be used instead of the provider URL.'
                        )
                        ->helperText(__('Leave empty to use provider URL.'))
                        ->rules(['min:1'])
                        ->type('url')
                        ->hidden(fn (Get $get) => $get('is_custom')),
                    TextInput::make('logo_internal')
                        ->label(fn (Get $get) => $get('is_custom') ? 'Logo' : 'Provider Logo')
                        ->columnSpan(1)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hint(__('tvg-logo'))
                        ->hintIcon(
                            icon: fn (Get $get) => $get('is_custom') ? null : 'heroicon-m-question-mark-circle',
                            tooltip: fn (Get $get) => $get('is_custom') ? null : 'The original logo from the playlist provider. This is read-only and cannot be modified. This URL is automatically updated on Playlist sync.'
                        )
                        ->formatStateUsing(fn ($record) => $record?->logo_internal)
                        ->disabled(fn (Get $get) => ! $get('is_custom')) // make it read-only but copyable for non-custom channels
                        ->dehydrated(fn (Get $get) => $get('is_custom')) // don't save the value in the database for custom channels
                        ->type('url')
                        ->suffixActions([
                            AssetPickerAction::upload('logo_internal')
                                ->visible(fn (Get $get): bool => $get('is_custom')),
                            AssetPickerAction::browse('logo_internal')
                                ->visible(fn (Get $get): bool => $get('is_custom')),
                        ]),
                    TextInput::make('logo')
                        ->label(__('Logo Override'))
                        ->columnSpan(1)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hint(__('tvg-logo'))
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Override the provider logo with your own custom logo. This logo will be used instead of the provider logo.'
                        )
                        ->helperText(__('Leave empty to use provider logo.'))
                        ->rules(['min:1'])
                        ->type('url')
                        ->hidden(fn (Get $get) => $get('is_custom'))
                        ->suffixActions([
                            AssetPickerAction::upload('logo'),
                            AssetPickerAction::browse('logo'),
                        ]),
                    TextInput::make('proxy_url')
                        ->label(__('Proxy URL'))
                        ->columnSpan(2)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Use m3u editor proxy to access this channel.'
                        )
                        ->formatStateUsing(fn ($record) => $record?->getProxyUrl())
                        ->helperText(__('m3u editor proxy url.'))
                        ->disabled() // make it read-only but copyable
                        ->dehydrated(false) // don't save the value in the database
                        ->type('url')
                        ->hiddenOn('create'),
                ]),
            Fieldset::make(__('EPG Settings'))
                ->schema([
                    Select::make('epg_channel_id')
                        ->label(__('EPG Channel'))
                        ->helperText(__('Select an associated EPG channel for this channel.'))
                        ->relationship('epgChannel', 'name')
                        ->getOptionLabelFromRecordUsing(fn ($record) => "$record->name [{$record->epg->name}]")
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
                        ->label(__('Preferred Icon'))
                        ->helperText(__('Prefer icon from channel or EPG.'))
                        ->options([
                            'channel' => 'Channel',
                            'epg' => 'EPG',
                        ])
                        ->columnSpan(1),
                    TextInput::make('tvg_shift')
                        ->label(__('EPG Shift'))
                        ->hint(__('tvg-shift'))
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'The "tvg-shift" attribute is used in your generated M3U playlist to shift the EPG (Electronic Program Guide) time for specific channels by a certain number of hours. This allows for adjusting the EPG data for individual channels rather than applying a global shift.'
                        )
                        ->columnSpan(1)
                        ->placeholder(__('0'))
                        ->type('number')
                        ->helperText(__('Indicates the shift of the program schedule, use the values -2,-1,0,1,2,.. and so on.'))
                        ->rules(['nullable', 'numeric']),
                ]),
            Fieldset::make(__('VOD Settings'))
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    // Basic VOD Information
                    TextInput::make('container_extension')
                        ->label(__('Container Extension'))
                        ->helperText(__('The file extension of the VOD container (e.g., mp4, mkv, etc.).'))
                        ->placeholder(__('mp4'))
                        ->rules(['nullable', 'string', 'max:10']),
                    TextInput::make('year')
                        ->label(__('Year'))
                        ->helperText(__('The year of the VOD content.'))
                        ->placeholder(__('2000'))
                        ->rules(['nullable', 'integer', 'digits:4']),
                    TextInput::make('rating')
                        ->label(__('Rating'))
                        ->helperText(__('10 based rating of the VOD content.'))
                        ->placeholder(__('8.7'))
                        ->rules(['nullable', 'numeric', 'max:10']),
                    TextInput::make('rating_5based')
                        ->label(__('Rating (5-based)'))
                        ->helperText(__('The rating of the VOD content on a scale of 0 to 5.'))
                        ->placeholder(__('5'))
                        ->rules(['nullable', 'numeric', 'min:0', 'max:5']),

                    // Info fields - Basic Details
                    TextInput::make('info.name')
                        ->label(__('Title (Info)'))
                        ->helperText(__('The title from metadata info.'))
                        ->placeholder(__('Movie Title'))
                        ->rules(['nullable', 'string', 'max:255']),
                    TextInput::make('info.o_name')
                        ->label(__('Original Title'))
                        ->helperText(__('The original title in the source language.'))
                        ->placeholder(__('Original Movie Title'))
                        ->rules(['nullable', 'string', 'max:255']),
                    TextInput::make('info.release_date')
                        ->label(__('Release Date'))
                        ->helperText(__('The release date of the content.'))
                        ->placeholder(__('YYYY-MM-DD'))
                        ->rules(['nullable', 'string', 'max:20']),
                    TextInput::make('info.releasedate')
                        ->label(__('Release Date (Alt)'))
                        ->helperText(__('Alternative release date field.'))
                        ->placeholder(__('YYYY or YYYY-MM-DD'))
                        ->rules(['nullable', 'string', 'max:20']),
                    TextInput::make('info.duration')
                        ->label(__('Duration'))
                        ->helperText(__('Duration in HH:MM:SS format.'))
                        ->placeholder(__('01:30:00'))
                        ->rules(['nullable', 'string', 'max:20']),
                    TextInput::make('info.duration_secs')
                        ->label(__('Duration (Seconds)'))
                        ->helperText(__('Duration in seconds.'))
                        ->placeholder(__('5400'))
                        ->type('number')
                        ->rules(['nullable', 'integer', 'min:0']),
                    TextInput::make('info.episode_run_time')
                        ->label(__('Episode Runtime'))
                        ->helperText(__('Episode runtime in minutes.'))
                        ->placeholder(__('45'))
                        ->type('number')
                        ->rules(['nullable', 'integer', 'min:0']),
                    TextInput::make('info.bitrate')
                        ->label(__('Bitrate'))
                        ->helperText(__('Video bitrate in kbps.'))
                        ->placeholder(__('5000'))
                        ->type('number')
                        ->rules(['nullable', 'integer', 'min:0']),

                    // Content Classification
                    TextInput::make('info.genre')
                        ->label(__('Genre'))
                        ->helperText(__('Genre of the content.'))
                        ->placeholder(__('Action, Drama, Comedy'))
                        ->rules(['nullable', 'string', 'max:255']),
                    TextInput::make('info.country')
                        ->label(__('Country'))
                        ->helperText(__('Country of origin.'))
                        ->placeholder(__('USA, UK, etc.'))
                        ->rules(['nullable', 'string', 'max:255']),
                    TextInput::make('info.age')
                        ->label(__('Age Rating'))
                        ->helperText(__('Age rating or classification.'))
                        ->placeholder(__('PG-13, R, etc.'))
                        ->rules(['nullable', 'string', 'max:10']),
                    TextInput::make('info.mpaa_rating')
                        ->label(__('MPAA Rating'))
                        ->helperText(__('MPAA rating classification.'))
                        ->placeholder(__('PG, PG-13, R, NC-17'))
                        ->rules(['nullable', 'string', 'max:10']),

                    // Ratings and Reviews
                    TextInput::make('info.rating_count_kinopoisk')
                        ->label(__('Kinopoisk Rating Count'))
                        ->helperText(__('Number of ratings on Kinopoisk.'))
                        ->placeholder(__('15000'))
                        ->type('number')
                        ->rules(['nullable', 'integer', 'min:0']),

                    // External IDs and URLs
                    TextInput::make('info.tmdb_id')
                        ->label(__('TMDB ID'))
                        ->helperText(__('The Movie Database ID.'))
                        ->placeholder(__('123456'))
                        ->type('number')
                        ->rules(['nullable', 'integer', 'min:0']),
                    TextInput::make('info.kinopoisk_url')
                        ->label(__('Kinopoisk URL'))
                        ->helperText(__('URL to Kinopoisk page.'))
                        ->placeholder(__('https://www.kinopoisk.ru/film/123456/'))
                        ->type('url')
                        ->rules(['nullable', 'url', 'max:500']),
                    TextInput::make('info.youtube_trailer')
                        ->label(__('YouTube Trailer'))
                        ->helperText(__('YouTube trailer URL or ID.'))
                        ->placeholder(__('https://www.youtube.com/watch?v=abc123'))
                        ->rules(['nullable', 'max:500']),

                    // Images
                    TextInput::make('info.cover_big')
                        ->label(__('Cover Image (Large)'))
                        ->helperText(__('URL to large cover image.'))
                        ->placeholder(__('https://example.com/cover.jpg'))
                        ->type('url')
                        ->rules(['nullable', 'url', 'max:500']),
                    TextInput::make('info.movie_image')
                        ->label(__('Movie Image'))
                        ->helperText(__('URL to movie poster/image.'))
                        ->placeholder(__('https://example.com/poster.jpg'))
                        ->type('url')
                        ->rules(['nullable', 'url', 'max:500']),

                    // Cast and Crew
                    Textarea::make('info.director')
                        ->label(__('Director'))
                        ->helperText(__('Director(s) of the content.'))
                        ->placeholder(__('John Doe, Jane Smith'))
                        ->rows(2)
                        ->rules(['nullable', 'string', 'max:1000']),
                    Textarea::make('info.actors')
                        ->label(__('Actors'))
                        ->helperText(__('Main actors in the content.'))
                        ->placeholder(__('Actor 1, Actor 2, Actor 3'))
                        ->rows(3)
                        ->rules(['nullable', 'string', 'max:2000']),
                    Textarea::make('info.cast')
                        ->label(__('Cast'))
                        ->helperText(__('Full cast information.'))
                        ->placeholder(__('Complete cast list'))
                        ->rows(3)
                        ->columnSpanFull()
                        ->rules(['nullable', 'string', 'max:2000']),

                    // Descriptions
                    Textarea::make('info.description')
                        ->label(__('Description'))
                        ->helperText(__('Short description of the content.'))
                        ->placeholder(__('Brief description...'))
                        ->rows(3)
                        ->columnSpanFull()
                        ->rules(['nullable', 'string', 'max:2000']),
                    Textarea::make('info.plot')
                        ->label(__('Plot'))
                        ->helperText(__('Detailed plot summary.'))
                        ->placeholder(__('Detailed plot summary...'))
                        ->rows(4)
                        ->columnSpanFull()
                        ->rules(['nullable', 'string', 'max:5000']),

                    // Array fields using repeaters
                    Repeater::make('info.backdrop_path')
                        ->label(__('Backdrop Images'))
                        ->helperText(__('Add backdrop/poster image URLs for this content.'))
                        ->columnSpanFull()
                        ->simple(
                            TextInput::make('url')
                                ->label(__('Image URL'))
                                ->placeholder(__('https://example.com/backdrop.jpg'))
                                ->type('url')
                                ->required()
                                ->rules(['url', 'max:500'])
                        )
                        ->addActionLabel('Add backdrop image')
                        ->reorderable()
                        ->collapsible()
                        ->defaultItems(0)
                        ->minItems(0)
                        ->formatStateUsing(function ($state) {
                            if (! is_array($state)) {
                                return [];
                            }
                            // Filter out empty values and convert to repeater format
                            $filtered = array_filter($state, function ($url) {
                                return ! empty(trim($url));
                            });

                            return array_map(fn ($url) => ['url' => $url], array_values($filtered));
                        })
                        ->dehydrateStateUsing(function ($state) {
                            if (! is_array($state)) {
                                return [];
                            }
                            // Convert repeater format back to simple array of URLs, filtering out empty values
                            $urls = array_column($state, 'url');
                            $filtered = array_filter($urls, function ($url) {
                                return ! empty(trim($url));
                            });

                            return array_values($filtered); // Re-index the array
                        }),

                    Repeater::make('info.subtitles')
                        ->label(__('Subtitles'))
                        ->helperText(__('Add available subtitle languages for this content.'))
                        ->columnSpanFull()
                        ->simple(
                            TextInput::make('language')
                                ->label(__('Language'))
                                ->placeholder(__('English, Spanish, French, etc.'))
                                ->required()
                                ->rules(['string', 'max:100'])
                        )
                        ->addActionLabel('Add subtitle language')
                        ->reorderable()
                        ->collapsible()
                        ->defaultItems(0)
                        ->minItems(0)
                        ->formatStateUsing(function ($state) {
                            if (! is_array($state)) {
                                return [];
                            }
                            // Filter out empty values and convert to repeater format
                            $filtered = array_filter($state, function ($language) {
                                return ! empty(trim($language));
                            });

                            return array_map(fn ($language) => ['language' => $language], array_values($filtered));
                        })
                        ->dehydrateStateUsing(function ($state) {
                            if (! is_array($state)) {
                                return [];
                            }
                            // Convert repeater format back to simple array of languages, filtering out empty values
                            $languages = array_column($state, 'language');
                            $filtered = array_filter($languages, function ($language) {
                                return ! empty(trim($language));
                            });

                            return array_values($filtered); // Re-index the array
                        }),

                ]),

            Fieldset::make(__('Stream file settings'))
                ->schema([
                    Grid::make(1)
                        ->schema([
                            Select::make('stream_file_setting_id')
                                ->label(__('Stream File Setting Profile'))
                                ->searchable()
                                ->relationship('streamFileSetting', 'name', fn ($query) => $query->forVod()->where('user_id', auth()->id())
                                )
                                ->nullable()
                                ->hintAction(
                                    Action::make('manage_stream_file_settings')
                                        ->label(__('Manage Stream File Settings'))
                                        ->icon('heroicon-o-arrow-top-right-on-square')
                                        ->iconPosition('after')
                                        ->size('sm')
                                        ->url('/stream-file-settings')
                                        ->openUrlInNewTab(false)
                                )
                                ->hintAction(
                                    Action::make('global_settings')
                                        ->label(__('Global Settings'))
                                        ->icon('heroicon-o-cog-6-tooth')
                                        ->iconPosition('after')
                                        ->size('sm')
                                        ->url('/preferences?tab=sync-options%3A%3Adata%3A%3Atab')
                                        ->openUrlInNewTab(false)
                                )
                                ->helperText(__('Select a Stream File Setting profile to override global/group settings for this VOD channel. Leave empty to use group or global settings. Priority: VOD > Group > Global.')),
                            TextInput::make('sync_location')
                                ->label(__('Location Override'))
                                ->rules([new CheckIfUrlOrLocalPath(localOnly: true, isDirectory: true)])
                                ->helperText(__('Override the sync location from the profile. Leave empty to use profile location.'))
                                ->maxLength(255)
                                ->placeholder(__('/VOD/movies')),
                        ]),
                ]),

            Fieldset::make(__('Failover Channels'))
                ->schema([
                    Repeater::make('failovers')
                        ->relationship()
                        ->label('')
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->orderColumn('sort')
                        ->simple(
                            Select::make('channel_failover_id')
                                ->label(__('Failover Channel'))
                                ->options(function ($state, $record) {
                                    // Get the current channel ID to exclude it from options
                                    if (! $state) {
                                        return [];
                                    }
                                    $channel = Channel::find($state);
                                    if (! $channel) {
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
                                        ->filter(fn ($failover) => $failover['channel_failover_id'] ?? null)
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
                        ->defaultItems(0),
                ]),
        ];
    }

    /**
     * Create a custom channel with the provided data.
     *
     * This method is used to create a channel with custom data, typically for a Custom Playlist.
     *
     * @param  array  $data  The data for the channel.
     * @param  string  $model  The model class to use for creating the channel.
     * @return Model The created channel model.
     *
     * @throws ValidationException
     * @throws ModelNotFoundException
     * @throws QueryException
     * @throws Exception
     */
    public static function createCustomChannel(array $data, string $model): Model
    {
        $data['user_id'] = auth()->id();
        $data['is_custom'] = true;
        $data['is_vod'] = true;
        if (! $data['shift']) {
            $data['shift'] = 0; // Default shift to 0 if not provided
        }
        if (! $data['logo_type']) {
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
