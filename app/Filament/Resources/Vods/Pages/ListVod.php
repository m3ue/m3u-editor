<?php

namespace App\Filament\Resources\Vods\Pages;

use App\Filament\Exports\ChannelExporter;
use App\Filament\Imports\ChannelImporter;
use App\Filament\Resources\Vods\VodResource;
use App\Jobs\ChannelFindAndReplace;
use App\Jobs\ChannelFindAndReplaceReset;
use App\Jobs\FetchTmdbIds;
use App\Jobs\ProcessVodChannels;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Series;
use App\Services\PlaylistService;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use App\Traits\RenderlessColumnUpdates;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ImportAction;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;

class ListVod extends ListRecords
{
    use RenderlessColumnUpdates;

    protected static string $resource = VodResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('NOTE: VOD output order is based on: 1 Sort order, 2 Channel no. and 3 Title - in that order. You can edit your Playlist output to auto sort as well, which will define the sort order based on the playlist order.');
    }

    #[Url(as: 'status')]
    public ?string $statusFilter = 'all';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Create Custom Channel'))
                ->modalHeading(__('New Custom Channel'))
                ->modalDescription(__('NOTE: Custom channels need to be associated with a Playlist or Custom Playlist.'))
                ->using(fn (array $data, string $model): Model => VodResource::createCustomChannel(
                    data: $data,
                    model: $model,
                ))
                ->slideOver(),
            ActionGroup::make([
                PlaylistService::getMergeAction()
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Channel merge started'))
                            ->body(__('Merging channels in the background. You will be notified once the process is complete.'))
                            ->send();
                    }),
                PlaylistService::getUnmergeAction()
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Channel unmerge started'))
                            ->body(__('Unmerging channels in the background. You will be notified once the process is complete.'))
                            ->send();
                    }),

                Action::make('process_vod')
                    ->label(__('Fetch Metadata'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->schema([
                        Toggle::make('overwrite_existing')
                            ->label(__('Overwrite Existing Metadata'))
                            ->helperText(__('Overwrite existing metadata? If disabled, it will only fetch and process metadata if it does not already exist.'))
                            ->default(false),
                        Select::make('playlist')
                            ->label(__('Playlist'))
                            ->required()
                            ->helperText(__('Select the Playlist you would like to fetch VOD metadata for.'))
                            ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->searchable(),
                    ])
                    ->action(function ($data) {
                        $playlist = Playlist::find($data['playlist'] ?? null);
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ProcessVodChannels(
                                force: $data['overwrite_existing'] ?? false,
                                playlist: $playlist,
                            ));
                    })
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Fetching VOD metadata for playlist'))
                            ->body(__('The VOD metadata fetching and processing has been started. You will be notified when it is complete.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription(__('Fetch and process VOD metadata for the selected Playlist? Only enabled VOD channels will be processed.'))
                    ->modalSubmitActionLabel(__('Yes, process now')),
                Action::make('fetch_tmdb_ids')
                    ->label(__('Fetch TMDB IDs'))
                    ->icon('heroicon-o-magnifying-glass')
                    ->schema([
                        Toggle::make('overwrite_existing')
                            ->label(__('Overwrite Existing IDs'))
                            ->helperText(__('Overwrite existing TMDB/IMDB IDs? If disabled, it will only fetch IDs for items that don\\\'t have them.'))
                            ->default(false),
                        Select::make('playlist')
                            ->label(__('Playlist'))
                            ->required()
                            ->helperText(__('Select the Playlist you would like to fetch TMDB IDs for.'))
                            ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
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

                        $playlistId = $data['playlist'] ?? null;
                        $playlist = Playlist::find($playlistId);
                        if (! $playlist) {
                            return;
                        }

                        $vodCount = $playlist->channels()
                            ->where('is_vod', true)
                            ->where('enabled', true)
                            ->count();

                        if ($vodCount === 0) {
                            Notification::make()
                                ->warning()
                                ->title(__('No VOD channels found'))
                                ->body(__('No enabled VOD channels found in the selected playlist.'))
                                ->send();

                            return;
                        }

                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new FetchTmdbIds(
                                vodChannelIds: null,
                                seriesIds: null,
                                vodPlaylistId: $playlistId,
                                seriesPlaylistId: null,
                                allVodPlaylists: false,
                                allSeriesPlaylists: false,
                                overwriteExisting: $data['overwrite_existing'] ?? false,
                                user: auth()->user(),
                            ));

                        Notification::make()
                            ->success()
                            ->title("Fetching TMDB IDs for {$vodCount} VOD channel(s)")
                            ->body(__('The TMDB ID lookup has been started. You will be notified when it is complete.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-magnifying-glass')
                    ->modalDescription(__('Search TMDB for matching movies and populate TMDB/IMDB IDs for all VOD channels in the selected playlist? This enables Trash Guides compatibility for Radarr.'))
                    ->modalSubmitActionLabel(__('Yes, fetch IDs now')),
                Action::make('sync')
                    ->label(__('Sync VOD .strm files'))
                    ->schema([
                        Select::make('playlist')
                            ->label(__('Playlist'))
                            ->required()
                            ->helperText(__('Select the Playlist you would like to fetch VOD metadata for.'))
                            ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->searchable(),
                    ])
                    ->action(function ($data) {
                        $playlist = Playlist::find($data['playlist'] ?? null);
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new SyncVodStrmFiles(
                                playlist: $playlist,
                            ));
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

                Action::make('find-replace')
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
                            Toggle::make('all_playlists')
                                ->label(__('All Playlists'))
                                ->live()
                                ->helperText(__('Apply find and replace to all playlists? If disabled, it will only apply to the selected playlist.'))
                                ->default(true),
                            Select::make('playlist')
                                ->label(__('Playlist'))
                                ->required()
                                ->helperText(__('Select the playlist you would like to apply changes to.'))
                                ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->hidden(fn (Get $get) => $get('all_playlists') === true)
                                ->searchable(),
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
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ChannelFindAndReplace(
                                user_id: auth()->id(), // The ID of the user who owns the content
                                all_playlists: $data['all_playlists'] ?? false,
                                playlist_id: $data['playlist'] ?? null,
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

                Action::make('find-replace-reset')
                    ->label(__('Undo Find & Replace'))
                    ->schema([
                        Toggle::make('all_playlists')
                            ->label(__('All Playlists'))
                            ->live()
                            ->helperText(__('Apply reset to all playlists? If disabled, it will only apply to the selected playlist.'))
                            ->default(false),
                        Select::make('playlist')
                            ->required()
                            ->label(__('Playlist'))
                            ->helperText(__('Select the playlist you would like to apply the reset to.'))
                            ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->hidden(fn (Get $get) => $get('all_playlists') === true)
                            ->searchable(),
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
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ChannelFindAndReplaceReset(
                                user_id: auth()->id(), // The ID of the user who owns the content
                                all_playlists: $data['all_playlists'] ?? false,
                                playlist_id: $data['playlist'] ?? null,
                                column: $data['column'] ?? 'title',
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
                    ->modalDescription(__('Reset Find & Replace results back to playlist defaults. This will remove any custom values set in the selected column.'))
                    ->modalSubmitActionLabel(__('Reset now')),

                ImportAction::make()
                    ->importer(ChannelImporter::class)
                    ->label(__('Import Channels'))
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('primary')
                    ->modalDescription(__('Import channels from a CSV or XLSX file.')),
                ExportAction::make()
                    ->exporter(ChannelExporter::class)
                    ->label(__('Export Channels'))
                    ->icon('heroicon-m-arrow-up-tray')
                    ->color('primary')
                    ->modalDescription(__('Export channels to a CSV or XLSX file. NOTE: Only enabled channels will be exported.'))
                    ->columnMapping(false)
                    ->modifyQueryUsing(function ($query, array $options) {
                        // For now, only allow exporting enabled channels
                        return $query->where([
                            ['playlist_id', $options['playlist']],
                            ['enabled', true],
                        ]);
                        // return $query->where('playlist_id', $options['playlist'])
                        //     ->when($options['enabled'], function ($query, $enabled) {
                        //         return $query->where('enabled', $enabled);
                        //     });
                    }),
            ])->button()->label(__('Actions')),
        ];
    }

    public function getTabs(): array
    {
        $where = [['user_id', auth()->id()], ['is_vod', true]];
        $playlists = Playlist::where('user_id', auth()->id())->orderBy('name')->get();

        $playlistCounts = Channel::where($where)
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
            ['is_vod', true],
        ];

        $totalCount = Channel::query()
            ->where($where)
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();
        $enabledCount = Channel::query()->where([...$where, ['enabled', true]])
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();
        $disabledCount = Channel::query()->where([...$where, ['enabled', false]])
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();
        $customCount = Channel::query()->where([...$where, ['is_custom', true]])
            ->whereNull('dvr_recording_id')
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();
        $dvrCount = Channel::query()->where($where)
            ->whereNotNull('dvr_recording_id')
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();

        $withFailoverCount = Channel::query()->whereHas('failovers')->where($where)
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();

        return [
            'all' => Tab::make(__('All VOD Channels'))
                ->badge($totalCount),
            'enabled' => Tab::make(__('Enabled'))
                ->badgeColor('success')
                ->modifyQueryUsing(fn ($query) => $query->where('enabled', true))
                ->badge($enabledCount),
            'disabled' => Tab::make(__('Disabled'))
                ->badgeColor('danger')
                ->modifyQueryUsing(fn ($query) => $query->where('enabled', false))
                ->badge($disabledCount),
            'failover' => Tab::make(__('Failover'))
                ->badgeColor('info')
                ->modifyQueryUsing(fn ($query) => $query->whereHas('failovers'))
                ->badge($withFailoverCount),
            'custom' => Tab::make(__('Custom'))
                ->modifyQueryUsing(fn ($query) => $query->where('is_custom', true)->whereNull('dvr_recording_id'))
                ->badge($customCount),
            'dvr' => Tab::make(__('DVR'))
                ->modifyQueryUsing(fn ($query) => $query->whereNotNull('dvr_recording_id'))
                ->badge($dvrCount),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getTabsContentComponent(),
            View::make('filament.vods.status-tabs'),
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
            'failover' => $query->whereHas('failovers'),
            'custom' => $query->where('is_custom', true)->whereNull('dvr_recording_id'),
            'dvr' => $query->whereNotNull('dvr_recording_id'),
            default => $query,
        };
    }

    /**
     * @return array<string, int>
     */
    public function getStatusTabCounts(): array
    {
        $baseQuery = Channel::query()
            ->where('user_id', auth()->id())
            ->where('is_vod', true);

        $activeTab = $this->activeTab;
        if ($activeTab && $activeTab !== 'all') {
            $tabs = $this->getCachedTabs();
            if (isset($tabs[$activeTab])) {
                $baseQuery = $tabs[$activeTab]->modifyQuery($baseQuery);
            }
        }

        $counts = (clone $baseQuery)
            ->selectRaw('count(*) as all_count, sum(case when enabled then 1 else 0 end) as enabled_count, sum(case when not enabled then 1 else 0 end) as disabled_count, sum(case when is_custom and dvr_recording_id is null then 1 else 0 end) as custom_count, sum(case when dvr_recording_id is not null then 1 else 0 end) as dvr_count')
            ->first();

        return [
            'all' => (int) ($counts->all_count ?? 0),
            'enabled' => (int) ($counts->enabled_count ?? 0),
            'disabled' => (int) ($counts->disabled_count ?? 0),
            'failover' => (clone $baseQuery)->whereHas('failovers')->count(),
            'custom' => (int) ($counts->custom_count ?? 0),
            'dvr' => (int) ($counts->dvr_count ?? 0),
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

    public function applyTmdbSelection(int $tmdbId, string $type, ?int $recordId, string $recordType): void
    {
        try {
            if (! $recordId) {
                Log::error('Manual TMDB search: Record ID is null', [
                    'tmdb_id' => $tmdbId,
                    'type' => $type,
                    'recordType' => $recordType,
                ]);

                Notification::make()
                    ->danger()
                    ->title(__('Error'))
                    ->body(__('Could not determine the record to update. Please close the modal and try again.'))
                    ->send();

                return;
            }

            $tmdbService = app(TmdbService::class);

            if ($type === 'tv' && $recordType === 'series') {
                $series = Series::find($recordId);
                if (! $series) {
                    Notification::make()
                        ->danger()
                        ->title(__('Error'))
                        ->body(__('Series record not found.'))
                        ->send();

                    return;
                }

                $this->applySeriesMetadata($tmdbService, $series, $tmdbId);
            } elseif ($type === 'movie' && $recordType === 'vod') {
                $vod = Channel::find($recordId);
                if (! $vod) {
                    Notification::make()
                        ->danger()
                        ->title(__('Error'))
                        ->body(__('VOD record not found.'))
                        ->send();

                    return;
                }

                $this->applyVodMetadata($tmdbService, $vod, $tmdbId);
            } else {
                Notification::make()
                    ->danger()
                    ->title(__('Error'))
                    ->body(__('Failed to apply TMDB selection.'))
                    ->send();

                return;
            }
        } catch (\Throwable $e) {
            Log::error('Manual TMDB search: Error applying selection', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'tmdb_id' => $tmdbId,
            ]);

            Notification::make()
                ->danger()
                ->title(__('Error'))
                ->body('An error occurred: '.$e->getMessage())
                ->send();
        }
    }

    /**
     * Fetch full metadata from TMDB and apply it to a VOD channel.
     */
    protected function applyVodMetadata(TmdbService $tmdbService, Channel $vod, int $tmdbId): void
    {
        $metadata = $tmdbService->applyMovieSelection($tmdbId);
        if (! $metadata) {
            Notification::make()
                ->danger()
                ->title(__('Error'))
                ->body(__('Failed to fetch TMDB data for this movie.'))
                ->send();

            return;
        }

        $info = $vod->info ?? [];
        $updateData = [
            'tmdb_id' => $metadata['tmdb_id'],
        ];

        if (! empty($metadata['imdb_id'])) {
            $updateData['imdb_id'] = $metadata['imdb_id'];
        }

        $info['tmdb_id'] = $metadata['tmdb_id'];
        if (! empty($metadata['imdb_id'])) {
            $info['imdb_id'] = $metadata['imdb_id'];
        }

        // Fetch full movie details to populate metadata
        $details = $tmdbService->getMovieDetails($tmdbId);
        if ($details) {
            if (! empty($details['imdb_id']) && empty($updateData['imdb_id'])) {
                $updateData['imdb_id'] = $details['imdb_id'];
                $info['imdb_id'] = $details['imdb_id'];
            }

            if (! empty($details['poster_url'])) {
                $info['cover_big'] = $details['poster_url'];
            }

            if (! empty($details['overview'])) {
                $info['plot'] = $details['overview'];
            }

            if (! empty($details['genres']) && (empty($info['genre']) || ($info['genre'] ?? '') === 'Uncategorized')) {
                $info['genre'] = $details['genres'];

                $primaryGenre = is_string($details['genres'])
                    ? explode(', ', $details['genres'])[0]
                    : (is_array($details['genres']) ? $details['genres'][0] : null);

                if ($primaryGenre && ($vod->group === 'Uncategorized' || $vod->group_internal === 'Uncategorized')) {
                    $group = Group::firstOrCreate(
                        [
                            'playlist_id' => $vod->playlist_id,
                            'name' => $primaryGenre,
                        ],
                        [
                            'name_internal' => $primaryGenre,
                            'user_id' => $vod->user_id,
                            'type' => 'vod',
                        ]
                    );
                    $updateData['group'] = $primaryGenre;
                    $updateData['group_internal'] = $primaryGenre;
                    $updateData['group_id'] = $group->id;
                }
            }

            if (! empty($details['release_date'])) {
                $info['release_date'] = $details['release_date'];
            }

            if (! empty($details['release_date']) && empty($vod->year)) {
                $updateData['year'] = substr($details['release_date'], 0, 4);
            }

            if (! empty($details['vote_average'])) {
                $info['rating'] = $details['vote_average'];
            }

            if (! empty($details['backdrop_url'])) {
                $info['backdrop_path'] = [$details['backdrop_url']];
            }

            if (! empty($details['cast'])) {
                $info['cast'] = is_array($details['cast']) ? implode(', ', $details['cast']) : $details['cast'];
            }

            if (! empty($details['director'])) {
                $info['director'] = is_array($details['director']) ? implode(', ', $details['director']) : $details['director'];
            }

            if (! empty($details['youtube_trailer'])) {
                $info['youtube_trailer'] = $details['youtube_trailer'];
            }

            if (! empty($details['runtime']) && (empty($info['duration_secs']) || ($info['duration_secs'] ?? 0) === 0)) {
                $runtimeMinutes = (int) $details['runtime'];
                $runtimeSeconds = $runtimeMinutes * 60;
                $info['duration_secs'] = $runtimeSeconds;
                $info['duration'] = gmdate('H:i:s', $runtimeSeconds);
                $info['episode_run_time'] = $runtimeMinutes;
            }
        }

        $updateData['info'] = $info;

        // Update logo from TMDB poster if empty
        if (! empty($info['cover_big']) && empty($vod->logo)) {
            $updateData['logo'] = $info['cover_big'];
        }
        if (! empty($info['cover_big']) && empty($vod->logo_internal)) {
            $updateData['logo_internal'] = $info['cover_big'];
        }

        // Set display title to TMDB title (manual match = user intent to correct the title)
        $tmdbTitle = $details['title'] ?? $metadata['title'] ?? null;
        if ($tmdbTitle) {
            $updateData['title_custom'] = $tmdbTitle;
        }

        $updateData['last_metadata_fetch'] = now();

        $vod->update($updateData);

        $vodName = $vod->title_custom ?: $vod->title ?: $vod->name;

        Log::info('Manual TMDB search: Applied full metadata to VOD', [
            'vod_id' => $vod->id,
            'vod_name' => $vodName,
            'tmdb_id' => $metadata['tmdb_id'],
            'imdb_id' => $metadata['imdb_id'] ?? null,
            'has_details' => $details !== null,
        ]);

        Notification::make()
            ->success()
            ->title(__('TMDB Metadata Applied'))
            ->body("Successfully linked \"{$vodName}\" to \"{$tmdbTitle}\" (TMDB: {$metadata['tmdb_id']}) with full metadata.")
            ->send();

        $this->unmountAction();
    }

    /**
     * Fetch full metadata from TMDB and apply it to a series.
     */
    protected function applySeriesMetadata(TmdbService $tmdbService, Series $series, int $tmdbId): void
    {
        $metadata = $tmdbService->applyTvSeriesSelection($tmdbId);
        if (! $metadata) {
            Notification::make()
                ->danger()
                ->title(__('Error'))
                ->body(__('Failed to fetch TMDB data for this series.'))
                ->send();

            return;
        }

        $updateData = [
            'tmdb_id' => $metadata['tmdb_id'],
            'last_metadata_fetch' => now(),
        ];

        if (! empty($metadata['tvdb_id'])) {
            $updateData['tvdb_id'] = $metadata['tvdb_id'];
        }
        if (! empty($metadata['imdb_id'])) {
            $updateData['imdb_id'] = $metadata['imdb_id'];
        }

        $seriesMetadata = $series->metadata ?? [];
        $seriesMetadata['tmdb_id'] = $metadata['tmdb_id'];
        if (! empty($metadata['tvdb_id'])) {
            $seriesMetadata['tvdb_id'] = $metadata['tvdb_id'];
        }
        if (! empty($metadata['imdb_id'])) {
            $seriesMetadata['imdb_id'] = $metadata['imdb_id'];
        }

        // Fetch full series details to populate metadata
        $details = $tmdbService->getTvSeriesDetails($tmdbId);
        if ($details) {
            if (! empty($details['tvdb_id']) && empty($updateData['tvdb_id'])) {
                $updateData['tvdb_id'] = $details['tvdb_id'];
                $seriesMetadata['tvdb_id'] = $details['tvdb_id'];
            }
            if (! empty($details['imdb_id']) && empty($updateData['imdb_id'])) {
                $updateData['imdb_id'] = $details['imdb_id'];
                $seriesMetadata['imdb_id'] = $details['imdb_id'];
            }

            if (! empty($details['poster_url'])) {
                $updateData['cover'] = $details['poster_url'];
            }

            if (! empty($details['overview'])) {
                $updateData['plot'] = $details['overview'];
            }

            if (! empty($details['genres']) && (empty($series->genre) || ($series->genre ?? '') === 'Uncategorized')) {
                $updateData['genre'] = $details['genres'];

                $primaryGenre = is_string($details['genres'])
                    ? explode(', ', $details['genres'])[0]
                    : (is_array($details['genres']) ? $details['genres'][0] : null);

                if ($primaryGenre) {
                    $currentCategory = $series->category_id ? Category::find($series->category_id) : null;
                    if (! $currentCategory || $currentCategory->name === 'Uncategorized') {
                        $category = Category::firstOrCreate(
                            [
                                'playlist_id' => $series->playlist_id,
                                'name' => $primaryGenre,
                            ],
                            [
                                'name_internal' => $primaryGenre,
                                'user_id' => $series->user_id,
                            ]
                        );
                        $updateData['category_id'] = $category->id;
                        $updateData['source_category_id'] = $category->id;
                    }
                }
            }

            if (! empty($details['first_air_date']) && empty($series->release_date)) {
                $updateData['release_date'] = $details['first_air_date'];
            }

            if (! empty($details['vote_average']) && empty($series->rating)) {
                $updateData['rating'] = $details['vote_average'];
            }

            if (! empty($details['backdrop_url'])) {
                $updateData['backdrop_path'] = json_encode([$details['backdrop_url']]);
            }

            if (! empty($details['cast'])) {
                $updateData['cast'] = is_array($details['cast']) ? implode(', ', $details['cast']) : $details['cast'];
            }

            if (! empty($details['director'])) {
                $updateData['director'] = is_array($details['director']) ? implode(', ', $details['director']) : $details['director'];
            }

            if (! empty($details['youtube_trailer'])) {
                $updateData['youtube_trailer'] = $details['youtube_trailer'];
            }
        }

        $updateData['metadata'] = $seriesMetadata;

        // Set series name to TMDB name (manual match = user intent to correct the name)
        $tmdbName = $details['name'] ?? $metadata['name'] ?? null;
        if ($tmdbName) {
            $updateData['name'] = $tmdbName;
        }

        $series->update($updateData);

        Log::info('Manual TMDB search: Applied full metadata to series', [
            'series_id' => $series->id,
            'series_name' => $series->name,
            'tmdb_id' => $metadata['tmdb_id'],
            'tvdb_id' => $metadata['tvdb_id'] ?? null,
            'imdb_id' => $metadata['imdb_id'] ?? null,
            'has_details' => $details !== null,
        ]);

        Notification::make()
            ->success()
            ->title(__('TMDB Metadata Applied'))
            ->body("Successfully linked \"{$series->name}\" to TMDB: {$metadata['tmdb_id']} with full metadata.")
            ->send();

        $this->unmountAction();
    }
}
