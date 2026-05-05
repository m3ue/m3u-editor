<?php

namespace App\Filament\Resources\Series;

use App\Facades\LogoFacade;
use App\Filament\Actions\AssetPickerAction;
use App\Filament\Actions\BulkModalActionGroup;
use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\Series\Pages\CreateSeries;
use App\Filament\Resources\Series\Pages\EditSeries;
use App\Filament\Resources\Series\Pages\ListSeries;
use App\Filament\Resources\Series\Pages\ViewSeries;
use App\Filament\Resources\Series\RelationManagers\EpisodesRelationManager;
use App\Forms\Components\TmdbSearchResults;
use App\Jobs\FetchTmdbIds;
use App\Jobs\ProbeVodStreamsChunk;
use App\Jobs\ProcessM3uImportSeriesEpisodes;
use App\Jobs\SeriesFindAndReplace;
use App\Jobs\SyncSeriesStrmFiles;
use App\Models\Category;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Rules\CheckIfUrlOrLocalPath;
use App\Services\DateFormatService;
use App\Services\LogoCacheService;
use App\Services\PlaylistService;
use App\Services\TmdbService;
use App\Services\XtreamService;
use App\Settings\GeneralSettings;
use App\Traits\HasUserFiltering;
use Carbon\Carbon;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SeriesResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;
    use HasUserFiltering;

    protected static ?string $model = Series::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'plot', 'genre', 'release_date', 'director'];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Series');
    }

    public static function getModelLabel(): string
    {
        return __('Series');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Series');
    }

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
                return $action->button()->label(__('Filters'));
            })
            ->modifyQueryUsing(function (Builder $query) {
                $query->with([
                    'playlist' => fn ($q) => $q->select('id', 'name', 'uuid', 'auto_sort'),
                ]);
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('sort')
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
            IconColumn::make('has_metadata')
                ->label(__('TMDB/TVDB'))
                ->boolean()
                ->trueIcon('heroicon-m-check-circle')
                ->falseIcon('heroicon-m-minus-circle')
                ->trueColor('success')
                ->falseColor('gray')
                ->tooltip(function ($record): string {
                    $ids = $record->getMovieDbIds();
                    if (! empty($ids['tmdb']) || ! empty($ids['tvdb']) || ! empty($ids['imdb'])) {
                        $parts = [];
                        if (! empty($ids['tmdb'])) {
                            $parts[] = 'TMDB: '.$ids['tmdb'];
                        }
                        if (! empty($ids['tvdb'])) {
                            $parts[] = 'TVDB: '.$ids['tvdb'];
                        }
                        if (! empty($ids['imdb'])) {
                            $parts[] = 'IMDB: '.$ids['imdb'];
                        }

                        return implode(' | ', $parts);
                    }

                    return 'No TMDB/TVDB/IMDB IDs available';
                })
                ->toggleable(),
            TextColumn::make('seasons_count')
                ->label(__('Seasons'))
                ->counts('seasons')
                ->badge()
                ->toggleable()
                ->sortable(),
            TextColumn::make('episodes_count')
                ->label(__('Episodes'))
                ->counts('episodes')
                ->badge()
                ->toggleable()
                ->sortable(),
            TextColumn::make('probe_enabled_episodes_count')
                ->label(__('Probe Enabled'))
                ->counts(['episodes as probe_enabled_episodes_count' => fn ($q) => $q->where('probe_enabled', true)])
                ->badge()
                ->color(fn ($state) => $state > 0 ? 'success' : 'gray')
                ->tooltip(__('Episodes with probing enabled'))
                ->toggleable()
                ->sortable(),
            TextColumn::make('probed_episodes_count')
                ->label(__('Probed'))
                ->counts([
                    'episodes as probed_episodes_count' => fn ($q) => $q
                        ->whereNotNull('stream_stats_probed_at')
                        ->whereNotNull('stream_stats')
                        ->whereRaw("CAST(stream_stats AS TEXT) != '[]'"),
                ])
                ->badge()
                ->color(function ($state, $record): string {
                    if ($state === 0) {
                        return 'gray';
                    }

                    return $state >= ($record->probe_enabled_episodes_count ?? 0) ? 'success' : 'warning';
                })
                ->tooltip(__('Episodes successfully probed (with stream info)'))
                ->toggleable()
                ->sortable(),
            TextColumn::make('probe_failed_episodes_count')
                ->label(__('Probe Failed'))
                ->counts([
                    'episodes as probe_failed_episodes_count' => fn ($q) => $q
                        ->whereNotNull('stream_stats_probed_at')
                        ->where(fn ($q) => $q->whereNull('stream_stats')->orWhereRaw("CAST(stream_stats AS TEXT) = '[]'")),
                ])
                ->badge()
                ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                ->tooltip(__('Episodes where probe ran but returned no stream info'))
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
                ->label(__('YouTube Trailer'))
                ->placeholder(__('No trailer ID set.'))
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
                ->label(__('Has TMDB/TVDB/IMDB ID'))
                ->toggle()
                ->query(fn ($query) => $query->hasSeriesId()),
            Filter::make('missing_metadata')
                ->label(__('Missing TMDB/TVDB/IMDB ID'))
                ->toggle()
                ->query(fn ($query) => $query->missingSeriesId()),
            Filter::make('probe_enabled')
                ->label(__('Probe enabled'))
                ->toggle()
                ->query(fn ($query) => $query->whereHas('episodes', fn ($q) => $q->where('probe_enabled', true))),
            Filter::make('probed')
                ->label(__('Probed'))
                ->toggle()
                ->query(fn ($query) => $query->whereHas('episodes', fn ($q) => $q->whereNotNull('stream_stats_probed_at')
                    ->whereNotNull('stream_stats')->whereRaw("CAST(stream_stats AS TEXT) != '[]'"))),
            Filter::make('probe_failed')
                ->label(__('Probe failed'))
                ->toggle()
                ->query(fn ($query) => $query->whereHas('episodes', fn ($q) => $q->whereNotNull('stream_stats_probed_at')
                    ->where(fn ($qq) => $qq->whereNull('stream_stats')->orWhereRaw("CAST(stream_stats AS TEXT) = '[]'")))),
            Filter::make('not_probed')
                ->label(__('Not probed'))
                ->toggle()
                ->query(fn ($query) => $query->whereDoesntHave('episodes', fn ($q) => $q->whereNotNull('stream_stats_probed_at'))),
        ];
    }

    public static function getTableActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('move')
                    ->label(__('Move Series to Category'))
                    ->schema([
                        Select::make('category')
                            ->required()
                            ->live()
                            ->label(__('Category'))
                            ->helperText(__('Select the category you would like to move the series to.'))
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
                            ->title(__('Series moved to category'))
                            ->body(__('The series has been moved to the chosen category.'))
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-right-left')
                    ->modalIcon('heroicon-o-arrows-right-left')
                    ->modalDescription(__('Move the series to another category.'))
                    ->modalSubmitActionLabel(__('Move now')),
                Action::make('fetch_tmdb_ids')
                    ->label(__('Fetch TMDB/TVDB IDs'))
                    ->icon('heroicon-o-film')
                    ->modalIcon('heroicon-o-film')
                    ->modalDescription(__('Fetch TMDB, TVDB, and IMDB IDs for this series from The Movie Database.'))
                    ->modalSubmitActionLabel(__('Fetch IDs now'))
                    ->action(function ($record) {
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

                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new FetchTmdbIds(
                                seriesIds: [$record->id],
                                overwriteExisting: true,
                                user: auth()->user(),
                            ));

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
                        'search_query' => $record->name,
                        'search_year' => $record->release_date ? (int) substr($record->release_date, 0, 4) : null,
                        'series_id' => $record->id,
                        'current_tmdb_id' => $record->tmdb_id,
                        'current_tvdb_id' => $record->tvdb_id,
                        'current_imdb_id' => $record->imdb_id,
                    ])
                    ->schema([
                        Section::make(__('Current IDs'))
                            ->description(__('Currently stored external IDs for this series'))
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        TextInput::make('current_tmdb_id')
                                            ->label(__('TMDB ID'))
                                            ->disabled()
                                            ->placeholder(__('Not set')),
                                        TextInput::make('current_tvdb_id')
                                            ->label(__('TVDB ID'))
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
                            ->description(__('Search The Movie Database for this series'))
                            ->schema([
                                Grid::make(3)
                                    ->schema([
                                        TextInput::make('search_query')
                                            ->label(__('Search Query'))
                                            ->placeholder(__('Enter series name...'))
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
                                                $results = $tmdbService->searchTvSeriesManual($query, $year);
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
                                Forms\Components\Hidden::make('series_id'),
                                TmdbSearchResults::make('search_results')
                                    ->type('tv')
                                    ->default([]),
                            ]),
                    ]),
                Action::make('process')
                    ->label(__('Fetch Series Metadata'))
                    ->schema([
                        Toggle::make('overwrite_existing')
                            ->label(__('Overwrite Existing Metadata'))
                            ->helperText(__('Overwrite existing metadata? If disabled, it will only fetch and process episodes for the Series.'))
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
                            ->title(__('Series is being processed'))
                            ->body(__('You will be notified once complete.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription(__('Process series now? This will fetch all episodes and seasons for this series.'))
                    ->modalSubmitActionLabel(__('Yes, process now')),
                Action::make('sync')
                    ->label(__('Sync Series .strm files'))
                    ->action(function ($record) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new SyncSeriesStrmFiles(
                                series: $record,
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Series .strm files are being synced'))
                            ->body(__('You will be notified once complete.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-arrow-down')
                    ->modalIcon('heroicon-o-document-arrow-down')
                    ->modalDescription(__('Sync series .strm files now? This will generate .strm files for this series at the path set for this series.'))
                    ->modalSubmitActionLabel(__('Yes, sync now')),
                Action::make('probe')
                    ->label(__('Probe Episode Streams'))
                    ->icon('heroicon-o-signal')
                    ->action(function ($record): void {
                        $episodeIds = Episode::where('series_id', $record->id)
                            ->where('probe_enabled', true)
                            ->pluck('id')
                            ->all();
                        if (! empty($episodeIds)) {
                            $total = count($episodeIds);
                            $chunks = array_chunk($episodeIds, 50);
                            $last = count($chunks) - 1;
                            foreach ($chunks as $i => $chunk) {
                                dispatch(new ProbeVodStreamsChunk(
                                    episodeIds: $chunk,
                                    probeTimeout: 15,
                                    notifyUserId: $i === $last ? auth()->id() : null,
                                    notifyLabel: $i === $last ? __('Series stream probing') : null,
                                    notifyTotal: $i === $last ? $total : null,
                                ));
                            }
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Stream probing started'))
                            ->body(__('Stream probing is running in the background. You will be notified once the process is complete.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-signal')
                    ->modalDescription(__('Probe all episodes of this series with ffprobe to collect stream metadata (codec, resolution, bitrate, HDR). This data enables Trash Guide naming with stream-stat-based detection.'))
                    ->modalSubmitActionLabel(__('Start probing')),
                DeleteAction::make()
                    ->modalIcon('heroicon-o-trash')
                    ->modalDescription(__('Are you sure you want to delete this series? This will delete all episodes and seasons for this series. This action cannot be undone.'))
                    ->modalSubmitActionLabel(__('Yes, delete series')),
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
                ->tooltip(__('View enhanced details')),
        ];
    }

    public static function getTableBulkActions($addToCustom = true): array
    {
        return [
            BulkModalActionGroup::make('Bulk series actions')
                ->modalHeading(__('Bulk series actions'))
                ->gridColumns(2)
                ->schema(self::getBulkActionSchema($addToCustom)),
        ];
    }

    /**
     * Build the sectioned schema for the bulk actions modal.
     */
    private static function getBulkActionSchema(bool $addToCustom): array
    {
        return [
            // -- Playlist & Groups --
            BulkModalActionGroup::section('Playlist & Groups', [
                PlaylistService::getAddToPlaylistBulkAction('add', 'series')
                    ->hidden(fn () => ! $addToCustom),
                BulkAction::make('move')
                    ->label(__('Move Series to Category'))
                    ->schema([
                        Select::make('category')
                            ->required()
                            ->live()
                            ->label(__('Category'))
                            ->helperText(__('Select the category you would like to move the series to.'))
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
                                    ->title(__('Warning'))
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
                            ->title(__('Series moved to category'))
                            ->body(__('The category series have been moved to the chosen category.'))
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-right-left')
                    ->modalIcon('heroicon-o-arrows-right-left')
                    ->modalDescription(__('Move the category series to another category.'))
                    ->modalSubmitActionLabel(__('Move now')),
            ]),

            // -- Poster --
            BulkModalActionGroup::section('Poster', [
                BulkAction::make('set_poster_url')
                    ->label(__('Set poster URL'))
                    ->schema([
                        TextInput::make('cover')
                            ->label(__('Series poster URL'))
                            ->url()
                            ->nullable()
                            ->helperText(__('Leave empty to remove custom poster URL and use placeholder fallback.'))
                            ->suffixActions([
                                AssetPickerAction::upload('cover'),
                                AssetPickerAction::browse('cover'),
                            ]),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        Series::whereIn('id', $records->pluck('id')->toArray())
                            ->update([
                                'cover' => empty($data['cover']) ? null : $data['cover'],
                            ]);
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Poster URL updated'))
                            ->body(__('The poster URL has been updated for the selected series.'))
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-link')
                    ->modalIcon('heroicon-o-link')
                    ->modalDescription(__('Apply a single poster URL to all selected series. Leave empty to remove custom posters.'))
                    ->modalSubmitActionLabel(__('Apply URL')),
                BulkAction::make('refresh_logo_cache')
                    ->label(__('Refresh poster cache (selected)'))
                    ->action(function (Collection $records): void {
                        $urls = $records
                            ->pluck('cover')
                            ->filter()
                            ->values()
                            ->toArray();

                        $cleared = LogoCacheService::clearByUrls($urls);

                        Notification::make()
                            ->success()
                            ->title(__('Selected series cache refreshed'))
                            ->body("Removed {$cleared} cache file(s) for selected series posters.")
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-path')
                    ->modalIcon('heroicon-o-arrow-path')
                    ->modalDescription(__('Clear cached poster images for selected series so they are fetched again on the next request.'))
                    ->modalSubmitActionLabel(__('Refresh selected cache')),
            ]),

            // -- Series Metadata --
            BulkModalActionGroup::section('Series Metadata', [
                BulkAction::make('process')
                    ->label(__('Fetch Series Metadata'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->schema([
                        Toggle::make('overwrite_existing')
                            ->label(__('Overwrite Existing Metadata'))
                            ->helperText(__('Overwrite existing metadata? Episodes and seasons will always be fetched/updated.'))
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
                            ->title(__('Series are being processed'))
                            ->body(__('You will be notified once complete.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription(__('Process selected series now? This will fetch all episodes and seasons for this series. This may take a while depending on the number of series selected.'))
                    ->modalSubmitActionLabel(__('Yes, process now')),
                BulkAction::make('fetch_tmdb_ids')
                    ->label(__('Fetch TMDB/TVDB IDs'))
                    ->icon('heroicon-o-magnifying-glass')
                    ->schema([
                        Toggle::make('overwrite_existing')
                            ->label(__('Overwrite Existing IDs'))
                            ->helperText(__('Overwrite existing TMDB/TVDB/IMDB IDs? If disabled, it will only fetch IDs for series that don\\\'t already have them.'))
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
                            ->body(__('The TMDB ID lookup has been started. You will be notified when it is complete.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-magnifying-glass')
                    ->modalDescription(__('Search TMDB for matching TV series and populate TMDB/TVDB/IMDB IDs for the selected series? This enables Trash Guides compatibility for Sonarr.'))
                    ->modalSubmitActionLabel(__('Yes, fetch IDs now')),
                BulkAction::make('sync')
                    ->label(__('Sync Series .strm files'))
                    ->action(function ($records) {
                        $seriesIds = $records->pluck('id')->all();
                        if (empty($seriesIds)) {
                            return;
                        }
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new SyncSeriesStrmFiles(
                                user_id: auth()->id(),
                                series_ids: $seriesIds,
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('.strm files are being synced for selected series'))
                            ->body(__('You will be notified once complete.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-arrow-down')
                    ->modalIcon('heroicon-o-document-arrow-down')
                    ->modalDescription(__('Sync selected series .strm files now? This will generate .strm files for the selected series at the path set for the series.'))
                    ->modalSubmitActionLabel(__('Yes, sync now')),
            ]),

            // -- Find & Replace --
            BulkModalActionGroup::section('Find & Replace', [
                BulkAction::make('find-replace')
                    ->label(__('Find & Replace'))
                    ->schema(function (): array {
                        $savedPatterns = [];
                        $savedPatternRules = [];
                        $counter = 0;
                        foreach (Playlist::where('user_id', auth()->id())->get() as $playlist) {
                            foreach ($playlist->find_replace_rules ?? [] as $rule) {
                                if (is_array($rule) && ($rule['target'] ?? 'channels') === 'series') {
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
                                    $set('column', $rule['column'] ?? 'name');
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
                                ->label(__('Replace with (optional)'))
                                ->placeholder(__('Leave empty to remove')),
                        ];
                    })
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
                            ->title(__('Find & Replace started'))
                            ->body(__('Find & Replace working in the background. You will be notified once the process is complete.'))
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('gray')
                    ->modalIcon('heroicon-o-magnifying-glass')
                    ->modalDescription(__('Select what you would like to find and replace in the selected epg channels.'))
                    ->modalSubmitActionLabel(__('Replace now')),
            ]),

            // -- Probing --
            BulkModalActionGroup::section('Probing', [
                BulkAction::make('enable-probing')
                    ->label(__('Enable Probing'))
                    ->action(function (Collection $records): void {
                        $seriesIds = $records->pluck('id')->all();
                        Episode::whereIn('series_id', $seriesIds)->update(['probe_enabled' => true]);
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Stream probing enabled'))
                            ->body(__('Stream probing has been enabled for all episodes of the selected series.'))
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-signal')
                    ->modalIcon('heroicon-o-signal')
                    ->modalDescription(__('Enable stream probing for all episodes of the selected series. They will be included in stream probing jobs.'))
                    ->modalSubmitActionLabel(__('Enable now')),
                BulkAction::make('disable-probing')
                    ->label(__('Disable Probing'))
                    ->color('warning')
                    ->action(function (Collection $records): void {
                        $seriesIds = $records->pluck('id')->all();
                        Episode::whereIn('series_id', $seriesIds)->update(['probe_enabled' => false]);
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Stream probing disabled'))
                            ->body(__('Stream probing has been disabled for all episodes of the selected series.'))
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-signal-slash')
                    ->modalIcon('heroicon-o-signal-slash')
                    ->modalDescription(__('Disable stream probing for all episodes of the selected series. They will be excluded from stream probing jobs.'))
                    ->modalSubmitActionLabel(__('Disable now')),
                BulkAction::make('probe-streams')
                    ->label(__('Probe Streams'))
                    ->action(function (Collection $records): void {
                        $seriesIds = $records->pluck('id')->all();
                        $episodeIds = Episode::whereIn('series_id', $seriesIds)
                            ->where('probe_enabled', true)
                            ->pluck('id')
                            ->all();
                        if (! empty($episodeIds)) {
                            $total = count($episodeIds);
                            $chunks = array_chunk($episodeIds, 50);
                            $last = count($chunks) - 1;
                            foreach ($chunks as $i => $chunk) {
                                dispatch(new ProbeVodStreamsChunk(
                                    episodeIds: $chunk,
                                    probeTimeout: 15,
                                    notifyUserId: $i === $last ? auth()->id() : null,
                                    notifyLabel: $i === $last ? __('Series stream probing') : null,
                                    notifyTotal: $i === $last ? $total : null,
                                ));
                            }
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Stream probing started'))
                            ->body(__('Stream probing is running in the background. You will be notified once the process is complete.'))
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-signal')
                    ->modalIcon('heroicon-o-signal')
                    ->modalDescription(__('Probe the episodes of the selected series with ffprobe to collect stream metadata (codec, resolution, bitrate, HDR). This data enables Trash Guide naming with stream-stat-based detection.'))
                    ->modalSubmitActionLabel(__('Start probing')),
            ]),

            // -- Enable / Disable --
            BulkModalActionGroup::section('Enable / Disable', [
                BulkAction::make('enable')
                    ->label(__('Enable selected'))
                    ->action(function (Collection $records): void {
                        foreach ($records->chunk(100) as $chunk) {
                            Series::whereIn('id', $chunk->pluck('id'))->update(['enabled' => true]);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Selected series enabled'))
                            ->body(__('The selected series have been enabled.'))
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
                            Series::whereIn('id', $chunk->pluck('id'))->update(['enabled' => false]);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Selected series disabled'))
                            ->body(__('The selected series have been disabled.'))
                            ->send();
                    })
                    ->color('warning')
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-x-circle')
                    ->modalIcon('heroicon-o-x-circle')
                    ->modalDescription(__('Disable the selected channel(s) now?'))
                    ->modalSubmitActionLabel(__('Yes, disable now')),
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
                Section::make(__('Group Details'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->badge(),
                        TextEntry::make('playlist.name')
                            ->label(__('Playlist'))
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
                    Section::make(__('Series Details'))
                        ->columnSpan(2)
                        ->icon('heroicon-o-pencil')
                        ->description(__('Edit or add the series details'))
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
                                        ->maxLength(255)
                                        ->suffixActions([
                                            AssetPickerAction::upload('cover'),
                                            AssetPickerAction::browse('cover'),
                                        ]),
                                    Textarea::make('plot')
                                        ->columnSpanFull(),
                                    TextInput::make('genre')
                                        ->maxLength(255),
                                    DatePicker::make('release_date')
                                        ->label(__('Release Date'))
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
                                        ->label(__('Rating (5 based)'))
                                        ->numeric(),
                                    Textarea::make('cast')
                                        ->columnSpanFull(),
                                    TextInput::make('director')
                                        ->maxLength(255),
                                    TextInput::make('backdrop_path'),
                                    TextInput::make('youtube_trailer')
                                        ->label(__('YouTube Trailer ID'))
                                        ->maxLength(255),
                                ]),
                        ]),
                    Section::make(__('Stream file settings'))
                        ->columnSpan(2)
                        ->icon('heroicon-o-cog')
                        ->description(__('Override global .strm file generation settings for this series.'))
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            Grid::make(1)
                                ->schema([
                                    Select::make('stream_file_setting_id')
                                        ->label(__('Stream File Setting Profile'))
                                        ->searchable()
                                        ->relationship('streamFileSetting', 'name', fn ($query) => $query->forSeries()->where('user_id', auth()->id())
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
                                        ->helperText(__('Select a Stream File Setting profile to override global/category settings for this series. Leave empty to use category or global settings.')),
                                    TextInput::make('sync_location')
                                        ->label(__('Location Override'))
                                        ->rules([new CheckIfUrlOrLocalPath(localOnly: true, isDirectory: true)])
                                        ->helperText(__('Override the sync location from the profile. Leave empty to use profile location.'))
                                        ->maxLength(255)
                                        ->placeholder(__('/Series')),
                                ]),
                        ]),
                ]),
        ];
    }

    public static function getFormSteps(): array
    {
        return [
            Step::make(__('Playlist'))
                ->schema([
                    Select::make('playlist')
                        ->required()
                        ->label(__('Playlist'))
                        ->helperText(__('Select the playlist you would like to import series from.'))
                        ->options(Playlist::where([
                            ['user_id', auth()->id()],
                            ['xtream', true],
                        ])->get(['name', 'id'])->pluck('name', 'id'))
                        ->searchable(),
                ]),
            Step::make(__('Category'))
                ->schema([
                    Select::make('category')
                        ->label(__('Series Category'))
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
                        ->label(__('Category Name'))
                        ->helperText(__('Automatically set when selecting a category.'))
                        ->required()
                        ->disabled()
                        ->dehydrated(fn (): bool => true),
                ]),
            Step::make(__('Series to Import'))
                ->schema([
                    Toggle::make('import_all')
                        ->label(__('Import All Series'))
                        ->onColor('warning')
                        ->hint(__('Use with caution'))
                        ->live()
                        ->helperText(__('If enabled, all series in the selected category will be imported. Use with caution as this will make a lot of requests to your provider to fetch metadata and episodes. It is recomended to import only the series you want to watch. You can also enable the series option on your playlist under the "Groups and Streams to Import" to import all the base data for all available series.'))
                        ->default(false)
                        ->columnSpanFull()
                        ->afterStateUpdated(function (Get $get, $set) {
                            if ($get('import_all')) {
                                $set('series', []);
                            }
                        }),
                    CheckboxList::make('series')
                        ->label(__('Series to Import'))
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
