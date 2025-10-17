<?php

namespace App\Filament\Resources\Series\Pages;

use Filament\Actions\Action;
use App\Jobs\SyncXtreamSeries;
use App\Filament\Resources\Series\SeriesResource;
use App\Jobs\ProcessM3uImportSeriesEpisodes;
use App\Jobs\SeriesFindAndReplace;
use App\Jobs\SyncSeriesStrmFiles;
use App\Jobs\ProcessEmbySeriesSync;
use App\Services\EmbyService;
use App\Models\Playlist;
use App\Models\Series;
use Filament\Actions;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Placeholder;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;

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
                Action::make('sync_from_emby')
                    ->label('Sync from Emby/Jellyfin')
                    ->icon('heroicon-o-tv')
                    ->color('primary')
                    ->schema([
                        TextEntry::make('security_warning')
                            ->label('Security Warning')
                            ->state('⚠️ SECURITY WARNING: This feature connects to your Emby/Jellyfin server and should only be used on trusted local networks. Ensure your Emby/Jellyfin server is not exposed to the public internet when using this feature.')
                            ->columnSpanFull()
                            ->extraAttributes([
                                'class' => 'text-warning-600 dark:text-warning-400 font-semibold bg-warning-50 dark:bg-warning-950 p-4 rounded-lg border-2 border-warning-200 dark:border-warning-800',
                            ]),
                        Select::make('library_id')
                            ->label('Emby/Jellyfin Library')
                            ->required()
                            ->searchable()
                            ->options(function () {
                                try {
                                    $embyService = new EmbyService();
                                    if (!$embyService->isConfigured()) {
                                        return ['_not_configured' => 'Emby/Jellyfin not configured - Please configure in Settings'];
                                    }

                                    $libraries = $embyService->getLibraries();
                                    $tvLibraries = [];

                                    foreach ($libraries as $library) {
                                        if (isset($library['CollectionType']) && $library['CollectionType'] === 'tvshows') {
                                            $tvLibraries[$library['ItemId']] = $library['Name'];
                                        }
                                    }

                                    if (empty($tvLibraries)) {
                                        return ['_no_libraries' => 'No TV show libraries found'];
                                    }

                                    return $tvLibraries;
                                } catch (\Exception $e) {
                                    return ['_error' => 'Error: ' . $e->getMessage()];
                                }
                            })
                            ->helperText('Select the Emby/Jellyfin TV show library to sync from.'),
                        Select::make('playlist_id')
                            ->label('Playlist')
                            ->required()
                            ->options(Playlist::where('user_id', auth()->id())->pluck('name', 'id'))
                            ->searchable()
                            ->helperText('Select the playlist to associate these series with.'),
                        Toggle::make('use_direct_path')
                            ->label('Use Direct File Paths')
                            ->default(false)
                            ->helperText('When enabled, uses direct file paths instead of Emby/Jellyfin streaming URLs. Requires file access from this server.'),
                        Toggle::make('auto_enable')
                            ->label('Auto-enable Series')
                            ->default(true)
                            ->helperText('Automatically enable imported series.'),
                    ])
                    ->action(function (array $data) {
                        // Validate library selection
                        if (in_array($data['library_id'], ['_not_configured', '_no_libraries', '_error'])) {
                            Notification::make()
                                ->danger()
                                ->title('Cannot Sync')
                                ->body('Please configure Emby/Jellyfin in Settings first.')
                                ->send();
                            return;
                        }

                        $playlist = Playlist::findOrFail($data['playlist_id']);

                        // Get library name
                        $embyService = new EmbyService();
                        $libraries = $embyService->getLibraries();
                        $libraryName = 'Emby/Jellyfin TV Shows';
                        foreach ($libraries as $library) {
                            if ($library['ItemId'] === $data['library_id']) {
                                $libraryName = $library['Name'];
                                break;
                            }
                        }

                        dispatch(new ProcessEmbySeriesSync(
                            playlist: $playlist,
                            libraryId: $data['library_id'],
                            libraryName: $libraryName,
                            useDirectPath: $data['use_direct_path'] ?? false,
                            autoEnable: $data['auto_enable'] ?? true,
                        ));
                    })
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Emby/Jellyfin sync started')
                            ->body('Syncing TV series from Emby/Jellyfin library. You will be notified once the process is complete.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-tv')
                    ->modalDescription('Sync TV series from your Emby/Jellyfin server library. This will import all series with their seasons, episodes, metadata, and posters.')
                    ->modalSubmitActionLabel('Sync now'),
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
                            ->hidden(fn(Get $get) => $get('all_playlists') === true)
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
                            ->hidden(fn(Get $get) => $get('all_playlists') === true)
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
                            ->hidden(fn(Get $get) => $get('all_series') === true)
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
                    ->modalSubmitActionLabel('Replace now')
            ])->button()->label('Actions')
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
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
                ->modifyQueryUsing(fn($query) => $query->where('enabled', true))
                ->badge($enabledCount),
            'disabled' => Tab::make('Disabled')
                // ->icon('heroicon-m-x-mark')
                ->badgeColor('danger')
                ->modifyQueryUsing(fn($query) => $query->where('enabled', false))
                ->badge($disabledCount),
        ];
    }
}
