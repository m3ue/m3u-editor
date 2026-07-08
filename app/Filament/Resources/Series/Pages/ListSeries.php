<?php

namespace App\Filament\Resources\Series\Pages;

use App\Filament\Actions\RegexTesterAction;
use App\Filament\Resources\Series\SeriesResource;
use App\Jobs\FetchTmdbIds;
use App\Jobs\ProcessM3uImportSeriesEpisodes;
use App\Jobs\SeriesFindAndReplace;
use App\Jobs\SyncSeriesStrmFiles;
use App\Jobs\SyncXtreamSeries;
use App\Models\Playlist;
use App\Models\Series;
use App\Services\PlaylistService;
use App\Settings\GeneralSettings;
use App\Traits\AppliesTmdbSelection;
use App\Traits\RenderlessColumnUpdates;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListSeries extends ListRecords
{
    use AppliesTmdbSelection;
    use RenderlessColumnUpdates;

    protected static string $resource = SeriesResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Only enabled series will be automatically updated on Playlist sync, this includes fetching episodes and metadata. You can also manually sync series to update episodes and metadata.');
    }

    #[Url(as: 'status')]
    public ?string $statusFilter = 'all';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->slideOver()
                ->label(__('Add Series'))
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
                        ->title(__('Series have been added and are being processed.'))
                        ->body(__('You will be notified when the process is complete.'))
                        ->send();
                })
                ->requiresConfirmation()
                ->modalWidth('2xl')
                ->modalIcon(null)
                ->modalDescription(__('Select the playlist Series you would like to add.'))
                ->modalSubmitActionLabel(__('Import Series Episodes & Metadata')),
            ActionGroup::make([
                PlaylistService::getMergeAction(contentType: 'series')
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Episode merge started'))
                            ->body(__('Merging series episodes in the background. You will be notified once the process is complete.'))
                            ->send();
                    }),
                PlaylistService::getUnmergeAction(contentType: 'series')
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Episode unmerge started'))
                            ->body(__('Unmerging episodes in the background. You will be notified once the process is complete.'))
                            ->send();
                    }),
                Action::make('process')
                    ->label(__('Fetch Series Metadata'))
                    ->schema([
                        Toggle::make('overwrite_existing')
                            ->label(__('Overwrite Existing Metadata'))
                            ->helperText(__('Overwrite existing metadata? Episodes and seasons will always be fetched/updated.'))
                            ->default(false),
                        Toggle::make('all_playlists')
                            ->label(__('All Playlists'))
                            ->live()
                            ->helperText(__('Fetch metadata for all enabled Playlist Series? If disabled, it will only be fetched for Series of the selected Playlist.'))
                            ->default(true),
                        Select::make('playlist')
                            ->label(__('Playlist'))
                            ->required()
                            ->helperText(__('Select the Playlist you would like to fetch Series metadata for.'))
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
                            ->title(__('Series are being processed'))
                            ->body(__('You will be notified once complete.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription(__('Process now? This will fetch all episodes and seasons for the enabled series.'))
                    ->modalSubmitActionLabel(__('Yes, process now')),
                Action::make('fetch_tmdb_ids')
                    ->label(__('Fetch TMDB/TVDB IDs'))
                    ->icon('heroicon-o-magnifying-glass')
                    ->schema([
                        Toggle::make('overwrite_existing')
                            ->label(__('Overwrite Existing IDs'))
                            ->helperText(__('Overwrite existing TMDB/TVDB/IMDB IDs? If disabled, it will only fetch IDs for series that don\'t have them.'))
                            ->default(false),
                        Toggle::make('all_playlists')
                            ->label(__('All Playlists'))
                            ->live()
                            ->helperText(__('Fetch IDs for all enabled Playlist Series? If disabled, it will only be fetched for Series of the selected Playlist.'))
                            ->default(true),
                        Select::make('playlist')
                            ->label(__('Playlist'))
                            ->required()
                            ->helperText(__('Select the Playlist you would like to fetch TMDB IDs for.'))
                            ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->hidden(fn (Get $get) => $get('all_playlists') === true)
                            ->searchable(),
                    ])
                    ->action(function ($data) {
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
                                ->title(__('No series found'))
                                ->body(__('No enabled series found matching the criteria.'))
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
                            ->body(__('The TMDB ID lookup has been started. You will be notified when it is complete.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-magnifying-glass')
                    ->modalDescription(__('Search TMDB for matching TV series and populate TMDB/TVDB/IMDB IDs? This enables Trash Guides compatibility for Sonarr.'))
                    ->modalSubmitActionLabel(__('Yes, fetch IDs now')),
                Action::make('sync')
                    ->label(__('Sync Series .strm files'))
                    ->schema([
                        Toggle::make('all_playlists')
                            ->label(__('All Playlists'))
                            ->live()
                            ->helperText(__('Sync stream files for all enabled Playlist Series? If disabled, it will only sync for Series of the selected Playlist.'))
                            ->default(true),
                        Select::make('playlist')
                            ->label(__('Playlist'))
                            ->required()
                            ->helperText(__('Select the Playlist you would like to sync Series stream files for.'))
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
                            ->title(__('Series .strm files are being synced'))
                            ->body(__('You will be notified once complete.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-arrow-down')
                    ->modalIcon('heroicon-o-document-arrow-down')
                    ->modalDescription(__('Sync .strm files now? This will generate .strm files for enabled series.'))
                    ->modalSubmitActionLabel(__('Yes, sync now')),
                Action::make('find-replace')
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
                            Toggle::make('all_series')
                                ->label(__('All Series'))
                                ->live()
                                ->helperText(__('Apply find and replace to all Series? If disabled, it will only apply to the selected Series.'))
                                ->default(true),
                            Select::make('series')
                                ->label(__('Series'))
                                ->required()
                                ->helperText(__('Select the Series you would like to apply changes to.'))
                                ->options(Series::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->hidden(fn (Get $get) => $get('all_series') === true)
                                ->searchable(),
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
                                )
                                ->suffixAction(
                                    RegexTesterAction::make(samplesContext: 'series', patternField: 'find_replace', replacementField: 'replace_with')
                                        ->visible(fn (Get $get): bool => (bool) $get('use_regex'))
                                ),
                            TextInput::make('replace_with')
                                ->label(__('Replace with (optional)'))
                                ->placeholder(__('Leave empty to remove')),
                        ];
                    })
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
                            ->title(__('Find & Replace started'))
                            ->body(__('Find & Replace working in the background. You will be notified once the process is complete.'))
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('gray')
                    ->modalIcon('heroicon-o-magnifying-glass')
                    ->modalDescription(__('Select what you would like to find and replace in your channels list.'))
                    ->modalSubmitActionLabel(__('Replace now')),
            ])->button()->label(__('Actions')),
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
        $playlists = Playlist::where('user_id', auth()->id())->orderBy('name')->get();

        $playlistCounts = Series::where('user_id', auth()->id())
            ->whereIn('playlist_id', $playlists->pluck('id'))
            ->groupBy('playlist_id')
            ->selectRaw('playlist_id, count(*) as aggregate')
            ->pluck('aggregate', 'playlist_id');

        return [
            'all' => Tab::make(__('All Playlists'))
                ->badge($playlistCounts->sum()),
            ...($playlists->mapWithKeys(fn (Playlist $playlist) => [
                'playlist_'.$playlist->id => Tab::make($playlist->name)
                    ->modifyQueryUsing(fn ($query) => $query->where('playlist_id', $playlist->id))
                    ->badge($playlistCounts->get($playlist->id, 0)),
            ])->toArray()),
        ];
    }

    public static function setupTabs($relationId = null): array
    {
        $where = [
            ['user_id', auth()->id()],
        ];

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

        return [
            'all' => Tab::make(__('All Series'))
                ->badge($totalCount),
            'enabled' => Tab::make(__('Enabled'))
                ->badgeColor('success')
                ->modifyQueryUsing(fn ($query) => $query->where('enabled', true))
                ->badge($enabledCount),
            'disabled' => Tab::make(__('Disabled'))
                ->badgeColor('danger')
                ->modifyQueryUsing(fn ($query) => $query->where('enabled', false))
                ->badge($disabledCount),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getTabsContentComponent(),
            View::make('filament.series.status-tabs'),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }

    protected function modifyQueryWithActiveTab(Builder $query, bool $isResolvingRecord = false): Builder
    {
        $query = parent::modifyQueryWithActiveTab($query, $isResolvingRecord);

        return match ($this->statusFilter) {
            'enabled' => $query->where('enabled', true),
            'disabled' => $query->where('enabled', false),
            default => $query,
        };
    }

    /**
     * @return array<string, int>
     */
    public function getStatusTabCounts(): array
    {
        $baseQuery = Series::query()
            ->where('user_id', auth()->id());

        $activeTab = $this->activeTab;
        if ($activeTab && $activeTab !== 'all') {
            $tabs = $this->getCachedTabs();
            if (isset($tabs[$activeTab])) {
                $baseQuery = $tabs[$activeTab]->modifyQuery($baseQuery);
            }
        }

        $counts = (clone $baseQuery)
            ->selectRaw('count(*) as all_count, sum(case when enabled then 1 else 0 end) as enabled_count, sum(case when not enabled then 1 else 0 end) as disabled_count')
            ->first();

        return [
            'all' => (int) ($counts->all_count ?? 0),
            'enabled' => (int) ($counts->enabled_count ?? 0),
            'disabled' => (int) ($counts->disabled_count ?? 0),
        ];
    }

    public function updatedActiveTab(): void
    {
        parent::updatedActiveTab();
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }


}
