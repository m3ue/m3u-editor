<?php

namespace App\Filament\Resources\Series\Pages;

use App\Filament\Resources\Series\SeriesResource;
use App\Jobs\FetchTmdbIds;
use App\Jobs\ProcessM3uImportSeriesEpisodes;
use App\Jobs\SeriesFindAndReplace;
use App\Jobs\SyncSeriesStrmFiles;
use App\Jobs\SyncXtreamSeries;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\Series;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class ListSeries extends ListRecords
{
    protected static string $resource = SeriesResource::class;

    protected ?string $subheading = 'Only enabled series will be automatically updated on Playlist sync, this includes fetching episodes and metadata. You can also manually sync series to update episodes and metadata.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->slideOver()
                ->label('Add Series')
                ->steps(SeriesResource::getFormSteps())
                ->color('primary')
                ->action(function (array $data): void {
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new SyncXtreamSeries(
                            playlist: $data['playlist'],
                            catId: $data['category'],
                            catName: $data['category_name'],
                            series: $data['series'] ?? [],
                            importAll: $data['import_all'] ?? false,
                        ));
                })->after(function () {
                    Notification::make()
                        ->success()
                        ->title('Series have been added and are being processed.')
                        ->body('You will be notified when the process is complete.')
                        ->send();
                })
                ->requiresConfirmation()
                ->modalWidth('2xl')
                ->modalIcon(null)
                ->modalDescription('Select the playlist Series you would like to add.')
                ->modalSubmitActionLabel('Import Series Episodes & Metadata'),
            ActionGroup::make([
                Action::make('process')
                    ->label('Fetch Series Metadata')
                    ->schema([
                        Toggle::make('overwrite_existing')
                            ->label('Overwrite Existing Metadata')
                            ->helperText('Overwrite existing metadata? Episodes and seasons will always be fetched/updated.')
                            ->default(false),
                        Toggle::make('all_playlists')
                            ->label('All Playlists')
                            ->live()
                            ->helperText('Fetch metadata for all enabled Playlist Series? If disabled, it will only be fetched for Series of the selected Playlist.')
                            ->default(true),
                        Select::make('playlist')
                            ->label('Playlist')
                            ->required()
                            ->helperText('Select the Playlist you would like to fetch Series metadata for.')
                            ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->hidden(fn (Get $get) => $get('all_playlists') === true)
                            ->searchable(),
                    ])
                    ->action(function ($data) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ProcessM3uImportSeriesEpisodes(
                                playlist_id: $data['playlist'] ?? null,
                                all_playlists: $data['all_playlists'] ?? false,
                                overwrite_existing: $data['overwrite_existing'] ?? false,
                                user_id: auth()->id(),
                            ));
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
                    ->modalDescription('Process now? This will fetch all episodes and seasons for the enabled series.')
                    ->modalSubmitActionLabel('Yes, process now'),
                Action::make('fetch_tmdb_ids')
                    ->label('Fetch TMDB/TVDB IDs')
                    ->icon('heroicon-o-magnifying-glass')
                    ->schema([
                        Toggle::make('overwrite_existing')
                            ->label('Overwrite Existing IDs')
                            ->helperText('Overwrite existing TMDB/TVDB/IMDB IDs? If disabled, it will only fetch IDs for series that don\'t have them.')
                            ->default(false),
                        Toggle::make('all_playlists')
                            ->label('All Playlists')
                            ->live()
                            ->helperText('Fetch IDs for all enabled Playlist Series? If disabled, it will only be fetched for Series of the selected Playlist.')
                            ->default(true),
                        Select::make('playlist')
                            ->label('Playlist')
                            ->required()
                            ->helperText('Select the Playlist you would like to fetch TMDB IDs for.')
                            ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->hidden(fn (Get $get) => $get('all_playlists') === true)
                            ->searchable(),
                    ])
                    ->action(function ($data) {
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

                        $allPlaylists = $data['all_playlists'] ?? false;
                        $playlistId = $data['playlist'] ?? null;

                        // Validate that we'll find series
                        $query = Series::where('user_id', auth()->id())
                            ->where('enabled', true);

                        if (! $allPlaylists && $playlistId) {
                            $query->where('playlist_id', $playlistId);
                        }

                        $seriesCount = $query->count();

                        if ($seriesCount === 0) {
                            Notification::make()
                                ->warning()
                                ->title('No series found')
                                ->body('No enabled series found matching the criteria.')
                                ->send();

                            return;
                        }

                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new FetchTmdbIds(
                                vodChannelIds: null,
                                seriesIds: null,
                                vodPlaylistId: null,
                                seriesPlaylistId: $allPlaylists ? null : $playlistId,
                                allVodPlaylists: false,
                                allSeriesPlaylists: $allPlaylists,
                                overwriteExisting: $data['overwrite_existing'] ?? false,
                                user: auth()->user(),
                            ));

                        Notification::make()
                            ->success()
                            ->title("Fetching TMDB/TVDB IDs for {$seriesCount} series")
                            ->body('The TMDB ID lookup has been started. You will be notified when it is complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-magnifying-glass')
                    ->modalDescription('Search TMDB for matching TV series and populate TMDB/TVDB/IMDB IDs? This enables Trash Guides compatibility for Sonarr.')
                    ->modalSubmitActionLabel('Yes, fetch IDs now'),
                Action::make('sync')
                    ->label('Sync Series .strm files')
                    ->schema([
                        Toggle::make('all_playlists')
                            ->label('All Playlists')
                            ->live()
                            ->helperText('Sync stream files for all enabled Playlist Series? If disabled, it will only sync for Series of the selected Playlist.')
                            ->default(true),
                        Select::make('playlist')
                            ->label('Playlist')
                            ->required()
                            ->helperText('Select the Playlist you would like to sync Series stream files for.')
                            ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->hidden(fn (Get $get) => $get('all_playlists') === true)
                            ->searchable(),
                    ])
                    ->action(function ($data) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new SyncSeriesStrmFiles(
                                all_playlists: $data['all_playlists'] ?? false,
                                playlist_id: $data['playlist'] ?? null,
                                user_id: auth()->id(),
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
                    ->modalDescription('Sync .strm files now? This will generate .strm files for enabled series.')
                    ->modalSubmitActionLabel('Yes, sync now'),
                Action::make('find-replace')
                    ->label('Find & Replace')
                    ->schema([
                        Toggle::make('all_series')
                            ->label('All Series')
                            ->live()
                            ->helperText('Apply find and replace to all Series? If disabled, it will only apply to the selected Series.')
                            ->default(true),
                        Select::make('series')
                            ->label('Series')
                            ->required()
                            ->helperText('Select the Series you would like to apply changes to.')
                            ->options(Series::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->hidden(fn (Get $get) => $get('all_series') === true)
                            ->searchable(),
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
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new SeriesFindAndReplace(
                                user_id: auth()->id(), // The ID of the user who owns the content
                                all_series: $data['all_series'] ?? false,
                                series_id: $data['series'] ?? null,
                                use_regex: $data['use_regex'] ?? true,
                                column: $data['column'] ?? 'title',
                                find_replace: $data['find_replace'] ?? null,
                                replace_with: $data['replace_with'] ?? ''
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
                    ->modalDescription('Select what you would like to find and replace in your channels list.')
                    ->modalSubmitActionLabel('Replace now'),
            ])->button()->label('Actions'),
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('series.user_id', auth()->id());
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

        // Change count based on view
        $totalCount = Series::query()
            ->where($where)
            ->when($relationId, function ($query, $relationId) {
                return $query->where('category_id', $relationId);
            })->count();
        $enabledCount = Series::query()->where([...$where, ['enabled', true]])
            ->when($relationId, function ($query, $relationId) {
                return $query->where('category_id', $relationId);
            })->count();
        $disabledCount = Series::query()->where([...$where, ['enabled', false]])
            ->when($relationId, function ($query, $relationId) {
                return $query->where('category_id', $relationId);
            })->count();

        // Return tabs
        return [
            'all' => Tab::make('All Series')
                ->badge($totalCount),
            'enabled' => Tab::make('Enabled')
                // ->icon('heroicon-m-check')
                ->badgeColor('success')
                ->modifyQueryUsing(fn ($query) => $query->where('enabled', true))
                ->badge($enabledCount),
            'disabled' => Tab::make('Disabled')
                // ->icon('heroicon-m-x-mark')
                ->badgeColor('danger')
                ->modifyQueryUsing(fn ($query) => $query->where('enabled', false))
                ->badge($disabledCount),
        ];
    }

    public function applyTmdbSelection(int $tmdbId, string $type, int $recordId, string $recordType): void
    {
        try {
            $tmdbService = app(TmdbService::class);

            if ($type === 'tv' && $recordType === 'series') {
                $metadata = $tmdbService->applyTvSeriesSelection($tmdbId);

                if ($metadata) {
                    $series = Series::find($recordId);
                    if ($series) {
                        $series->update([
                            'tmdb_id' => $metadata['tmdb_id'],
                            'tvdb_id' => $metadata['tvdb_id'],
                            'imdb_id' => $metadata['imdb_id'],
                        ]);

                        Log::info('Manual TMDB search: Applied IDs to series', [
                            'series_id' => $series->id,
                            'series_name' => $series->name,
                            'tmdb_id' => $metadata['tmdb_id'],
                            'tvdb_id' => $metadata['tvdb_id'],
                            'imdb_id' => $metadata['imdb_id'],
                        ]);

                        Notification::make()
                            ->success()
                            ->title('TMDB IDs Applied')
                            ->body("Successfully linked \"{$series->name}\" to \"{$metadata['name']}\" (TMDB: {$metadata['tmdb_id']})")
                            ->send();

                        $this->unmountAction();

                        return;
                    }
                }
            } elseif ($type === 'movie' && $recordType === 'vod') {
                $metadata = $tmdbService->applyMovieSelection($tmdbId);

                if ($metadata) {
                    $vod = Channel::find($recordId);
                    if ($vod) {
                        $info = $vod->info ?? [];
                        $info['tmdb_id'] = $metadata['tmdb_id'];
                        $info['imdb_id'] = $metadata['imdb_id'];

                        $vod->update([
                            'info' => $info,
                        ]);

                        Log::info('Manual TMDB search: Applied IDs to VOD', [
                            'vod_id' => $vod->id,
                            'vod_name' => $vod->name ?? $vod->title,
                            'tmdb_id' => $metadata['tmdb_id'],
                            'imdb_id' => $metadata['imdb_id'],
                        ]);

                        $vodName = $vod->title_custom ?: $vod->title ?: $vod->name;
                        Notification::make()
                            ->success()
                            ->title('TMDB IDs Applied')
                            ->body("Successfully linked \"{$vodName}\" to \"{$metadata['title']}\" (TMDB: {$metadata['tmdb_id']})")
                            ->send();

                        $this->unmountAction();

                        return;
                    }
                }
            }

            Notification::make()
                ->danger()
                ->title('Error')
                ->body('Failed to apply TMDB selection.')
                ->send();
        } catch (\Exception $e) {
            Log::error('Manual TMDB search: Error applying selection', [
                'error' => $e->getMessage(),
                'tmdb_id' => $tmdbId,
            ]);

            Notification::make()
                ->danger()
                ->title('Error')
                ->body('An error occurred: '.$e->getMessage())
                ->send();
        }
    }
}
