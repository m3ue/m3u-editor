<?php

namespace App\Filament\Resources\Vods\Pages;

use Filament\Actions\CreateAction;
use Filament\Actions\ActionGroup;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Placeholder;
use App\Jobs\MergeChannels;
use App\Jobs\UnmergeChannels;
use App\Jobs\MapPlaylistChannelsToEpg;
use App\Jobs\FetchTmdbIds;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\TextInput;
use App\Jobs\ChannelFindAndReplace;
use App\Jobs\ChannelFindAndReplaceReset;
use Filament\Actions\ImportAction;
use Filament\Actions\ExportAction;
use Filament\Schemas\Components\Tabs\Tab;
use App\Filament\Exports\ChannelExporter;
use App\Filament\Imports\ChannelImporter;
use App\Filament\Resources\Vods\VodResource;
use App\Filament\Resources\EpgMaps\EpgMapResource;
use App\Jobs\ProcessVodChannels;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\Playlist;
use App\Settings\GeneralSettings;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Hydrat\TableLayoutToggle\Concerns\HasToggleableTable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ListVod extends ListRecords
{
    protected static string $resource = VodResource::class;

    protected ?string $subheading = 'NOTE: VOD output order is based on: 1 Sort order, 2 Channel no. and 3 Title - in that order. You can edit your Playlist output to auto sort as well, which will define the sort order based on the playlist order.';

    public function setPage($page, $pageName = 'page'): void
    {
        parent::setPage($page, $pageName);

        $this->dispatch('scroll-to-top');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Create Custom Channel')
                ->modalHeading('New Custom Channel')
                ->modalDescription('NOTE: Custom channels need to be associated with a Playlist or Custom Playlist.')
                ->using(fn(array $data, string $model): Model => VodResource::createCustomChannel(
                    data: $data,
                    model: $model,
                ))
                ->slideOver(),
            ActionGroup::make([
                Action::make('merge')
                    ->label('Merge Same ID')
                    ->schema([
                        Select::make('playlist_id')
                            ->required()
                            ->label('Preferred Playlist')
                            ->options(Playlist::where('user_id', auth()->id())->pluck('name', 'id'))
                            ->live()
                            ->searchable()
                            ->helperText('Select a playlist to prioritize as the master during the merge process.'),
                        Repeater::make('failover_playlists')
                            ->label('')
                            ->helperText('Select one or more playlists use as failover source(s).')
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->orderColumn('sort')
                            ->simple(
                                Select::make('playlist_failover_id')
                                    ->label('Failover Playlists')
                                    ->options(Playlist::where('user_id', auth()->id())->pluck('name', 'id'))
                                    ->searchable()
                                    ->required()
                            )
                            ->distinct()
                            ->columns(1)
                            ->addActionLabel('Add failover playlist')
                            ->columnSpanFull()
                            ->minItems(1)
                            ->defaultItems(1),
                        Toggle::make('by_resolution')
                            ->label('Order by Resolution')
                            ->live()
                            ->helperText('⚠️ IPTV WARNING: This will analyze each stream to determine resolution, which may cause rate limiting or blocking with IPTV providers. Only enable if your provider allows stream analysis.')
                            ->default(false),
                        Toggle::make('deactivate_failover_channels')
                            ->label('Deactivate Failover Channels')
                            ->helperText('When enabled, channels that become failovers will be automatically disabled.')
                            ->default(false),
                    ])
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new MergeChannels(
                                user: auth()->user(),
                                playlists: collect($data['failover_playlists']),
                                playlistId: $data['playlist_id'],
                                checkResolution: $data['by_resolution'] ?? false, // Sort failovers by resolution, or by playlist (default behavior)
                                deactivateFailoverChannels: $data['deactivate_failover_channels'] ?? false,
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Channel merge started')
                            ->body('Merging channels in the background. You will be notified once the process is complete.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-pointing-in')
                    ->modalIcon('heroicon-o-arrows-pointing-in')
                    ->modalDescription('Merge all channels with the same ID into a single channel with failover.')
                    ->modalSubmitActionLabel('Merge now'),
                Action::make('unmerge')
                    ->schema([
                        Select::make('playlist_id')
                            ->label('Unmerge Playlist')
                            ->options(Playlist::where('user_id', auth()->id())->pluck('name', 'id'))
                            ->live()
                            ->searchable()
                            ->helperText('Playlist to unmerge channels from (or leave empty to unmerge all).'),
                    ])
                    ->label('Unmerge Same ID')
                    ->action(function ($data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new UnmergeChannels(
                                user: auth()->user(),
                                playlistId: $data['playlist_id'] ?? null
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Channel unmerge started')
                            ->body('Unmerging channels in the background. You will be notified once the process is complete.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-pointing-out')
                    ->color('warning')
                    ->modalIcon('heroicon-o-arrows-pointing-out')
                    ->modalDescription('Unmerge all channels with the same ID, removing all failover relationships.')
                    ->modalSubmitActionLabel('Unmerge now'),

                Action::make('process_vod')
                    ->label('Fetch Metadata')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->schema([
                        Toggle::make('overwrite_existing')
                            ->label('Overwrite Existing Metadata')
                            ->helperText('Overwrite existing metadata? If disabled, it will only fetch and process metadata if it does not already exist.')
                            ->default(false),
                        Select::make('playlist')
                            ->label('Playlist')
                            ->required()
                            ->helperText('Select the Playlist you would like to fetch VOD metadata for.')
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
                            ->title("Fetching VOD metadata for playlist")
                            ->body('The VOD metadata fetching and processing has been started. You will be notified when it is complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription('Fetch and process VOD metadata for the selected Playlist? Only enabled VOD channels will be processed.')
                    ->modalSubmitActionLabel('Yes, process now'),
                Action::make('fetch_tmdb_ids')
                    ->label('Fetch TMDB IDs')
                    ->icon('heroicon-o-magnifying-glass')
                    ->schema([
                        Toggle::make('overwrite_existing')
                            ->label('Overwrite Existing IDs')
                            ->helperText('Overwrite existing TMDB/IMDB IDs? If disabled, it will only fetch IDs for items that don\'t have them.')
                            ->default(false),
                        Select::make('playlist')
                            ->label('Playlist')
                            ->required()
                            ->helperText('Select the Playlist you would like to fetch TMDB IDs for.')
                            ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
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

                        $playlistId = $data['playlist'] ?? null;
                        $playlist = Playlist::find($playlistId);
                        if (!$playlist) {
                            return;
                        }

                        $vodCount = $playlist->channels()
                            ->where('is_vod', true)
                            ->where('enabled', true)
                            ->count();

                        if ($vodCount === 0) {
                            Notification::make()
                                ->warning()
                                ->title('No VOD channels found')
                                ->body('No enabled VOD channels found in the selected playlist.')
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
                            ->body('The TMDB ID lookup has been started. You will be notified when it is complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-magnifying-glass')
                    ->modalDescription('Search TMDB for matching movies and populate TMDB/IMDB IDs for all VOD channels in the selected playlist? This enables Trash Guides compatibility for Radarr.')
                    ->modalSubmitActionLabel('Yes, fetch IDs now'),
                Action::make('sync')
                    ->label('Sync VOD .strm files')
                    ->schema([
                        Select::make('playlist')
                            ->label('Playlist')
                            ->required()
                            ->helperText('Select the Playlist you would like to fetch VOD metadata for.')
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
                            ->title('.strm files are being synced for selected VOD channels')
                            ->body('You will be notified once complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-arrow-down')
                    ->modalIcon('heroicon-o-document-arrow-down')
                    ->modalDescription('Sync selected VOD .strm files now? This will generate .strm files for the selected VOD channels at the path set for the channels.')
                    ->modalSubmitActionLabel('Yes, sync now'),

                Action::make('map')
                    ->label('Map EPG to Playlist')
                    ->schema(EpgMapResource::getForm())
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new MapPlaylistChannelsToEpg(
                                epg: (int)$data['epg_id'],
                                playlist: $data['playlist_id'],
                                force: $data['override'],
                                recurring: $data['recurring'],
                                settings: $data['settings'] ?? [],
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('EPG to Channel mapping')
                            ->body('Channel mapping started, you will be notified when the process is complete.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->modalIcon('heroicon-o-link')
                    ->modalWidth(Width::FourExtraLarge)
                    ->modalDescription('Map the selected EPG to the selected Playlist channels.')
                    ->modalSubmitActionLabel('Map now'),

                Action::make('find-replace')
                    ->label('Find & Replace')
                    ->schema([
                        Toggle::make('all_playlists')
                            ->label('All Playlists')
                            ->live()
                            ->helperText('Apply find and replace to all playlists? If disabled, it will only apply to the selected playlist.')
                            ->default(true),
                        Select::make('playlist')
                            ->label('Playlist')
                            ->required()
                            ->helperText('Select the playlist you would like to apply changes to.')
                            ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->hidden(fn(Get $get) => $get('all_playlists') === true)
                            ->searchable(),
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
                                'info->description' => 'Description (metadata)',
                                'info->genre' => 'Genre (metadata)',
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

                Action::make('find-replace-reset')
                    ->label('Undo Find & Replace')
                    ->schema([
                        Toggle::make('all_playlists')
                            ->label('All Playlists')
                            ->live()
                            ->helperText('Apply reset to all playlists? If disabled, it will only apply to the selected playlist.')
                            ->default(false),
                        Select::make('playlist')
                            ->required()
                            ->label('Playlist')
                            ->helperText('Select the playlist you would like to apply the reset to.')
                            ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->hidden(fn(Get $get) => $get('all_playlists') === true)
                            ->searchable(),
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
                            ->title('Find & Replace reset started')
                            ->body('Find & Replace reset working in the background. You will be notified once the process is complete.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->modalIcon('heroicon-o-arrow-uturn-left')
                    ->modalDescription('Reset Find & Replace results back to playlist defaults. This will remove any custom values set in the selected column.')
                    ->modalSubmitActionLabel('Reset now'),

                ImportAction::make()
                    ->importer(ChannelImporter::class)
                    ->label('Import Channels')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('primary')
                    ->modalDescription('Import channels from a CSV or XLSX file.'),
                ExportAction::make()
                    ->exporter(ChannelExporter::class)
                    ->label('Export Channels')
                    ->icon('heroicon-m-arrow-up-tray')
                    ->color('primary')
                    ->modalDescription('Export channels to a CSV or XLSX file. NOTE: Only enabled channels will be exported.')
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
                    })
            ])->button()->label('Actions'),
        ];
    }

    public function getTabs(): array
    {
        return self::setupTabs();
    }

    public static function setupTabs($relationId = null): array
    {
        $where = [
            ['user_id', auth()->id()],
            ['is_vod', true], // Only VOD channels
        ];

        // Change count based on view
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
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();

        $withFailoverCount = Channel::query()->whereHas('failovers')->where($where)
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();

        // Return tabs
        return [
            'all' => Tab::make('All VOD Channels')
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
            'failover' => Tab::make('Failover')
                // ->icon('heroicon-m-x-mark')
                ->badgeColor('info')
                ->modifyQueryUsing(fn($query) => $query->whereHas('failovers'))
                ->badge($withFailoverCount),
            'custom' => Tab::make('Custom')
                // ->icon('heroicon-m-x-mark')
                ->modifyQueryUsing(fn($query) => $query->where('is_custom', true))
                ->badge($customCount),
        ];
    }
}
