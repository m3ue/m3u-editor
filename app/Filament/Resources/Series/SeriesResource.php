<?php

namespace App\Filament\Resources\Series;

use App\Facades\LogoFacade;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\Series\Pages\CreateSeries;
use App\Filament\Resources\Series\Pages\EditSeries;
use App\Filament\Resources\Series\Pages\ListSeries;
use App\Filament\Resources\Series\Pages\ViewSeries;
use App\Filament\Resources\Series\RelationManagers\EpisodesRelationManager;
use App\Jobs\FetchTmdbIds;
use App\Jobs\ProcessM3uImportSeriesEpisodes;
use App\Jobs\SeriesFindAndReplace;
use App\Jobs\SyncSeriesStrmFiles;
use App\Models\Category;
use App\Models\Playlist;
use App\Models\Series;
use App\Rules\CheckIfUrlOrLocalPath;
use App\Services\LogoCacheService;
use App\Services\PlaylistService;
use App\Services\XtreamService;
use App\Settings\GeneralSettings;
use App\Traits\HasUserFiltering;
use Carbon\Carbon;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SeriesResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = Series::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'plot', 'genre', 'release_date', 'director'];
    }

    protected static string|\UnitEnum|null $navigationGroup = 'Series';

    public static function getNavigationSort(): ?int
    {
        return 4;
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
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['playlist']);
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns(self::getTableColumns(showCategory: ! $relationId, showPlaylist: ! $relationId))
            ->filters(self::getTableFilters(showPlaylist: ! $relationId))
            ->recordActions(self::getTableActions(), position: RecordActionsPosition::BeforeCells)
            ->toolbarActions(self::getTableBulkActions());
    }

    public static function getTableColumns($showCategory = true, $showPlaylist = true): array
    {
        return [
            ImageColumn::make('cover')
                ->width(80)
                ->height(120)
                ->checkFileExistence(false)
                ->getStateUsing(fn ($record) => LogoFacade::getSeriesLogoUrl($record))
                ->searchable(),
            TextColumn::make('name')
                ->description((fn ($record) => Str::limit($record->plot, 200)))
                ->wrap()
                ->extraAttributes(['style' => 'min-width: 350px;'])
                ->searchable(query: function (Builder $query, string $search): Builder {
                    return $query->orWhereRaw('LOWER(series.name) LIKE ?', ['%'.strtolower($search).'%']);
                })
                ->sortable(),
            TextInputColumn::make('sort')
                ->label('Sort Order')
                ->rules(['min:0'])
                ->type('number')
                ->placeholder('Sort Order')
                ->sortable()
                ->tooltip(fn ($record) => ! $record->is_custom && $record->playlist?->auto_sort ? 'Playlist auto-sort enabled; any changes will be overwritten on next sync' : 'Channel sort order')
                ->toggleable(),
            ToggleColumn::make('enabled')
                ->toggleable()
                ->tooltip('Toggle series status')
                ->sortable(),
            IconColumn::make('has_metadata')
                ->label('TMDB/TVDB')
                ->boolean()
                ->trueIcon('heroicon-m-check-circle')
                ->falseIcon('heroicon-m-minus-circle')
                ->trueColor('success')
                ->falseColor('gray')
                ->tooltip(function ($record): string {
                    if ($record->has_metadata) {
                        $ids = [];
                        if ($record->tmdb_id || ($record->metadata['tmdb_id'] ?? null)) {
                            $ids[] = 'TMDB: '.($record->tmdb_id ?? $record->metadata['tmdb_id']);
                        }
                        if ($record->tvdb_id || ($record->metadata['tvdb_id'] ?? null)) {
                            $ids[] = 'TVDB: '.($record->tvdb_id ?? $record->metadata['tvdb_id']);
                        }
                        if ($record->imdb_id || ($record->metadata['imdb_id'] ?? null)) {
                            $ids[] = 'IMDB: '.($record->imdb_id ?? $record->metadata['imdb_id']);
                        }

                        return implode(' | ', $ids);
                    }

                    return 'No TMDB/TVDB/IMDB IDs available';
                })
                ->toggleable(),
            TextColumn::make('seasons_count')
                ->label('Seasons')
                ->counts('seasons')
                ->badge()
                ->toggleable()
                ->sortable(),
            TextColumn::make('episodes_count')
                ->label('Episodes')
                ->counts('episodes')
                ->badge()
                ->toggleable()
                ->sortable(),
            TextColumn::make('category.name')
                ->hidden(fn () => ! $showCategory)
                ->badge()
                ->numeric()
                ->sortable(),
            TextColumn::make('genre')
                ->searchable(),
            TextColumn::make('youtube_trailer')
                ->label('YouTube Trailer')
                ->placeholder('No trailer ID set.')
                ->url(fn ($record): string => 'https://www.youtube.com/watch?v='.$record->youtube_trailer)
                ->openUrlInNewTab()
                ->icon('heroicon-s-play'),
            TextColumn::make('release_date')
                ->searchable(),
            TextColumn::make('rating')
                ->badge()
                ->color('success')
                ->icon('heroicon-m-star')
                ->searchable(),
            TextColumn::make('rating_5based')
                ->badge()
                ->color('success')
                ->icon('heroicon-m-star')
                ->sortable(),
            TextColumn::make('playlist.name')
                ->numeric()
                ->sortable()
                ->hidden(fn () => ! $showPlaylist),
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
                ->multiple()
                ->preload()
                ->searchable()
                ->hidden(fn () => ! $showPlaylist),
            Filter::make('enabled')
                ->label('Series is enabled')
                ->toggle()
                ->query(function ($query) {
                    return $query->where('enabled', true);
                }),
            Filter::make('has_metadata')
                ->label('Has TMDB/TVDB/IMDB ID')
                ->toggle()
                ->query(function ($query) {
                    return $query->where(function ($q) {
                        $q->whereNotNull('tmdb_id')
                            ->orWhereNotNull('tvdb_id')
                            ->orWhereNotNull('imdb_id')
                            ->orWhereRaw("metadata::jsonb ?? 'tmdb_id'")
                            ->orWhereRaw("metadata::jsonb ?? 'tvdb_id'")
                            ->orWhereRaw("metadata::jsonb ?? 'imdb_id'");
                    });
                }),
            Filter::make('missing_metadata')
                ->label('Missing TMDB/TVDB/IMDB ID')
                ->toggle()
                ->query(function ($query) {
                    return $query->whereNull('tmdb_id')
                        ->whereNull('tvdb_id')
                        ->whereNull('imdb_id')
                        ->where(function ($q) {
                            $q->whereNull('metadata')
                                ->orWhereRaw("NOT (metadata::jsonb ?? 'tmdb_id')")
                                ->orWhereRaw("NOT (metadata::jsonb ?? 'tvdb_id')")
                                ->orWhereRaw("NOT (metadata::jsonb ?? 'imdb_id')");
                        });
                }),
        ];
    }

    public static function getTableActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('move')
                    ->label('Move Series to Category')
                    ->schema([
                        Select::make('category')
                            ->required()
                            ->live()
                            ->label('Category')
                            ->helperText('Select the category you would like to move the series to.')
                            ->options(fn (Get $get, $record) => Category::where(['user_id' => auth()->id(), 'playlist_id' => $record->playlist_id])->get(['name', 'id'])->pluck('name', 'id'))
                            ->searchable(),
                    ])
                    ->action(function ($record, array $data): void {
                        $category = Category::findOrFail($data['category']);
                        $record->update([
                            'category_id' => $category->id,
                        ]);
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Series moved to category')
                            ->body('The series has been moved to the chosen category.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-right-left')
                    ->modalIcon('heroicon-o-arrows-right-left')
                    ->modalDescription('Move the series to another category.')
                    ->modalSubmitActionLabel('Move now'),
                Action::make('fetch_tmdb_ids')
                    ->label('Fetch TMDB/TVDB IDs')
                    ->icon('heroicon-o-film')
                    ->modalIcon('heroicon-o-film')
                    ->modalDescription('Fetch TMDB, TVDB, and IMDB IDs for this series from The Movie Database.')
                    ->modalSubmitActionLabel('Fetch IDs now')
                    ->action(function ($record) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new FetchTmdbIds(
                                seriesIds: [$record->id]
                            ));
                    })
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title('TMDB Search Started')
                            ->body('Searching for TMDB/TVDB IDs. Check the logs or refresh the page in a few seconds.')
                            ->duration(8000)
                            ->send();
                    })
                    ->requiresConfirmation(),
                Action::make('manual_tmdb_search')
                    ->label('Manual TMDB Search')
                    ->icon('heroicon-o-magnifying-glass')
                    ->slideOver()
                    ->modalWidth('4xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->fillForm(fn ($record) => [
                        'search_query' => $record->name,
                        'search_year' => $record->release_date ? (int) substr($record->release_date, 0, 4) : null,
                        'series_id' => $record->id,
                        'current_tmdb_id' => $record->tmdb_id,
                        'current_tvdb_id' => $record->tvdb_id,
                        'current_imdb_id' => $record->imdb_id,
                    ])
                    ->schema([
                        Section::make('Current IDs')
                            ->description('Currently stored external IDs for this series')
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        TextInput::make('current_tmdb_id')
                                            ->label('TMDB ID')
                                            ->disabled()
                                            ->placeholder('Not set'),
                                        TextInput::make('current_tvdb_id')
                                            ->label('TVDB ID')
                                            ->disabled()
                                            ->placeholder('Not set'),
                                        TextInput::make('current_imdb_id')
                                            ->label('IMDB ID')
                                            ->disabled()
                                            ->placeholder('Not set'),
                                    ]),
                            ])
                            ->collapsible()
                            ->collapsed(),
                        Section::make('Search TMDB')
                            ->description('Search The Movie Database for this series')
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        TextInput::make('search_query')
                                            ->label('Search Query')
                                            ->placeholder('Enter series name...')
                                            ->required()
                                            ->columnSpan(2),
                                        TextInput::make('search_year')
                                            ->label('Year (optional)')
                                            ->numeric()
                                            ->minValue(1900)
                                            ->maxValue(2100)
                                            ->placeholder('e.g. 2024'),
                                    ]),
                                Actions::make([
                                    Action::make('search_tmdb')
                                        ->label('Search TMDB')
                                        ->icon('heroicon-o-magnifying-glass')
                                        ->action(function (Get $get, Set $set) {
                                            $query = $get('search_query');
                                            $year = $get('search_year');

                                            if (empty($query)) {
                                                Notification::make()
                                                    ->warning()
                                                    ->title('Please enter a search query')
                                                    ->send();

                                                return;
                                            }

                                            try {
                                                $tmdbService = app(\App\Services\TmdbService::class);
                                                $results = $tmdbService->searchTvSeriesManual($query, $year);
                                                $set('search_results', $results);
                                            } catch (\Exception $e) {
                                                Notification::make()
                                                    ->danger()
                                                    ->title('Search Error')
                                                    ->body($e->getMessage())
                                                    ->send();
                                            }
                                        }),
                                ])->fullWidth(),
                            ]),
                        Section::make('Search Results')
                            ->description('Click on a result to apply the TMDB IDs')
                            ->schema([
                                Forms\Components\Hidden::make('series_id'),
                                \App\Forms\Components\TmdbSearchResults::make('search_results')
                                    ->type('tv')
                                    ->default([]),
                            ]),
                    ]),
                Action::make('process')
                    ->label('Fetch Series Metadata')
                    ->schema([
                        Toggle::make('overwrite_existing')
                            ->label('Overwrite Existing Metadata')
                            ->helperText('Overwrite existing metadata? If disabled, it will only fetch and process episodes for the Series.')
                            ->default(false),
                    ])
                    ->action(function ($record, array $data) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ProcessM3uImportSeriesEpisodes(
                                playlistSeries: $record,
                                overwrite_existing: $data['overwrite_existing'] ?? false,
                                sync_stream_files: false,
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Series is being processed')
                            ->body('You will be notified once complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription('Process series now? This will fetch all episodes and seasons for this series.')
                    ->modalSubmitActionLabel('Yes, process now'),
                Action::make('sync')
                    ->label('Sync Series .strm files')
                    ->action(function ($record) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new SyncSeriesStrmFiles(
                                series: $record,
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Series .strm files are being synced')
                            ->body('You will be notified once complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-arrow-down')
                    ->modalIcon('heroicon-o-document-arrow-down')
                    ->modalDescription('Sync series .strm files now? This will generate .strm files for this series at the path set for this series.')
                    ->modalSubmitActionLabel('Yes, sync now'),
                DeleteAction::make()
                    ->modalIcon('heroicon-o-trash')
                    ->modalDescription('Are you sure you want to delete this series? This will delete all episodes and seasons for this series. This action cannot be undone.')
                    ->modalSubmitActionLabel('Yes, delete series'),
            ])->button()->hiddenLabel()->size('sm'),
            EditAction::make()
                ->slideOver()
                ->button()->hiddenLabel()->size('sm')
                    // Refresh table after edit to remove records that no longer match active filters
                ->after(fn ($livewire) => $livewire->dispatch('$refresh')),
            ViewAction::make()
                ->url(fn ($record) => static::getUrl('view', ['record' => $record]))
                ->button()->hiddenLabel()->size('sm')
                ->icon('heroicon-s-eye')
                ->tooltip('View enhanced details'),
        ];
    }

    public static function getTableBulkActions($addToCustom = true): array
    {
        return [
            BulkActionGroup::make([
                PlaylistService::getAddToPlaylistBulkAction('add', 'series')
                    ->hidden(fn () => ! $addToCustom),
                BulkAction::make('move')
                    ->label('Move Series to Category')
                    ->schema([
                        Select::make('category')
                            ->required()
                            ->live()
                            ->label('Category')
                            ->helperText('Select the category you would like to move the series to.')
                            ->options(
                                fn () => Category::query()
                                    ->with(['playlist'])
                                    ->where(['user_id' => auth()->id()])
                                    ->get(['name', 'id', 'playlist_id'])
                                    ->transform(fn ($category) => [
                                        'id' => $category->id,
                                        'name' => $category->name.' ('.$category->playlist->name.')',
                                    ])->pluck('name', 'id')
                            )->searchable(),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $category = Category::findOrFail($data['category']);
                        foreach ($records as $record) {
                            // Update the series to the new category
                            // This will change the category_id for the series in the database
                            // to reflect the new category
                            if ($category->playlist_id !== $record->playlist_id) {
                                Notification::make()
                                    ->warning()
                                    ->title('Warning')
                                    ->body("Cannot move \"{$category->name}\" to \"{$record->name}\" as they belong to different playlists.")
                                    ->persistent()
                                    ->send();

                                continue;
                            }
                            $record->update([
                                'category_id' => $category->id,
                            ]);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Series moved to category')
                            ->body('The category series have been moved to the chosen category.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-right-left')
                    ->modalIcon('heroicon-o-arrows-right-left')
                    ->modalDescription('Move the category series to another category.')
                    ->modalSubmitActionLabel('Move now'),
                BulkAction::make('process')
                    ->label('Fetch Series Metadata')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->schema([
                        Toggle::make('overwrite_existing')
                            ->label('Overwrite Existing Metadata')
                            ->helperText('Overwrite existing metadata? Episodes and seasons will always be fetched/updated.')
                            ->default(false),
                    ])
                    ->action(function ($records, array $data) {
                        foreach ($records as $record) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new ProcessM3uImportSeriesEpisodes(
                                    playlistSeries: $record,
                                    overwrite_existing: $data['overwrite_existing'] ?? false,
                                ));
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Series are being processed')
                            ->body('You will be notified once complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription('Process selected series now? This will fetch all episodes and seasons for this series. This may take a while depending on the number of series selected.')
                    ->modalSubmitActionLabel('Yes, process now'),
                BulkAction::make('set_poster_url')
                    ->label('Set poster URL')
                    ->schema([
                        TextInput::make('cover')
                            ->label('Series poster URL')
                            ->url()
                            ->nullable()
                            ->helperText('Leave empty to remove custom poster URL and use placeholder fallback.'),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        Series::whereIn('id', $records->pluck('id')->toArray())
                            ->update([
                                'cover' => empty($data['cover']) ? null : $data['cover'],
                            ]);
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Poster URL updated')
                            ->body('The poster URL has been updated for the selected series.')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-link')
                    ->modalIcon('heroicon-o-link')
                    ->modalDescription('Apply a single poster URL to all selected series. Leave empty to remove custom posters.')
                    ->modalSubmitActionLabel('Apply URL'),
                BulkAction::make('refresh_logo_cache')
                    ->label('Refresh poster cache (selected)')
                    ->action(function (Collection $records): void {
                        $urls = $records
                            ->pluck('cover')
                            ->filter()
                            ->values()
                            ->toArray();

                        $cleared = LogoCacheService::clearByUrls($urls);

                        Notification::make()
                            ->success()
                            ->title('Selected series cache refreshed')
                            ->body("Removed {$cleared} cache file(s) for selected series posters.")
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-path')
                    ->modalIcon('heroicon-o-arrow-path')
                    ->modalDescription('Clear cached poster images for selected series so they are fetched again on the next request.')
                    ->modalSubmitActionLabel('Refresh selected cache'),
                BulkAction::make('fetch_tmdb_ids')
                    ->label('Fetch TMDB/TVDB IDs')
                    ->icon('heroicon-o-magnifying-glass')
                    ->schema([
                        Toggle::make('overwrite_existing')
                            ->label('Overwrite Existing IDs')
                            ->helperText('Overwrite existing TMDB/TVDB/IMDB IDs? If disabled, it will only fetch IDs for series that don\'t already have them.')
                            ->default(false),
                    ])
                    ->action(function ($records, $data) {
                        $settings = app(GeneralSettings::class);
                        if (empty($settings->tmdb_api_key)) {
                            Notification::make()
                                ->danger()
                                ->title('TMDB API Key Required')
                                ->body('Please configure your TMDB API key in Settings > TMDB before using this feature.')
                                ->duration(10000)
                                ->send();

                            return;
                        }

                        $seriesIds = $records->pluck('id')->toArray();

                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new FetchTmdbIds(
                                vodChannelIds: null,
                                seriesIds: $seriesIds,
                                overwriteExisting: $data['overwrite_existing'] ?? false,
                                user: auth()->user(),
                            ));

                        Notification::make()
                            ->success()
                            ->title('Fetching TMDB/TVDB IDs for '.count($seriesIds).' series')
                            ->body('The TMDB ID lookup has been started. You will be notified when it is complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-magnifying-glass')
                    ->modalDescription('Search TMDB for matching TV series and populate TMDB/TVDB/IMDB IDs for the selected series? This enables Trash Guides compatibility for Sonarr.')
                    ->modalSubmitActionLabel('Yes, fetch IDs now'),
                BulkAction::make('sync')
                    ->label('Sync Series .strm files')
                    ->action(function ($records) {
                        foreach ($records as $record) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new SyncSeriesStrmFiles(
                                    series: $record,
                                ));
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('.strm files are being synced for selected series')
                            ->body('You will be notified once complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-arrow-down')
                    ->modalIcon('heroicon-o-document-arrow-down')
                    ->modalDescription('Sync selected series .strm files now? This will generate .strm files for the selected series at the path set for the series.')
                    ->modalSubmitActionLabel('Yes, sync now'),

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
                                'name' => 'Series Name',
                                'genre' => 'Genre',
                                'plot' => 'Plot',
                            ])
                            ->default('name')
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
                            ->label('Replace with (optional)')
                            ->placeholder('Leave empty to remove'),

                    ])
                    ->action(function (Collection $records, array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new SeriesFindAndReplace(
                                user_id: auth()->id(), // The ID of the user who owns the content
                                use_regex: $data['use_regex'] ?? true,
                                column: $data['column'] ?? 'title',
                                find_replace: $data['find_replace'] ?? null,
                                replace_with: $data['replace_with'] ?? '',
                                series: $records
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
                    ->modalDescription('Select what you would like to find and replace in the selected epg channels.')
                    ->modalSubmitActionLabel('Replace now'),

                BulkAction::make('enable')
                    ->label('Enable selected')
                    ->action(function (Collection $records): void {
                        foreach ($records->chunk(100) as $chunk) {
                            Series::whereIn('id', $chunk->pluck('id'))->update(['enabled' => true]);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Selected series enabled')
                            ->body('The selected series have been enabled.')
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
                            Series::whereIn('id', $chunk->pluck('id'))->update(['enabled' => false]);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Selected series disabled')
                            ->body('The selected series have been disabled.')
                            ->send();
                    })
                    ->color('warning')
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-x-circle')
                    ->modalIcon('heroicon-o-x-circle')
                    ->modalDescription('Disable the selected channel(s) now?')
                    ->modalSubmitActionLabel('Yes, disable now'),
                DeleteBulkAction::make(),
            ]),
        ];
    }

    public static function getRelations(): array
    {
        return [
            EpisodesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSeries::route('/'),
            'create' => CreateSeries::route('/create'),
            'view' => ViewSeries::route('/{record}'),
            'edit' => EditSeries::route('/{record}/edit'),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Group Details')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->badge(),
                        TextEntry::make('playlist.name')
                            ->label('Playlist')
                            // ->badge(),
                            ->url(fn ($record) => PlaylistResource::getUrl('edit', ['record' => $record->playlist_id])),
                    ]),
            ]);
    }

    public static function getForm($customPlaylist = null): array
    {
        return [
            Grid::make()
                ->columns(4)
                ->schema([
                    Section::make('Series Details')
                        ->columnSpan(2)
                        ->icon('heroicon-o-pencil')
                        ->description('Edit or add the series details')
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    TextInput::make('name')
                                        ->maxLength(255),
                                    Toggle::make('enabled')
                                        ->inline(false)
                                        ->required(),
                                    Select::make('category_id')
                                        ->relationship('category', 'name'),
                                    TextInput::make('cover')
                                        ->maxLength(255),
                                    Textarea::make('plot')
                                        ->columnSpanFull(),
                                    TextInput::make('genre')
                                        ->maxLength(255),
                                    DatePicker::make('release_date')
                                        ->label('Release Date')
                                        ->dehydrateStateUsing(function ($state) {
                                            // Ensure we store a properly formatted date
                                            if ($state) {
                                                try {
                                                    return Carbon::parse($state)->format('Y-m-d');
                                                } catch (Exception $e) {
                                                    return null;
                                                }
                                            }

                                            return null;
                                        })
                                        ->formatStateUsing(function ($state) {
                                            // Extract just the date part for display
                                            if ($state) {
                                                try {
                                                    if (preg_match('/(\d{4}-\d{2}-\d{2})/', $state, $matches)) {
                                                        return $matches[1];
                                                    }

                                                    return Carbon::parse($state)->format('Y-m-d');
                                                } catch (Exception $e) {
                                                    return null;
                                                }
                                            }

                                            return null;
                                        }),
                                    TextInput::make('rating')
                                        ->maxLength(255),
                                    TextInput::make('rating_5based')
                                        ->label('Rating (5 based)')
                                        ->numeric(),
                                    Textarea::make('cast')
                                        ->columnSpanFull(),
                                    TextInput::make('director')
                                        ->maxLength(255),
                                    TextInput::make('backdrop_path'),
                                    TextInput::make('youtube_trailer')
                                        ->label('YouTube Trailer ID')
                                        ->maxLength(255),
                                ]),
                        ]),
                    Section::make('Stream file settings')
                        ->columnSpan(2)
                        ->icon('heroicon-o-cog')
                        ->description('Override global .strm file generation settings for this series.')
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            Grid::make(1)
                                ->schema([
                                    Select::make('stream_file_setting_id')
                                        ->label('Stream File Setting Profile')
                                        ->searchable()
                                        ->relationship('streamFileSetting', 'name', fn ($query) => $query->forSeries()->where('user_id', auth()->id())
                                        )
                                        ->nullable()
                                        ->hintAction(
                                            Action::make('manage_stream_file_settings')
                                                ->label('Manage Stream File Settings')
                                                ->icon('heroicon-o-arrow-top-right-on-square')
                                                ->iconPosition('after')
                                                ->size('sm')
                                                ->url('/stream-file-settings')
                                                ->openUrlInNewTab(false)
                                        )
                                        ->hintAction(
                                            Action::make('global_settings')
                                                ->label('Global Settings')
                                                ->icon('heroicon-o-cog-6-tooth')
                                                ->iconPosition('after')
                                                ->size('sm')
                                                ->url('/preferences?tab=sync-options%3A%3Adata%3A%3Atab')
                                                ->openUrlInNewTab(false)
                                        )
                                        ->helperText('Select a Stream File Setting profile to override global/category settings for this series. Leave empty to use category or global settings.'),
                                    TextInput::make('sync_location')
                                        ->label('Location Override')
                                        ->rules([new CheckIfUrlOrLocalPath(localOnly: true, isDirectory: true)])
                                        ->helperText('Override the sync location from the profile. Leave empty to use profile location.')
                                        ->maxLength(255)
                                        ->placeholder('/Series'),
                                ]),
                        ]),
                ]),
        ];
    }

    public static function getFormSteps(): array
    {
        return [
            Step::make('Playlist')
                ->schema([
                    Select::make('playlist')
                        ->required()
                        ->label('Playlist')
                        ->helperText('Select the playlist you would like to import series from.')
                        ->options(Playlist::where([
                            ['user_id', auth()->id()],
                            ['xtream', true],
                        ])->get(['name', 'id'])->pluck('name', 'id'))
                        ->searchable(),
                ]),
            Step::make('Category')
                ->schema([
                    Select::make('category')
                        ->label('Series Category')
                        ->live()
                        ->required()
                        ->searchable()
                        ->columnSpanFull()
                        ->options(function ($get) {
                            $playlist = $get('playlist');
                            if (! $playlist) {
                                return [];
                            }
                            $xtreamConfig = Playlist::find($playlist)->xtream_config ?? [];
                            $xtremeUrl = $xtreamConfig['url'] ?? '';
                            $xtreamUser = $xtreamConfig['username'] ?? '';
                            $xtreamPass = $xtreamConfig['password'] ?? '';
                            $cacheKey = 'xtream_series_categories_'.md5($xtremeUrl.$xtreamUser.$xtreamPass);
                            $cachedCategories = Cache::remember($cacheKey, 60 * 1, function () use ($xtremeUrl, $xtreamUser, $xtreamPass) {
                                $service = new XtreamService;
                                $xtream = $service->init(xtream_config: [
                                    'url' => $xtremeUrl,
                                    'username' => $xtreamUser,
                                    'password' => $xtreamPass,
                                ]);
                                $userInfo = $xtream->authenticate();
                                if (! ($userInfo['auth'] ?? false)) {
                                    return [];
                                }
                                $seriesCategories = $xtream->getSeriesCategories();

                                return collect($seriesCategories)
                                    ->map(function ($category) {
                                        return [
                                            'label' => $category['category_name'],
                                            'value' => $category['category_id'],
                                        ];
                                    })->pluck('label', 'value')->toArray();
                            });

                            return $cachedCategories;
                        })
                        ->helperText(
                            fn (Get $get): string => $get('playlist')
                                ? 'Which category would you like to add a series from.'
                                : 'You must select a playlist first.'
                        )
                        ->disabled(fn (Get $get): bool => ! $get('playlist'))
                        ->hidden(fn (Get $get): bool => ! $get('playlist'))
                        ->afterStateUpdated(function ($get, $set, $state) {
                            if ($state) {
                                $playlist = $get('playlist');
                                if (! $playlist) {
                                    return;
                                }
                                $xtreamConfig = Playlist::find($playlist)->xtream_config ?? [];
                                $xtremeUrl = $xtreamConfig['url'] ?? '';
                                $xtreamUser = $xtreamConfig['username'] ?? '';
                                $xtreamPass = $xtreamConfig['password'] ?? '';
                                $cacheKey = 'xtream_series_categories_'.md5($xtremeUrl.$xtreamUser.$xtreamPass);
                                $cachedCategories = Cache::get($cacheKey);

                                if ($cachedCategories) {
                                    $category = $cachedCategories[$state] ?? null;
                                    if ($category) {
                                        $set('category_name', $category);
                                    }
                                }
                            }
                        }),
                    TextInput::make('category_name')
                        ->label('Category Name')
                        ->helperText('Automatically set when selecting a category.')
                        ->required()
                        ->disabled()
                        ->dehydrated(fn (): bool => true),
                ]),
            Step::make('Series to Import')
                ->schema([
                    Toggle::make('import_all')
                        ->label('Import All Series')
                        ->onColor('warning')
                        ->hint('Use with caution')
                        ->live()
                        ->helperText('If enabled, all series in the selected category will be imported. Use with caution as this will make a lot of requests to your provider to fetch metadata and episodes. It is recomended to import only the series you want to watch. You can also enable the series option on your playlist under the "Groups and Streams to Import" to import all the base data for all available series.')
                        ->default(false)
                        ->columnSpanFull()
                        ->afterStateUpdated(function (Get $get, $set) {
                            if ($get('import_all')) {
                                $set('series', []);
                            }
                        }),
                    CheckboxList::make('series')
                        ->label('Series to Import')
                        ->required()
                        ->searchable()
                        ->columnSpanFull()
                        ->columns(4)
                        ->options(function ($get) {
                            $playlist = $get('playlist');
                            $category = $get('category');
                            if (! $playlist) {
                                return [];
                            }
                            $xtreamConfig = Playlist::find($playlist)->xtream_config ?? [];
                            $xtremeUrl = $xtreamConfig['url'] ?? '';
                            $xtreamUser = $xtreamConfig['username'] ?? '';
                            $xtreamPass = $xtreamConfig['password'] ?? '';
                            $cacheKey = 'xtream_category_series'.md5($xtremeUrl.$xtreamUser.$xtreamPass.$category);
                            $cachedCategories = Cache::remember($cacheKey, 60 * 1, function () use ($xtremeUrl, $xtreamUser, $xtreamPass, $category) {
                                $xtream = XtreamService::make(xtream_config: [
                                    'url' => $xtremeUrl,
                                    'username' => $xtreamUser,
                                    'password' => $xtreamPass,
                                ]);
                                $userInfo = $xtream->authenticate();
                                if (! ($userInfo['auth'] ?? false)) {
                                    return [];
                                }
                                $series = $xtream->getSeries($category);

                                return collect($series)
                                    ->map(function ($s) {
                                        return [
                                            'label' => $s['name'],
                                            'value' => $s['series_id'],
                                        ];
                                    })->pluck('label', 'value')->toArray();
                            });

                            return $cachedCategories;
                        })
                        ->helperText(
                            fn (Get $get): string => $get('playlist') && $get('category')
                                ? 'Which series would you like to import.'
                                : 'You must select a playlist and category first.'
                        )
                        ->disabled(fn (Get $get): bool => ! $get('playlist') || ! $get('category') || $get('import_all'))
                        ->hidden(fn (Get $get): bool => ! $get('playlist') || ! $get('category') || $get('import_all')),
                ]),
        ];
    }
}
