<?php

namespace App\Filament\Resources\Playlists;

use App\Enums\PlaylistSourceType;
use App\Enums\Status;
use App\Facades\PlaylistFacade;
use App\Filament\Resources\Playlists\Pages\CreatePlaylist;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Filament\Resources\Playlists\Pages\ListPlaylists;
use App\Filament\Resources\Playlists\Pages\ViewPlaylist;
use App\Filament\Tables\SourceCategoriesTable;
use App\Filament\Tables\SourceGroupsTable;
use App\Jobs\CopyAttributesToPlaylist;
use App\Jobs\DuplicatePlaylist;
use App\Jobs\ProcessM3uImport;
use App\Jobs\ProcessM3uImportSeries;
use App\Jobs\ProcessVodChannels;
use App\Jobs\SyncMediaServer;
use App\Livewire\EpgViewer;
use App\Livewire\MediaFlowProxyUrl;
use App\Livewire\PlaylistEpgUrl;
use App\Livewire\PlaylistInfo;
use App\Livewire\PlaylistM3uUrl;
use App\Livewire\XtreamApiInfo;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistProfile;
use App\Models\SourceCategory;
use App\Models\SourceGroup;
use App\Models\StreamProfile;
use App\Rules\CheckIfUrlOrLocalPath;
use App\Rules\Cron;
use App\Services\EpgCacheService;
use App\Services\M3uProxyService;
use App\Services\ProfileService;
use App\Traits\HasUserFiltering;
use Carbon\Carbon;
use Cron\CronExpression;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use RyanChandler\FilamentProgressColumn\ProgressColumn;

class PlaylistResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = Playlist::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Playlist';

    public static function getRecordTitle(?Model $record): string|null|Htmlable
    {
        return $record->name;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'url'];
    }

    public static function getNavigationSort(): ?int
    {
        return 0;
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
            ->modifyQueryUsing(function (Builder $query) {
                $query->withCount([
                    'enabled_live_channels',
                    'enabled_vod_channels',
                    'enabled_series',
                    'groups',
                    'live_channels',
                    'vod_channels',
                    'series',
                ]);
            })
            ->deferLoading()
            ->columns([
                TextColumn::make('id')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->description(function ($record) {
                        if (in_array($record->source_type, [PlaylistSourceType::Emby, PlaylistSourceType::Jellyfin])) {
                            $integration = MediaServerIntegration::where('playlist_id', $record->id)->first();
                            $integrationLink = route('filament.admin.resources.media-server-integrations.edit', $integration->id);

                            return new HtmlString('
                            <div class="flex items-center gap-1">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4">
                                    <path d="M4.464 3.162A2 2 0 0 1 6.28 2h7.44a2 2 0 0 1 1.816 1.162l1.154 2.5c.067.145.115.291.145.438A3.508 3.508 0 0 0 16 6H4c-.288 0-.568.035-.835.1.03-.147.078-.293.145-.438l1.154-2.5Z" />
                                    <path fill-rule="evenodd" d="M2 9.5a2 2 0 0 1 2-2h12a2 2 0 1 1 0 4H4a2 2 0 0 1-2-2Zm13.24 0a.75.75 0 0 1 .75-.75H16a.75.75 0 0 1 .75.75v.01a.75.75 0 0 1-.75.75h-.01a.75.75 0 0 1-.75-.75V9.5Zm-2.25-.75a.75.75 0 0 0-.75.75v.01c0 .414.336.75.75.75H13a.75.75 0 0 0 .75-.75V9.5a.75.75 0 0 0-.75-.75h-.01ZM2 15a2 2 0 0 1 2-2h12a2 2 0 1 1 0 4H4a2 2 0 0 1-2-2Zm13.24 0a.75.75 0 0 1 .75-.75H16a.75.75 0 0 1 .75.75v.01a.75.75 0 0 1-.75.75h-.01a.75.75 0 0 1-.75-.75V15Zm-2.25-.75a.75.75 0 0 0-.75.75v.01c0 .414.336.75.75.75H13a.75.75 0 0 0 .75-.75V15a.75.75 0 0 0-.75-.75h-.01Z" clip-rule="evenodd" />
                                </svg>
                                <a class="inline m-0 p-0 hover:underline" href="'.$integrationLink.'">Integration: '.$integration->name.'</a>
                            </div>');
                        }
                    })
                    ->sortable(),
                TextColumn::make('url')
                    ->label('Playlist URL')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                TextColumn::make('user_info')
                    ->label('Provider Streams')
                    ->getStateUsing(function ($record) {
                        if ($record->xtream) {
                            try {
                                // If profiles are enabled, show total capacity from all profiles
                                if ($record->profiles_enabled) {
                                    $poolStatus = ProfileService::getPoolStatus($record);

                                    return $poolStatus['total_capacity'] > 0 ? $poolStatus['total_capacity'] : 'N/A';
                                }
                                // Otherwise show primary account max connections
                                if ($record->xtream_status['user_info'] ?? false) {
                                    return $record->xtream_status['user_info']['max_connections'];
                                }
                            } catch (Exception $e) {
                            }
                        }

                        return 'N/A';
                    })
                    ->description(function (Playlist $record): string {
                        if (! $record->xtream) {
                            return '';
                        }
                        // If profiles are enabled, show combined active count
                        if ($record->profiles_enabled) {
                            $poolStatus = ProfileService::getPoolStatus($record);
                            $profileCount = count($poolStatus['profiles']);

                            return "Active: {$poolStatus['total_active']} ({$profileCount} profiles)";
                        }

                        // Otherwise show primary account active
                        return 'Active: '.($record->xtream_status['user_info']['active_cons'] ?? 0);
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('available_streams')
                    ->label('Proxy Streams')
                    ->toggleable()
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? '∞' : (string) $state)
                    ->tooltip('Total streams available for this playlist (∞ indicates no limit)')
                    ->description(function (Playlist $record): string {
                        // Cache active streams count for 5 seconds to reduce load
                        $count = Cache::remember(
                            "active_streams_{$record->id}",
                            5,
                            fn () => M3uProxyService::getPlaylistActiveStreamsCount($record)
                        );

                        return "Active: {$count}";
                    })
                    ->sortable(),
                TextColumn::make('groups_count')
                    ->label('Groups')
                    ->counts('groups')
                    ->toggleable()
                    ->sortable(),
                // Tables\Columns\TextColumn::make('channels_count')
                //     ->label('Channels')
                //     ->counts('channels')
                //     ->description(fn(Playlist $record): string => "Enabled: {$record->enabled_channels_count}")
                //     ->toggleable()
                //     ->sortable(),
                TextColumn::make('live_channels_count')
                    ->label('Live')
                    ->counts('live_channels')
                    ->description(fn (Playlist $record): string => "Enabled: {$record->enabled_live_channels_count}")
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('vod_channels_count')
                    ->label('VOD')
                    ->counts('vod_channels')
                    ->description(fn (Playlist $record): string => "Enabled: {$record->enabled_vod_channels_count}")
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('series_count')
                    ->label('Series')
                    ->counts('series')
                    ->description(fn (Playlist $record): string => "Enabled: {$record->enabled_series_count}")
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->toggleable()
                    ->color(fn (Status $state) => $state->getColor()),
                ProgressColumn::make('progress')
                    ->label('Live Sync')
                    ->sortable()
                    ->poll(fn ($record) => $record->status === Status::Processing || $record->status === Status::Pending ? '3s' : null)
                    ->toggleable(),
                ProgressColumn::make('vod_progress')
                    ->label('VOD Sync')
                    ->sortable()
                    ->poll(fn ($record) => $record->status === Status::Processing || $record->status === Status::Pending ? '3s' : null)
                    ->toggleable(),
                ProgressColumn::make('series_progress')
                    ->label('Series Sync')
                    ->sortable()
                    ->poll(fn ($record) => $record->status === Status::Processing || $record->status === Status::Pending ? '3s' : null)
                    ->toggleable(),
                ToggleColumn::make('enable_proxy')
                    ->label('Proxy')
                    ->toggleable()
                    ->tooltip(fn (Playlist $record): string => $record->profiles_enabled
                        ? 'Proxy is required when Provider Profiles are enabled'
                        : 'Toggle proxy status')
                    ->disabled(fn (Playlist $record): bool => $record->profiles_enabled)
                    ->sortable(),
                ToggleColumn::make('auto_sync')
                    ->label('Auto Sync')
                    ->toggleable()
                    ->tooltip('Toggle auto-sync status')
                    ->sortable(),
                TextColumn::make('synced')
                    ->label('Last Synced')
                    ->since()
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('sync_interval')
                    ->label('Next Sync')
                    ->toggleable()
                    ->formatStateUsing(function ($state, $record) {
                        if ($record->auto_sync && $record->sync_interval && CronExpression::isValidExpression($record->sync_interval)) {
                            return (new CronExpression($record->sync_interval))->getNextRunDate()->format('Y-m-d H:i:s');
                        }

                        return 'N/A';
                    })
                    ->sortable(),
                TextColumn::make('sync_time')
                    ->label('Sync Time')
                    ->formatStateUsing(fn (string $state): string => gmdate('H:i:s', (int) $state))
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('exp_date')
                    ->label('Expiry Date')
                    ->getStateUsing(function ($record) {
                        if ($record->xtream) {
                            try {
                                if ($record->xtream_status['user_info']['exp_date'] ?? false) {
                                    $expires = Carbon::createFromTimestamp($record->xtream_status['user_info']['exp_date']);

                                    return $expires->toDayDateTimeString();
                                }
                            } catch (Exception $e) {
                            }
                        }

                        return 'N/A';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('process')
                        ->label('Sync and Process')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function ($record) {
                            // For media server playlists, dispatch the media server sync job
                            if (in_array($record->source_type, [PlaylistSourceType::Emby, PlaylistSourceType::Jellyfin])) {
                                $integration = MediaServerIntegration::where('playlist_id', $record->id)->first();
                                if ($integration) {
                                    app('Illuminate\Contracts\Bus\Dispatcher')
                                        ->dispatch(new SyncMediaServer($integration->id));

                                    return;
                                }
                            }

                            // For regular playlists, use the standard M3U import process
                            $record->update([
                                'status' => Status::Processing,
                                'progress' => 0,
                                'vod_progress' => 0,
                            ]);
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new ProcessM3uImport($record, force: true));
                        })->after(function ($record) {
                            $isMediaServer = in_array($record->source_type, [PlaylistSourceType::Emby, PlaylistSourceType::Jellyfin]);
                            $message = $isMediaServer
                                ? 'Media server content is being synced in the background. Depending on the size of your library, this may take several minutes. You will be notified on completion.'
                                : 'Playlist is being processed in the background. Depending on the size of your playlist, this may take a while. You will be notified on completion.';

                            Notification::make()
                                ->success()
                                ->title($isMediaServer ? 'Media server sync started' : 'Playlist is processing')
                                ->body($message)
                                ->duration(10000)
                                ->send();
                        })
                        ->disabled(fn ($record): bool => $record->isProcessing())
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription(function ($record) {
                            $isMediaServer = in_array($record->source_type, [PlaylistSourceType::Emby, PlaylistSourceType::Jellyfin]);

                            return $isMediaServer
                                ? 'Sync content from the media server now? This will fetch all movies, series, and episodes from your media server library.'
                                : 'Process playlist now?';
                        })
                        ->modalSubmitActionLabel('Yes, sync now'),
                    Action::make('reset_processing')
                        ->label('Reset Processing State')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Reset Processing State')
                        ->modalDescription('This will clear any stuck processing locks and allow new syncs to run. Use this if syncs appear stuck.')
                        ->modalSubmitActionLabel('Reset')
                        ->action(function (Playlist $record) {
                            // Clear processing flag
                            $record->update([
                                'processing' => [
                                    'live_processing' => false,
                                    'vod_processing' => false,
                                    'series_processing' => false,
                                ],
                            ]);

                            Notification::make()
                                ->success()
                                ->title('Processing state reset')
                                ->body('The playlist is no longer processing. You can now run new syncs.')
                                ->send();
                        })
                        ->visible(fn (Playlist $record) => $record->isProcessing()),
                    Action::make('process_series')
                        ->label('Fetch Series Metadata')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function ($record) {
                            $record->update([
                                'status' => Status::Processing,
                                'series_progress' => 0,
                            ]);
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new ProcessM3uImportSeries($record, force: true));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist is fetching metadata for Series')
                                ->body('Playlist Series are being processed in the background. Depending on the number of enabled Series, this may take a while. You will be notified on completion.')
                                ->duration(10000)
                                ->send();
                        })
                        ->disabled(fn ($record): bool => $record->isProcessing())
                        ->hidden(fn ($record): bool => ! $record->xtream)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-down-tray')
                        ->modalIcon('heroicon-o-arrow-down-tray')
                        ->modalDescription('Fetch Series metadata for this playlist now? Only enabled Series will be included.')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Action::make('process_vod')
                        ->label('Fetch VOD Metadata')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function ($record) {
                            $record->update([
                                'status' => Status::Processing,
                                'progress' => 0,
                            ]);
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new ProcessVodChannels(playlist: $record));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist is fetching metadata for VOD channels')
                                ->body('Playlist VOD channels are being processed in the background. Depending on the number of enabled VOD channels, this may take a while. You will be notified on completion.')
                                ->duration(10000)
                                ->send();
                        })
                        ->disabled(fn ($record): bool => $record->isProcessing())
                        ->hidden(fn ($record): bool => ! $record->xtream)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-down-tray')
                        ->modalIcon('heroicon-o-arrow-down-tray')
                        ->modalDescription('Fetch VOD metadata for this playlist now? Only enabled VOD channels will be included.')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Action::make('Download M3U')
                        ->label('Download M3U')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn ($record) => PlaylistFacade::getUrls($record)['m3u'])
                        ->openUrlInNewTab(),
                    EpgCacheService::getEpgTableAction(),
                    Action::make('HDHomeRun URL')
                        ->label('HDHomeRun URL')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn ($record) => PlaylistFacade::getUrls($record)['hdhr'])
                        ->openUrlInNewTab(),
                    Action::make('Public URL')
                        ->label('Public URL')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn ($record) => '/playlist/v/'.$record->uuid)
                        ->openUrlInNewTab(),
                    Action::make('Duplicate')
                        ->label('Duplicate')
                        ->schema([
                            TextInput::make('name')
                                ->label('Playlist name')
                                ->required()
                                ->helperText('This will be the name of the duplicated playlist.'),
                        ])
                        ->action(function ($record, $data) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new DuplicatePlaylist($record, $data['name']));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist is being duplicated')
                                ->body('Playlist is being duplicated in the background. You will be notified on completion.')
                                ->duration(3000)
                                ->send();
                        })
                        ->hidden(fn ($record): bool => $record->source_type !== null)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-document-duplicate')
                        ->modalIcon('heroicon-o-document-duplicate')
                        ->modalDescription('Duplicate playlist now?')
                        ->modalSubmitActionLabel('Yes, duplicate now'),

                    Action::make('Copy Changes')
                        ->label('Copy Changes')
                        ->schema([
                            Select::make('target_playlist_id')
                                ->label('Target Playlist')
                                ->options(function ($record) {
                                    return Playlist::where('id', '!=', $record->id)
                                        ->where('user_id', auth()->id())
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->toArray();
                                })
                                ->searchable()
                                ->required(),
                            Select::make('channel_match_attributes')
                                ->label('Channel Match Attributes')
                                ->options([
                                    'name' => 'Name',
                                    'title' => 'Title',
                                    'url' => 'URL',
                                    'stream_id' => 'TVG-ID/Stream ID',
                                    'station_id' => 'Station ID (tvc-guide-stationid)',
                                    'logo_internal' => 'Logo (tvg-logo)',
                                    'channel' => 'Channel Number (tvg-chno/num)',
                                ])
                                ->hintIcon(
                                    'heroicon-s-information-circle',
                                    tooltip: 'Select the channel attributes to match channels between the source and target playlists. Channels will be matched based on these attributes. If multiple attributes are selected, all must match for a channel to be considered the same.',
                                )
                                ->multiple()
                                ->required()
                                ->default(['url'])
                                ->columnSpanFull(),
                            Toggle::make('create_missing_channels')
                                ->label('Create Missing Channels')
                                ->live()
                                ->hintIcon(
                                    'heroicon-s-information-circle',
                                    tooltip: 'If enabled, missing channels will be created in the target playlist. If disabled, only existing matched channels will be updated.',
                                )
                                ->default(false),
                            Toggle::make('all_attributes')
                                ->label('All Attributes')
                                ->live()
                                ->hintIcon(
                                    'heroicon-s-information-circle',
                                    tooltip: 'If enabled, all channel attributes will be copied to the target playlist. If disabled, only the selected attributes below will be copied.',
                                )
                                ->default(true),
                            Select::make('channel_attributes')
                                ->label('Channel Attributes to Copy')
                                ->options([
                                    'enabled' => 'Enabled Status',
                                    'name' => 'Name',
                                    'title' => 'Title',
                                    'logo_internal' => 'Logo (tvg-logo)',
                                    'stream_id' => 'TVG-ID/Stream ID',
                                    'station_id' => 'Station ID (tvc-guide-stationid)',
                                    'group' => 'Group (group-title)',
                                    'shift' => 'Shift (tvg-shift)',
                                    'channel' => 'Channel Number (tvg-chno/num)',
                                    'sort' => 'Sort Order',
                                ])
                                ->multiple()
                                ->required()
                                ->helperText('Select the channel attributes you want to copy to the target playlist.')
                                ->hidden(fn ($get) => (bool) $get('all_attributes')),
                            Toggle::make('overwrite')
                                ->label('Overwrite Existing Attributes')
                                ->hintIcon(
                                    'heroicon-s-information-circle',
                                    tooltip: 'If enabled, existing custom attributes in the target playlist will be overwritten. If disabled, only empty custom attributes will be updated.',
                                )
                                ->default(true),
                        ])
                        ->action(function ($record, $data) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new CopyAttributesToPlaylist(
                                    source: $record,
                                    targetId: $data['target_playlist_id'],
                                    channelAttributes: $data['channel_attributes'] ?? [],
                                    channelMatchAttributes: $data['channel_match_attributes'] ?? ['url'],
                                    createIfMissing: $data['create_missing_channels'] ?? false,
                                    allAttributes: $data['all_attributes'] ?? false,
                                    overwrite: $data['overwrite'] ?? false,
                                ));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist settings are being copied')
                                ->body('Playlist settings are being copied in the background. You will be notified on completion.')
                                ->duration(3000)
                                ->send();
                        })
                        ->hidden(fn ($record): bool => $record->source_type !== null)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-clipboard-document')
                        ->modalIcon('heroicon-o-clipboard-document')
                        ->modalDescription('Select the target playlist and channel attributes to copy')
                        ->modalSubmitActionLabel('Copy now'),

                    Action::make('view_sync_logs')
                        ->label('View Sync Logs')
                        ->color('gray')
                        ->icon('heroicon-m-arrows-right-left')
                        ->url(function (Playlist $record): string {
                            return "/playlists/{$record->id}/playlist-sync-statuses";
                        })
                        ->openUrlInNewTab(false),
                    Action::make('reset')
                        ->label('Reset status')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->action(function ($record) {
                            $record->update([
                                'status' => Status::Pending,
                                'processing' => [
                                    'live_processing' => false,
                                    'vod_processing' => false,
                                    'series_processing' => false,
                                ],
                                'progress' => 0,
                                'series_progress' => 0,
                                'vod_progress' => 0,
                                'channels' => 0,
                                'synced' => null,
                                'errors' => null,
                            ]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist status reset')
                                ->body('Playlist status has been reset.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalIcon('heroicon-o-arrow-uturn-left')
                        ->modalDescription('Reset playlist status so it can be processed again. Only perform this action if you are having problems with the playlist syncing.')
                        ->modalSubmitActionLabel('Yes, reset now'),
                    Action::make('purge_series')
                        ->label('Purge Series')
                        ->icon('heroicon-s-trash')
                        ->color('danger')
                        ->action(function ($record) {
                            $record->series()->delete();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-s-trash')
                        ->modalIcon('heroicon-s-trash')
                        ->modalDescription('This action will permanently delete all series associated with the playlist. Proceed with caution.')
                        ->modalSubmitActionLabel('Purge now')
                        ->hidden(fn ($record): bool => ! $record->xtream),
                    DeleteAction::make()
                        ->disabled(fn ($record): bool => $record->isProcessing()),
                ])->button()->hiddenLabel()->size('sm'),
                EditAction::make()->button()->hiddenLabel()->size('sm'),
                ViewAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('process')
                        ->label('Process selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                // For media server playlists, dispatch the media server sync job
                                if (in_array($record->source_type, [PlaylistSourceType::Emby, PlaylistSourceType::Jellyfin])) {
                                    $integration = MediaServerIntegration::where('playlist_id', $record->id)->first();
                                    if ($integration) {
                                        app('Illuminate\Contracts\Bus\Dispatcher')
                                            ->dispatch(new SyncMediaServer($integration->id));

                                        continue;
                                    }
                                }

                                // For regular playlists, use the standard M3U import process
                                $record->update([
                                    'status' => Status::Processing,
                                    'progress' => 0,
                                ]);
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new ProcessM3uImport($record, force: true));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected playlists are processing')
                                ->body('The selected playlists are being processed in the background. Depending on the size of your playlist, this may take a while.')
                                ->duration(10000)
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process the selected playlist(s) now?')
                        ->modalSubmitActionLabel('Yes, process now'),
                    BulkAction::make('reset')
                        ->label('Reset status')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                $record->update([
                                    'status' => Status::Pending,
                                    'processing' => [
                                        'live_processing' => false,
                                        'vod_processing' => false,
                                        'series_processing' => false,
                                    ],
                                    'progress' => 0,
                                    'series_progress' => 0,
                                    'vod_progress' => 0,
                                    'channels' => 0,
                                    'synced' => null,
                                    'errors' => null,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist status reset')
                                ->body('Status has been reset for the selected Playlists.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalIcon('heroicon-o-arrow-uturn-left')
                        ->modalDescription('Reset status for the selected Playlists so they can be processed again. Only perform this action if you are having problems with the playlist syncing.')
                        ->modalSubmitActionLabel('Yes, reset now'),
                    DeleteBulkAction::make(),
                ]),
            ])->checkIfRecordIsSelectableUsing(
                fn ($record): bool => $record->status !== Status::Processing && $record->source_type === null,
            );
    }

    public static function getRelations(): array
    {
        return [
            // Removed SyncStatusesRelationManager to avoid showing it as a tab
            // Sync statuses are now accessible via direct navigation to the nested resource
        ];
    }

    public static function getPages(): array
    {
        return [
            // Playlists
            'index' => ListPlaylists::route('/'),
            'create' => CreatePlaylist::route('/create'),
            'view' => ViewPlaylist::route('/{record}'),
            'edit' => EditPlaylist::route('/{record}/edit'),
        ];
    }

    public static function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('process')
                    ->label('Sync and Process')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function ($record) {
                        // For media server playlists, dispatch the media server sync job
                        if (in_array($record->source_type, [PlaylistSourceType::Emby, PlaylistSourceType::Jellyfin])) {
                            $integration = MediaServerIntegration::where('playlist_id', $record->id)->first();
                            if ($integration) {
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new SyncMediaServer($integration->id));

                                return;
                            }
                        }

                        // For regular playlists, use the standard M3U import process
                        $record->update([
                            'status' => Status::Processing,
                            'progress' => 0,
                        ]);
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ProcessM3uImport($record, force: true));
                    })->after(function ($record) {
                        $isMediaServer = in_array($record->source_type, [PlaylistSourceType::Emby, PlaylistSourceType::Jellyfin]);
                        $message = $isMediaServer
                            ? 'Media server content is being synced in the background. Depending on the size of your library, this may take several minutes. You will be notified on completion.'
                            : 'Playlist is being processed in the background. Depending on the size of your playlist, this may take a while. You will be notified on completion.';

                        Notification::make()
                            ->success()
                            ->title($isMediaServer ? 'Media server sync started' : 'Playlist is processing')
                            ->body($message)
                            ->duration(10000)
                            ->send();
                    })
                    ->disabled(fn ($record): bool => $record->isProcessing())
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-path')
                    ->modalIcon('heroicon-o-arrow-path')
                    ->modalDescription(function ($record) {
                        $isMediaServer = in_array($record->source_type, [PlaylistSourceType::Emby, PlaylistSourceType::Jellyfin]);

                        return $isMediaServer
                            ? 'Sync content from the media server now? This will fetch all movies, series, and episodes from your media server library.'
                            : 'Process playlist now?';
                    })
                    ->modalSubmitActionLabel('Yes, sync now'),
                Action::make('process_series')
                    ->label('Fetch Series Metadata')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function ($record) {
                        $record->update([
                            'status' => Status::Processing,
                            'series_progress' => 0,
                        ]);
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ProcessM3uImportSeries($record, force: true));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Playlist is fetching metadata for Series')
                            ->body('Playlist Series are being processed in the background. Depending on the number of enabled Series, this may take a while. You will be notified on completion.')
                            ->duration(10000)
                            ->send();
                    })
                    ->disabled(fn ($record): bool => $record->isProcessingSeries())
                    ->hidden(fn ($record): bool => ! $record->xtream)
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription('Fetch Series metadata for this playlist now? Only enabled Series will be included.')
                    ->modalSubmitActionLabel('Yes, process now'),
                Action::make('process_vod')
                    ->label('Fetch VOD Metadata')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function ($record) {
                        $record->update([
                            'status' => Status::Processing,
                            'progress' => 0,
                        ]);
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ProcessVodChannels(playlist: $record));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Playlist is fetching metadata for VOD channels')
                            ->body('Playlist VOD channels are being processed in the background. Depending on the number of enabled VOD channels, this may take a while. You will be notified on completion.')
                            ->duration(10000)
                            ->send();
                    })
                    ->disabled(fn ($record): bool => $record->isProcessingVod())
                    ->hidden(fn ($record): bool => ! $record->xtream)
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription('Fetch VOD metadata for this playlist now? Only enabled VOD channels will be included.')
                    ->modalSubmitActionLabel('Yes, process now'),
                Action::make('reset_processing')
                    ->label('Reset Processing State')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reset Processing State')
                    ->modalDescription('This will clear any stuck processing locks and allow new syncs to run. Use this if syncs appear stuck.')
                    ->modalSubmitActionLabel('Reset')
                    ->action(function ($record) {
                        // Clear processing flag
                        $record->update([
                            'processing' => [
                                'live_processing' => false,
                                'vod_processing' => false,
                                'series_processing' => false,
                            ],
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Processing state reset')
                            ->body('The playlist is no longer processing. You can now run new syncs.')
                            ->send();
                    })
                    ->visible(fn ($record) => $record->isProcessing()),
                Action::make('Download M3U')
                    ->label('Download M3U')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn ($record) => PlaylistFacade::getUrls($record)['m3u'])
                    ->openUrlInNewTab(),
                EpgCacheService::getEpgPlaylistAction(),
                Action::make('HDHomeRun URL')
                    ->label('HDHomeRun URL')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => PlaylistFacade::getUrls($record)['hdhr'])
                    ->openUrlInNewTab(),
                Action::make('Duplicate')
                    ->label('Duplicate')
                    ->schema([
                        TextInput::make('name')
                            ->label('Playlist name')
                            ->required()
                            ->helperText('This will be the name of the duplicated playlist.'),
                    ])
                    ->action(function ($record, $data) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new DuplicatePlaylist($record, $data['name']));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Playlist is being duplicated')
                            ->body('Playlist is being duplicated in the background. You will be notified on completion.')
                            ->duration(3000)
                            ->send();
                    })
                    ->hidden(fn ($record): bool => $record->source_type !== null)
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-duplicate')
                    ->modalIcon('heroicon-o-document-duplicate')
                    ->modalDescription('Duplicate playlist now?')
                    ->modalSubmitActionLabel('Yes, duplicate now'),
                Action::make('reset')
                    ->label('Reset status')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->action(function ($record) {
                        $record->update([
                            'status' => Status::Pending,
                            'processing' => [
                                'live_processing' => false,
                                'vod_processing' => false,
                                'series_processing' => false,
                            ],
                            'progress' => 0,
                            'series_progress' => 0,
                            'vod_progress' => 0,
                            'channels' => 0,
                            'synced' => null,
                            'errors' => null,
                        ]);
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Playlist status reset')
                            ->body('Playlist status has been reset.')
                            ->duration(3000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->modalIcon('heroicon-o-arrow-uturn-left')
                    ->modalDescription('Reset playlist status so it can be processed again. Only perform this action if you are having problems with the playlist syncing.')
                    ->modalSubmitActionLabel('Yes, reset now'),
                DeleteAction::make()
                    ->disabled(fn ($record): bool => $record->isProcessing()),
            ])->button(),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        $extraLinks = [];
        if (PlaylistFacade::mediaFlowProxyEnabled()) {
            $extraLinks[] = Livewire::make(MediaFlowProxyUrl::class);
        }
        $extraLinks[] = Livewire::make(PlaylistEpgUrl::class);

        return $schema
            ->components([
                Tabs::make()
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Details')
                            ->icon('heroicon-o-play')
                            ->schema([
                                Livewire::make(PlaylistInfo::class),
                            ]),
                        Tab::make('Links')
                            ->icon('heroicon-m-link')
                            ->schema([
                                Section::make()
                                    ->columns(2)
                                    ->schema([
                                        Grid::make()
                                            ->columnSpan(1)
                                            ->columns(1)
                                            ->schema([
                                                Livewire::make(PlaylistM3uUrl::class)
                                                    ->columnSpanFull(),
                                            ]),
                                        Grid::make()
                                            ->columnSpan(1)
                                            ->columns(1)
                                            ->schema($extraLinks),
                                    ]),
                            ]),
                        Tab::make('Xtream API')
                            ->icon('heroicon-m-bolt')
                            ->schema([
                                Section::make()
                                    ->columns(1)
                                    ->schema([
                                        Livewire::make(XtreamApiInfo::class),
                                    ]),
                            ]),
                    ])->contained(false),
                Livewire::make(EpgViewer::class)
                    ->columnSpanFull(),
            ]);
    }

    public static function getFormSections($creating = false, $includeAuth = false): array
    {
        // Define the form fields for each section
        $nameFields = [
            TextInput::make('name')
                ->helperText('Enter the name of the playlist. Internal use only.')
                ->required(),
            Grid::make()
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    Toggle::make('short_urls_enabled')
                        ->label('Use Short URLs')
                        ->helperText('When enabled, short URLs will be used for the playlist links. Save changes to generate the short URLs (or remove them).')
                        ->columnSpan(2)
                        ->inline(false)
                        ->default(false),
                    Toggle::make('edit_uuid')
                        ->label('View/Update Unique Identifier')
                        ->columnSpanFull()
                        ->inline(false)
                        ->live()
                        ->dehydrated(false)
                        ->default(false),
                    TextInput::make('uuid')
                        ->label('Unique Identifier')
                        ->columnSpanFull()
                        ->rules(function ($record) {
                            return [
                                'required',
                                'min:3',
                                'max:36',
                                Rule::unique('playlists', 'uuid')->ignore($record?->id),
                                Rule::unique('playlist_aliases', 'uuid'), // Ensure UUID is unique in playlist_aliases table as well
                            ];
                        })
                        ->helperText('Value must be between 3 and 36 characters.')
                        ->hintIcon(
                            'heroicon-m-exclamation-triangle',
                            tooltip: 'Be careful changing this value as this will change the URLs for the Playlist, its EPG, and HDHR.'
                        )
                        ->hidden(fn ($get): bool => ! $get('edit_uuid'))
                        ->required(),
                ])->hiddenOn('create'),
        ];

        $typeFields = [
            Grid::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    ToggleButtons::make('xtream')
                        ->label('Playlist type')
                        ->grouped()
                        ->boolean()
                        ->options([
                            false => 'm3u8 url or local file',
                            true => 'Xtream API',
                        ])
                        ->icons([
                            false => 'heroicon-s-link',
                            true => 'heroicon-s-bolt',
                        ])
                        ->colors([
                            false => 'primary',
                            true => 'success',
                        ])
                        ->default(false)
                        ->live(),
                    TextInput::make('xtream_config.url')
                        ->label('Xtream API URL')
                        ->live()
                        ->helperText('Enter the full url, using <url>:<port> format - without trailing slash (/).')
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->maxLength(4000)
                        ->url()
                        ->columnSpan(2)
                        ->required()
                        ->hidden(fn (Get $get): bool => ! $get('xtream')),
                    Grid::make()
                        ->columnSpanFull()
                        ->schema([
                            Fieldset::make('Config')
                                ->columns(3)
                                ->schema([
                                    TextInput::make('xtream_config.username')
                                        ->label('Xtream API Username')
                                        ->live()
                                        ->required()
                                        ->columnSpan(1),
                                    TextInput::make('xtream_config.password')
                                        ->label('Xtream API Password')
                                        ->live()
                                        ->required()
                                        ->columnSpan(1)
                                        ->password()
                                        ->revealable(),
                                    Select::make('xtream_config.output')
                                        ->label('Input Stream Format')
                                        ->required()
                                        ->columnSpan(1)
                                        ->hintIcon(
                                            'heroicon-s-information-circle',
                                            tooltip: 'This is the format that will be used for the imported streams. If you change this later, the playlist will need to be synced for the changes to be applied.',
                                        )
                                        ->options([
                                            'ts' => 'MPEG-TS (.ts)',
                                            'm3u8' => 'HLS (.m3u8)',
                                        ])->default('ts'),
                                    CheckboxList::make('xtream_config.import_options')
                                        ->label('Groups and Streams to Import')
                                        ->columnSpan(2)
                                        ->live()
                                        ->options([
                                            'live' => 'Live',
                                            'vod' => 'VOD',
                                            'series' => 'Series',
                                        ])->helperText('NOTE: Playlist series can be managed in the Series section. You will need to enabled the VOD channels and Series you wish to import metadata for as it will only be imported for enabled channels and series.'),
                                    Toggle::make('xtream_config.import_epg')
                                        ->label('Import EPG')
                                        ->helperText('If your provider supports EPG, you can import it automatically.')
                                        ->columnSpan(1)
                                        ->inline(false)
                                        ->default(true),
                                ]),
                        ])->hidden(fn (Get $get): bool => ! $get('xtream')),
                    TextInput::make('url')
                        ->label('URL or Local file path')
                        ->columnSpan(2)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->helperText('Enter the URL of the playlist file. If this is a local file, you can enter a full or relative path. If changing URL, the playlist will be re-imported. Use with caution as this could lead to data loss if the new playlist differs from the old one.')
                        ->requiredWithout('uploads')
                        ->rules([new CheckIfUrlOrLocalPath])
                        ->maxLength(255)
                        ->hidden(fn (Get $get): bool => (bool) $get('xtream')),
                    FileUpload::make('uploads')
                        ->label('File')
                        ->columnSpan(2)
                        ->disk('local')
                        ->directory('playlist')
                        ->helperText('Upload the playlist file. This will be used to import groups and channels.')
                        ->rules(['file'])
                        ->requiredWithout('url')
                        ->hidden(fn (Get $get): bool => (bool) $get('xtream')),
                ]),

            Grid::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('user_agent')
                        ->helperText('User agent string to use for fetching the playlist.')
                        ->default('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36')
                        ->columnSpan(2)
                        ->required(),
                    Toggle::make('disable_ssl_verification')
                        ->label('Disable SSL verification')
                        ->helperText('Only disable this if you are having issues.')
                        ->columnSpan(1)
                        ->onColor('danger')
                        ->inline(false)
                        ->default(false),
                ]),

            // Provider Profiles Section (Xtream only)
            Section::make('Provider Profiles')
                ->description('Pool multiple Xtream accounts from this provider to increase concurrent stream capacity.')
                ->icon('heroicon-o-user-group')
                ->collapsible()
                ->collapsed(fn (?Playlist $record): bool => ! ($record?->profiles_enabled ?? false))
                ->hidden(fn (Get $get): bool => ! $get('xtream'))
                ->schema([
                    Toggle::make('profiles_enabled')
                        ->label('Enable Provider Profiles')
                        ->helperText('When enabled, proxy mode is required for accurate connection tracking.')
                        ->live()
                        ->afterStateUpdated(function (Set $set, $state) {
                            if ($state) {
                                $set('enable_proxy', true);
                            }
                        })
                        ->rules([
                            fn (): \Closure => function (string $attribute, $value, \Closure $fail) {
                                if ($value && ! config('proxy.m3u_proxy_token')) {
                                    $fail('Provider Profiles require the m3u-proxy to be configured. Please ensure M3U_PROXY_TOKEN is set.');
                                }
                            },
                        ])
                        ->inline(false)
                        ->default(false),

                    Grid::make()
                        ->columns(2)
                        ->visible(fn (Get $get): bool => $get('profiles_enabled'))
                        ->schema([
                            Placeholder::make('primary_profile_info')
                                ->label('Primary Account')
                                ->content(function (?Playlist $record): string {
                                    if (! $record || ! $record->xtream_config) {
                                        return 'Configure Xtream credentials above first.';
                                    }

                                    $username = $record->xtream_config['username'] ?? 'Unknown';
                                    $primaryProfile = $record->profiles()->where('is_primary', true)->first();

                                    if ($primaryProfile) {
                                        $maxStreams = $primaryProfile->max_streams ?? 1;
                                        $providerMax = $primaryProfile->provider_max_connections ?? 'Unknown';

                                        return "Username: {$username} | Max Streams: {$maxStreams} (Provider: {$providerMax})";
                                    }

                                    return "Username: {$username} (Profile will be created when saved)";
                                }),

                            \Filament\Schemas\Components\Actions::make([
                                \Filament\Actions\Action::make('test_primary_profile')
                                    ->label('Test Primary')
                                    ->icon('heroicon-o-signal')
                                    ->color('info')
                                    ->tooltip('Test primary account credentials and detect max connections')
                                    ->action(function (Get $get, ?Playlist $record): void {
                                        $xtreamConfig = $record?->xtream_config;

                                        if (! $xtreamConfig) {
                                            // Try to build from form data
                                            $url = $get('xtream_config.url') ?? $get('xtream_config.server');
                                            $username = $get('xtream_config.username');
                                            $password = $get('xtream_config.password');

                                            if (empty($url) || empty($username) || empty($password)) {
                                                Notification::make()
                                                    ->title('Missing Credentials')
                                                    ->body('Please configure Xtream credentials first.')
                                                    ->danger()
                                                    ->send();

                                                return;
                                            }

                                            $xtreamConfig = [
                                                'url' => $url,
                                                'username' => $username,
                                                'password' => $password,
                                            ];
                                        } else {
                                            $xtreamConfig = [
                                                'url' => $xtreamConfig['url'] ?? $xtreamConfig['server'] ?? '',
                                                'username' => $xtreamConfig['username'] ?? '',
                                                'password' => $xtreamConfig['password'] ?? '',
                                            ];
                                        }

                                        $result = ProfileService::testCredentials($xtreamConfig);

                                        if ($result['valid']) {
                                            // If the primary profile exists, only update max_streams when not manually set
                                            $primaryProfile = $record?->profiles()->where('is_primary', true)->first();
                                            if ($primaryProfile) {
                                                $currentMax = $primaryProfile->max_streams ?? null;
                                                $shouldUpdateMax = ! $currentMax || $currentMax <= 1;

                                                if ($shouldUpdateMax && $result['max_connections'] > 0) {
                                                    $primaryProfile->update(['max_streams' => $result['max_connections']]);
                                                }
                                            }

                                            $expDate = $result['exp_date'] ? " | Expires: {$result['exp_date']}" : '';
                                            Notification::make()
                                                ->title('Primary Account Valid ✓')
                                                ->body("Status: {$result['status']} | Max Connections: {$result['max_connections']} | Active: {$result['active_cons']}{$expDate}")
                                                ->success()
                                                ->duration(8000)
                                                ->send();
                                        } else {
                                            Notification::make()
                                                ->title('Primary Account Test Failed')
                                                ->body($result['error'] ?? 'Unknown error')
                                                ->danger()
                                                ->send();
                                        }
                                    }),
                            ])->verticallyAlignEnd(),
                        ]),

                    Repeater::make('additional_profiles')
                        ->label('Additional Profiles')
                        ->relationship('profiles', fn ($query) => $query->where('is_primary', false)->orderBy('priority'))
                        ->visible(fn (Get $get): bool => $get('profiles_enabled'))
                        ->schema([
                            TextInput::make('name')
                                ->label('Profile Name')
                                ->placeholder('Backup Account')
                                ->columnSpan(2),
                            TextInput::make('url')
                                ->label('Provider URL')
                                ->placeholder(fn (Get $get, $livewire) => $livewire->getRecord()?->xtream_config['url'] ?? 'http://provider.com:port')
                                ->helperText('Leave blank to use the same provider as the primary account.')
                                ->columnSpan(2),
                            TextInput::make('username')
                                ->label('Username')
                                ->required()
                                ->live(onBlur: true)
                                ->columnSpan(1),
                            TextInput::make('password')
                                ->label('Password')
                                ->password()
                                ->revealable()
                                ->required(fn ($record) => $record === null) // Only required for new profiles
                                ->dehydrated(fn ($state, $record) => filled($state) || $record === null) // Only save if filled or new
                                ->dehydrateStateUsing(fn ($state, $record) => filled($state) ? $state : $record?->password)
                                ->placeholder(fn ($record) => $record?->password ? '••••••••' : null)
                                ->live(onBlur: true)
                                ->columnSpan(1),
                            TextInput::make('max_streams')
                                ->label('Max Streams')
                                ->numeric()
                                ->default(1)
                                ->minValue(1)
                                ->helperText('Use "Test" to auto-detect from provider.')
                                ->columnSpan(1),
                            TextInput::make('priority')
                                ->label('Priority')
                                ->numeric()
                                ->default(fn ($record) => PlaylistProfile::where('playlist_id', $record?->playlist_id)->max('priority') + 1 ?? 1)
                                ->helperText('Lower = tried first')
                                ->columnSpan(1),
                            Toggle::make('enabled')
                                ->label('Enabled')
                                ->default(true)
                                ->inline(false)
                                ->columnSpan(1),
                        ])
                        ->columns(4)
                        ->addActionLabel('Add Profile')
                        ->reorderable()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? $state['username'] ?? 'New Profile')
                        ->extraItemActions([
                            \Filament\Actions\Action::make('test_profile')
                                ->label('Test')
                                ->icon('heroicon-o-signal')
                                ->color('info')
                                ->tooltip('Test credentials and auto-detect max connections')
                                ->action(function (array $arguments, Repeater $component, Get $get, Set $set, ?Playlist $record): void {
                                    // Get the item data directly from the repeater's state
                                    $itemKey = $arguments['item'];
                                    $allItems = $component->getState();
                                    $profileData = $allItems[$itemKey] ?? null;

                                    // If password is empty, try to get it from the existing database record
                                    $password = $profileData['password'] ?? null;
                                    if (empty($password) && ! empty($profileData['id'])) {
                                        $existingProfile = PlaylistProfile::find($profileData['id']);
                                        $password = $existingProfile?->password;
                                    }

                                    if (! $profileData || empty($profileData['username']) || empty($password)) {
                                        Notification::make()
                                            ->title('Missing Credentials')
                                            ->body('Please enter username and password first.')
                                            ->danger()
                                            ->send();

                                        return;
                                    }

                                    // Use profile's URL if provided, otherwise use playlist's base URL
                                    $url = $profileData['url'] ?? $record?->xtream_config['url'] ?? $record?->xtream_config['server'] ?? null;

                                    if (empty($url)) {
                                        Notification::make()
                                            ->title('Missing URL')
                                            ->body('Please provide a provider URL or configure the playlist Xtream URL.')
                                            ->danger()
                                            ->send();

                                        return;
                                    }

                                    // Build xtream config for testing
                                    $testConfig = [
                                        'url' => $url,
                                        'username' => $profileData['username'],
                                        'password' => $password,
                                    ];

                                    $result = ProfileService::testCredentials($testConfig);

                                    if ($result['valid']) {
                                        $currentMax = $allItems[$itemKey]['max_streams'] ?? null;
                                        $shouldUpdateMax = ! $currentMax || $currentMax <= 1;

                                        if ($shouldUpdateMax && $result['max_connections'] > 0) {
                                            $allItems[$itemKey]['max_streams'] = $result['max_connections'];
                                            $component->state($allItems);
                                        }

                                        $expDate = $result['exp_date'] ? " | Expires: {$result['exp_date']}" : '';
                                        Notification::make()
                                            ->title('Profile Valid ✓')
                                            ->body("Status: {$result['status']} | Max Connections: {$result['max_connections']} | Active: {$result['active_cons']}{$expDate}")
                                            ->success()
                                            ->duration(8000)
                                            ->send();
                                    } else {
                                        Notification::make()
                                            ->title('Profile Test Failed')
                                            ->body($result['error'] ?? 'Unknown error')
                                            ->danger()
                                            ->send();
                                    }
                                }),
                        ])
                        ->mutateRelationshipDataBeforeCreateUsing(function (array $data, Get $get, $livewire): array {
                            $record = $livewire->getRecord();
                            $data['user_id'] = $record->user_id;
                            $data['playlist_id'] = $record->id;

                            // Auto-test credentials and populate max_streams if not manually set or set to default
                            if (($data['max_streams'] ?? 1) <= 1 && ! empty($data['username']) && ! empty($data['password'])) {
                                // Use profile URL if provided, otherwise use playlist URL
                                $url = $data['url'] ?? $record->xtream_config['url'] ?? $record->xtream_config['server'] ?? null;
                                if ($url) {
                                    $testConfig = [
                                        'url' => $url,
                                        'username' => $data['username'],
                                        'password' => $data['password'],
                                    ];
                                    $result = ProfileService::testCredentials($testConfig);
                                    if ($result['valid'] && $result['max_connections'] > 1) {
                                        $data['max_streams'] = $result['max_connections'];
                                    }
                                }
                            }

                            return $data;
                        })
                        ->mutateRelationshipDataBeforeSaveUsing(function (array $data, Get $get, $livewire, $record): array {
                            $playlist = $livewire->getRecord();

                            // If password is empty but we have an existing record, preserve the old password
                            if (empty($data['password']) && $record instanceof PlaylistProfile) {
                                $data['password'] = $record->password;
                            }

                            // If URL is empty but we have an existing record, preserve the old URL
                            if (empty($data['url']) && $record instanceof PlaylistProfile) {
                                $data['url'] = $record->url;
                            }

                            // Auto-test credentials and update max_streams if still at default
                            if (($data['max_streams'] ?? 1) <= 1 && ! empty($data['username']) && ! empty($data['password'])) {
                                // Use profile URL if provided, otherwise use playlist URL
                                $url = $data['url'] ?? $playlist->xtream_config['url'] ?? $playlist->xtream_config['server'] ?? null;
                                if ($url) {
                                    $testConfig = [
                                        'url' => $url,
                                        'username' => $data['username'],
                                        'password' => $data['password'],
                                    ];
                                    $result = ProfileService::testCredentials($testConfig);
                                    if ($result['valid'] && $result['max_connections'] > 1) {
                                        $data['max_streams'] = $result['max_connections'];
                                    }
                                }
                            }

                            return $data;
                        }),

                    Placeholder::make('pool_status')
                        ->label('Pool Status')
                        ->content(function (?Playlist $record, Get $get): HtmlString {
                            if (! $record || ! $record->profiles_enabled) {
                                return new HtmlString('Enable profiles to see pool status.');
                            }
                            $status = ProfileService::getPoolStatus($record);

                            // Check if primary profile exists - if not, estimate from xtream_status
                            $hasPrimaryProfile = collect($status['profiles'])->contains('is_primary', true);
                            if (! $hasPrimaryProfile && $record->xtream) {
                                // Primary profile will be created on save - show estimated capacity
                                $primaryMax = $record->xtream_status['user_info']['max_connections'] ?? 1;
                                $primaryActive = $record->xtream_status['user_info']['active_cons'] ?? 0;

                                // Add pending primary to the display
                                array_unshift($status['profiles'], [
                                    'is_primary' => true,
                                    'name' => 'Primary (pending)',
                                    'username' => $record->xtream_config['username'] ?? '',
                                    'enabled' => true,
                                    'max_streams' => $primaryMax,
                                    'active_connections' => $primaryActive,
                                ]);
                                $status['total_capacity'] += $primaryMax;
                                $status['total_active'] += $primaryActive;
                                $status['available'] = max(0, $status['total_capacity'] - $status['total_active']);
                            }

                            // Build profile breakdown
                            $profileLines = [];
                            foreach ($status['profiles'] as $profile) {
                                $name = $profile['is_primary'] ? '⭐ Primary' : ($profile['name'] ?? $profile['username']);
                                $statusIcon = $profile['enabled'] ? '✓' : '✗';
                                $profileLines[] = "{$statusIcon} {$name}: {$profile['active_connections']}/{$profile['max_streams']} streams";
                            }

                            $html = "<div class='space-y-1'>";
                            $html .= "<div class='font-semibold'>Total: {$status['total_active']}/{$status['total_capacity']} active | {$status['available']} available</div>";
                            if (count($profileLines) > 0) {
                                $html .= "<div class='text-sm text-gray-500 dark:text-gray-400'>".implode('<br>', $profileLines).'</div>';
                            }
                            $html .= '</div>';

                            return new HtmlString($html);
                        })
                        ->visible(fn (Get $get, ?Playlist $record): bool => $get('profiles_enabled') && $record?->exists),
                ]),
        ];

        $schedulingFields = [
            Grid::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Grid::make()
                        ->columns(2)
                        ->columnSpanFull()
                        ->schema([
                            Toggle::make('auto_sync')
                                ->label('Automatically sync playlist')
                                ->helperText('When enabled, the playlist will be automatically re-synced at the specified interval.')
                                ->live()
                                ->inline(false)
                                ->default(true),
                            Toggle::make('backup_before_sync')
                                ->label('Backup Before Sync')
                                ->helperText('When enabled, a backup will be created before syncing.')
                                ->inline(false)
                                ->default(false),
                        ]),

                    TextInput::make('sync_interval')
                        ->label('Sync Schedule')
                        ->suffix(config('app.timezone'))
                        ->rules([new Cron])
                        ->live()
                        ->placeholder('0 0 * * *')
                        ->columnSpanFull()
                        ->hintAction(
                            Action::make('view_cron_example')
                                ->label('CRON Example')
                                ->icon('heroicon-o-arrow-top-right-on-square')
                                ->iconPosition('after')
                                ->size('sm')
                                ->url('https://crontab.guru')
                                ->openUrlInNewTab(true)
                        )
                        ->helperText(fn ($get) => $get('sync_interval') && CronExpression::isValidExpression($get('sync_interval'))
                            ? 'Next scheduled sync: '.(new CronExpression($get('sync_interval')))->getNextRunDate()->format('Y-m-d H:i:s')
                            : 'Specify the CRON schedule for automatic sync, e.g. "0 3 * * *".')
                        ->hidden(fn (Get $get): bool => ! $get('auto_sync')),

                    DateTimePicker::make('synced')
                        ->columnSpan(2)
                        ->suffix(config('app.timezone'))
                        ->native(false)
                        ->label('Last Synced')
                        ->disabled()
                        ->helperText('The last time the playlist was successfully synced.')
                        ->dehydrated(false),
                ]),
        ];

        $processingFields = [
            Section::make('Playlist Processing')
                ->description('Processing settings for the playlist')
                ->columnSpanFull()
                ->columns(columns: 2)
                ->schema([
                    Toggle::make('import_prefs.preprocess')
                        ->label('Preprocess playlist')
                        ->columnSpanFull()
                        ->live()
                        ->inline(true)
                        ->default(false)
                        ->helperText('When enabled, the playlist will be preprocessed before importing. You can then select which groups you would like to import.'),

                    Toggle::make('import_prefs.use_regex')
                        ->label('Use regex for filtering')
                        ->columnSpan(2)
                        ->inline(true)
                        ->live()
                        ->default(false)
                        ->helperText('When enabled, groups will be included based on regex pattern match instead of prefix.')
                        ->hidden(fn (Get $get): bool => ! $get('import_prefs.preprocess') || ! $get('status')),

                    Fieldset::make('Live channel processing')
                        ->schema([
                            ModalTableSelect::make('import_prefs.selected_groups')
                                ->tableConfiguration(SourceGroupsTable::class)
                                ->label('Live groups to import')
                                ->columnSpan(1)
                                ->multiple()
                                ->helperText('NOTE: If the list is empty, sync the playlist and check again once complete.')
                                ->tableArguments(fn ($record): array => [
                                    'playlist_id' => $record?->id,
                                    'type' => 'live',
                                ])
                                ->selectAction(
                                    fn (Action $action) => $action
                                        ->label('Select live groups')
                                        ->modalHeading('Search live groups')
                                        ->modalSubmitActionLabel('Confirm selection')
                                        ->button(),
                                )
                                ->hintAction(
                                    Action::make('clear_groups')
                                        ->label('Clear all')
                                        ->icon('heroicon-o-x-mark')
                                        ->color('danger')
                                        ->action(function (Set $set) {
                                            $set('import_prefs.selected_groups', []);
                                        })
                                        ->requiresConfirmation()
                                        ->modalHeading('Clear selection')
                                        ->modalDescription('Are you sure you want to clear all selected live groups?')
                                        ->modalSubmitActionLabel('Clear')
                                )
                                ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)
                                ->getOptionLabelsUsing(function (array $values, $record): array {
                                    // Values are IDs, return id => name pairs
                                    // Need to filter out strings (names) that may exist from previous storage format
                                    $values = array_filter($values, fn ($value) => is_numeric($value));

                                    return SourceGroup::where('playlist_id', $record?->id)
                                        ->where('type', 'live')
                                        ->whereIn('id', $values)
                                        ->pluck('name', 'id')  // id => name
                                        ->toArray();
                                })
                                ->afterStateHydrated(function ($component, $state, $record) {
                                    // Convert names to IDs for display when loading existing data
                                    if (is_array($state) && ! empty($state)) {
                                        // Check if first item is a string (name) - need to convert to IDs
                                        if (is_string($state[0] ?? null)) {
                                            $ids = SourceGroup::where('playlist_id', $record?->id)
                                                ->where('type', 'live')
                                                ->whereIn('name', $state)
                                                ->pluck('id')
                                                ->unique()
                                                ->values()
                                                ->toArray();
                                            $component->state($ids);
                                        }
                                    }
                                })
                                ->dehydrateStateUsing(function ($state, $record) {
                                    // Convert IDs back to names for storage
                                    if (is_array($state) && ! empty($state)) {
                                        return SourceGroup::where('playlist_id', $record?->id)
                                            ->where('type', 'live')
                                            ->whereIn('id', $state)
                                            ->pluck('name')
                                            ->unique()
                                            ->values()
                                            ->toArray();
                                    }

                                    return $state;
                                }),
                            TagsInput::make('import_prefs.included_group_prefixes')
                                ->label(fn (Get $get) => ! $get('import_prefs.use_regex') ? 'Live group prefixes to import' : 'Regex patterns to import')
                                ->helperText('Press [tab] or [return] to add item.')
                                ->columnSpan(1)
                                ->suggestions([
                                    'US -',
                                    'UK -',
                                    'CA -',
                                    '^(US|UK|CA)',
                                    'Sports.*HD$',
                                    '\[.*\]',
                                ])
                                ->splitKeys(['Tab', 'Return']),
                        ])->hidden(fn (Get $get): bool => ! $get('import_prefs.preprocess') || ! $get('status')),

                    Fieldset::make('VOD processing')
                        ->schema([
                            ModalTableSelect::make('import_prefs.selected_vod_groups')
                                ->tableConfiguration(SourceGroupsTable::class)
                                ->label('VOD groups to import')
                                ->columnSpan(1)
                                ->multiple()
                                ->helperText('NOTE: If the list is empty, sync the playlist and check again once complete.')
                                ->tableArguments(fn ($record): array => [
                                    'playlist_id' => $record?->id,
                                    'type' => 'vod',
                                ])
                                ->selectAction(
                                    fn (Action $action) => $action
                                        ->label('Select VOD groups')
                                        ->modalHeading('Search VOD groups')
                                        ->modalSubmitActionLabel('Confirm selection')
                                        ->button(),
                                )
                                ->hintAction(
                                    Action::make('clear_groups')
                                        ->label('Clear all')
                                        ->icon('heroicon-o-x-mark')
                                        ->color('danger')
                                        ->action(function (Set $set) {
                                            $set('import_prefs.selected_vod_groups', []);
                                        })
                                        ->requiresConfirmation()
                                        ->modalHeading('Clear selection')
                                        ->modalDescription('Are you sure you want to clear all selected VOD groups?')
                                        ->modalSubmitActionLabel('Clear')
                                )
                                ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)
                                ->getOptionLabelsUsing(function (array $values, $record): array {
                                    // Values are IDs, return id => name pairs
                                    // Need to filter out strings (names) that may exist from previous storage format
                                    $values = array_filter($values, fn ($value) => is_numeric($value));

                                    return SourceGroup::where('playlist_id', $record?->id)
                                        ->where('type', 'vod')
                                        ->whereIn('id', $values)
                                        ->pluck('name', 'id')  // id => name
                                        ->toArray();
                                })
                                ->afterStateHydrated(function ($component, $state, $record) {
                                    // Convert names to IDs for display when loading existing data
                                    if (is_array($state) && ! empty($state)) {
                                        // Check if first item is a string (name) - need to convert to IDs
                                        if (is_string($state[0] ?? null)) {
                                            $ids = SourceGroup::where('playlist_id', $record?->id)
                                                ->where('type', 'vod')
                                                ->whereIn('name', $state)
                                                ->pluck('id')
                                                ->unique()
                                                ->values()
                                                ->toArray();
                                            $component->state($ids);
                                        }
                                    }
                                })
                                ->dehydrateStateUsing(function ($state, $record) {
                                    // Convert IDs back to names for storage
                                    if (is_array($state) && ! empty($state)) {
                                        return SourceGroup::where('playlist_id', $record?->id)
                                            ->where('type', 'vod')
                                            ->whereIn('id', $state)
                                            ->pluck('name')
                                            ->unique()
                                            ->values()
                                            ->toArray();
                                    }

                                    return $state;
                                }),
                            TagsInput::make('import_prefs.included_vod_group_prefixes')
                                ->label(fn (Get $get) => ! $get('import_prefs.use_regex') ? 'VOD group prefixes to import' : 'Regex patterns to import')
                                ->helperText('Press [tab] or [return] to add item.')
                                ->columnSpan(1)
                                ->suggestions([
                                    'US -',
                                    'UK -',
                                    'CA -',
                                    '^(US|UK|CA)',
                                    'Sports.*HD$',
                                    '\[.*\]',
                                ])
                                ->splitKeys(['Tab', 'Return']),
                        ])->hidden(fn (Get $get): bool => ! $get('import_prefs.preprocess') || ! $get('status')),

                    Fieldset::make('Series processing')
                        ->schema([
                            ModalTableSelect::make('import_prefs.selected_categories')
                                ->tableConfiguration(SourceCategoriesTable::class)
                                ->label('Categories to import')
                                ->columnSpan(1)
                                ->multiple()
                                ->helperText('NOTE: If the list is empty, sync the playlist and check again once complete.')
                                ->tableArguments(fn ($record): array => [
                                    'playlist_id' => $record?->id,
                                ])
                                ->selectAction(
                                    fn (Action $action) => $action
                                        ->label('Select categories')
                                        ->modalHeading('Search categories')
                                        ->modalSubmitActionLabel('Confirm selection')
                                        ->button(),
                                )
                                ->hintAction(
                                    Action::make('clear_categories')
                                        ->label('Clear all')
                                        ->icon('heroicon-o-x-mark')
                                        ->color('danger')
                                        ->action(function (Set $set) {
                                            $set('import_prefs.selected_categories', []);
                                        })
                                        ->requiresConfirmation()
                                        ->modalHeading('Clear selection')
                                        ->modalDescription('Are you sure you want to clear all selected categories?')
                                        ->modalSubmitActionLabel('Clear')
                                )
                                ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)
                                ->getOptionLabelsUsing(function (array $values, $record): array {
                                    // Values are IDs, return id => name pairs
                                    // Need to filter out strings (names) that may exist from previous storage format
                                    $values = array_filter($values, fn ($value) => is_numeric($value));

                                    return SourceCategory::where('playlist_id', $record?->id)
                                        ->whereIn('id', $values)
                                        ->pluck('name', 'id')  // id => name
                                        ->toArray();
                                })
                                ->afterStateHydrated(function ($component, $state, $record) {
                                    // Convert names to IDs for display when loading existing data
                                    if (is_array($state) && ! empty($state)) {
                                        // Check if first item is a string (name) - need to convert to IDs
                                        if (is_string($state[0] ?? null)) {
                                            $ids = SourceCategory::where('playlist_id', $record?->id)
                                                ->whereIn('name', $state)
                                                ->pluck('id')
                                                ->unique()
                                                ->values()
                                                ->toArray();
                                            $component->state($ids);
                                        }
                                    }
                                })
                                ->dehydrateStateUsing(function ($state, $record) {
                                    // Convert IDs back to names for storage
                                    if (is_array($state) && ! empty($state)) {
                                        return SourceCategory::where('playlist_id', $record?->id)
                                            ->whereIn('id', $state)
                                            ->pluck('name')
                                            ->unique()
                                            ->values()
                                            ->toArray();
                                    }

                                    return $state;
                                }),
                            TagsInput::make('import_prefs.included_category_prefixes')
                                ->label(fn (Get $get) => ! $get('import_prefs.use_regex') ? 'Category prefixes to import' : 'Regex patterns to import')
                                ->helperText('Press [tab] or [return] to add item.')
                                ->columnSpan(1)
                                ->suggestions([
                                    'US -',
                                    'UK -',
                                    'CA -',
                                    '^(US|UK|CA)',
                                    'Sports.*HD$',
                                    '\[.*\]',
                                ])
                                ->splitKeys(['Tab', 'Return']),
                        ])->hidden(fn (Get $get): bool => ! $get('import_prefs.preprocess') || ! $get('status')),

                    TagsInput::make('import_prefs.ignored_file_types')
                        ->label('Ignored file types')
                        ->helperText('Press [tab] or [return] to add item. You can ignore certain file types from being imported (.e.g.: ".mkv", ".mp4", etc.) This is useful for ignoring VOD or other unwanted content.')
                        ->columnSpanFull()
                        ->suggestions([
                            '.avi',
                            '.mkv',
                            '.mp4',
                        ])->splitKeys(['Tab', 'Return']),
                ]),

            Section::make('Auto-Enable Settings')
                ->description('Settings for automatically enabling new content')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Toggle::make('enable_channels')
                        ->label('Enable new channels')
                        ->columnSpanFull()
                        ->live()
                        ->inline(true)
                        ->default(false)
                        ->helperText('When enabled, newly added Live and VOD channels will be enabled by default.'),

                    Fieldset::make('Default options for new channels')
                        ->columnSpanFull()
                        ->schema([
                            Toggle::make('import_prefs.channel_default_mapping_enabled')
                                ->label('Enable EPG mapping by default')
                                ->inline(true)
                                ->default(true)
                                ->helperText('When enabled, newly added channels will have EPG mapping enabled by default on sync.'),
                            Toggle::make('import_prefs.channel_default_merge_enabled')
                                ->label('Enable merging by default')
                                ->inline(true)
                                ->default(true)
                                ->helperText('When enabled, newly added channels will have merging enabled by default on sync.'),
                        ])
                        ->hidden(fn (Get $get): bool => ! $get('enable_channels')),

                    Toggle::make('enable_series')
                        ->label('Enable new series')
                        ->columnSpanFull()
                        ->live()
                        ->inline(true)
                        ->default(false)
                        ->helperText('When enabled, newly added series will be enabled by default on sync.'),
                ]),

            Section::make('Series Processing')
                ->description('Processing options for playlist series')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Toggle::make('auto_fetch_series_metadata')
                        ->label('Fetch metadata & sync stream files')
                        ->inline(false)
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Recommend leaving this disabled, unless you are including Series in the M3U output, or syncing stream files. When accessing via the Xtream API, metadata will be automatically fetched'
                        )
                        ->default(false)
                        ->helperText('This will only fetch metadata/sync stream files for enabled series. When disabled, series metadata will be fetched automatically when access via the Xtream API for this playlist.'),
                    Toggle::make('include_series_in_m3u')
                        ->label('Include series in M3U output')
                        ->inline(false)
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Enable this to output your enabled series in the M3U file. It is recommended to enable the "Auto-fetch series metadata" option when enabled, otherwise you will need to manually fetch metadata for each series.'
                        )
                        ->default(false)
                        ->helperText('When enabled, series will be included in the M3U output. It is recommended to enable the "Auto-fetch series metadata" option when enabled.'),
                ])->hidden(fn (Get $get): bool => ! $get('xtream')),

            Section::make('VOD Processing')
                ->description('Processing options for playlist VOD')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(3)
                ->schema([
                    Toggle::make('auto_fetch_vod_metadata')
                        ->label('Fetch metadata')
                        ->inline(false)
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Enable this to automatically fetch metadata for enabled VOD channels. When accessing via the Xtream API, metadata will be automatically fetched.'
                        )
                        ->default(false)
                        ->helperText('This will only fetch metadata for enabled VOD channels.'),
                    Toggle::make('auto_sync_vod_stream_files')
                        ->label('Sync stream files')
                        ->inline(false)
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Enable this to automatically sync stream files for enabled VOD channels.'
                        )
                        ->default(false)
                        ->helperText('This will only sync stream files for enabled VOD channels.'),
                    Toggle::make('include_vod_in_m3u')
                        ->label('Include VOD in M3U output')
                        ->inline(false)
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Enable this to output your enabled VOD channels in the M3U file.'
                        )
                        ->default(false)
                        ->helperText('When enabled, VOD channels will be included in the M3U output.'),
                ])->hidden(fn (Get $get): bool => ! $get('xtream')),

            Section::make('Auto-Merge Processing')
                ->description('Automatically merge channels with the same stream ID into failover relationships after sync')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Toggle::make('auto_merge_channels_enabled')
                        ->label('Enable auto-merge after sync')
                        ->helperText('When enabled, channels with the same stream ID will be automatically merged with failover relationships after each sync.')
                        ->columnSpanFull()
                        ->live()
                        ->inline(false)
                        ->default(false),

                    Fieldset::make('Merge source configuration')
                        ->columnSpanFull()
                        ->columns(2)
                        ->hidden(fn (Get $get): bool => ! $get('auto_merge_channels_enabled'))
                        ->schema([
                            Select::make('auto_merge_config.preferred_playlist_id')
                                ->label('Preferred Playlist (optional)')
                                ->options(fn () => Playlist::where('user_id', auth()->id())->pluck('name', 'id'))
                                ->searchable()
                                ->columnSpanFull()
                                ->placeholder('Use this playlist only')
                                ->helperText('If set, channels from this playlist will be prioritized as master during merge. Leave empty to only merge within this playlist.'),
                            Repeater::make('auto_merge_config.failover_playlists')
                                ->label('Additional Failover Playlists (optional)')
                                ->helperText('Select additional playlists to include as failover sources. Leave empty to only merge channels within this playlist.')
                                ->reorderable()
                                ->reorderableWithButtons()
                                ->simple(
                                    Select::make('playlist_failover_id')
                                        ->label('Failover Playlist')
                                        ->options(fn () => Playlist::where('user_id', auth()->id())->pluck('name', 'id'))
                                        ->searchable()
                                        ->required()
                                )
                                ->distinct()
                                ->columns(1)
                                ->addActionLabel('Add failover playlist')
                                ->columnSpanFull()
                                ->defaultItems(0),
                        ]),

                    Fieldset::make('Merge behavior')
                        ->columnSpanFull()
                        ->columns(2)
                        ->hidden(fn (Get $get): bool => ! $get('auto_merge_channels_enabled'))
                        ->schema([
                            Toggle::make('auto_merge_config.check_resolution')
                                ->label('Prioritize by resolution')
                                ->inline(false)
                                ->default(false)
                                ->hintIcon(
                                    'heroicon-m-exclamation-triangle',
                                    tooltip: '⚠️ IPTV WARNING: This will analyze each stream to determine resolution, which may cause rate limiting or blocking with IPTV providers.'
                                )
                                ->helperText('When enabled, channels with higher resolution will be prioritized as master.'),
                            Toggle::make('auto_merge_config.force_complete_remerge')
                                ->label('Force complete re-merge')
                                ->inline(false)
                                ->default(false)
                                ->hintIcon(
                                    'heroicon-m-exclamation-triangle',
                                    tooltip: 'This will re-evaluate ALL existing failover relationships on each sync.'
                                )
                                ->helperText('When enabled, all channels will be re-evaluated during merge, including existing failover relationships.'),
                            Toggle::make('auto_merge_config.new_channels_only')
                                ->label('Merge only new channels')
                                ->inline(false)
                                ->hintIcon(
                                    'heroicon-m-exclamation-triangle',
                                    tooltip: 'When enabled, only newly synced channels will be merged. Disable to re-process all channels on each sync.'
                                )
                                ->default(true),
                            Toggle::make('auto_merge_deactivate_failover')
                                ->label('Deactivate failover channels')
                                ->inline(false)
                                ->hintIcon(
                                    'heroicon-m-exclamation-triangle',
                                    tooltip: 'When enabled, channels that become failovers will be automatically disabled.'
                                )
                                ->default(false),
                            Toggle::make('auto_merge_config.prefer_catchup_as_primary')
                                ->label('Prefer catch-up as primary')
                                ->inline(false)
                                ->hintIcon(
                                    'heroicon-m-exclamation-triangle',
                                    tooltip: 'When enabled, channels with catch-up enabled will be selected as the master when available.'
                                )
                                ->default(false),
                            Toggle::make('auto_merge_config.exclude_disabled_groups')
                                ->label('Exclude disabled groups from master selection')
                                ->inline(false)
                                ->hintIcon(
                                    'heroicon-m-exclamation-triangle',
                                    tooltip: 'Channels from disabled groups will never be selected as master, only as failovers.'
                                )
                                ->default(false),
                        ]),

                    Fieldset::make('Advanced Priority Scoring (optional)')
                        ->columnSpanFull()
                        ->columns(2)
                        ->hidden(fn (Get $get): bool => ! $get('auto_merge_channels_enabled'))
                        ->schema([
                            Select::make('auto_merge_config.prefer_codec')
                                ->label('Preferred Codec')
                                ->options([
                                    'hevc' => 'HEVC / H.265 (smaller file size)',
                                    'h264' => 'H.264 / AVC (better compatibility)',
                                ])
                                ->placeholder('No preference')
                                ->helperText('Prioritize channels with a specific video codec.'),
                            TagsInput::make('auto_merge_config.priority_keywords')
                                ->label('Priority Keywords')
                                ->placeholder('Add keyword...')
                                ->helperText('Channels with these keywords in their name will be prioritized (e.g., "RAW", "LOCAL", "HD").')
                                ->splitKeys(['Tab', 'Return']),
                            Repeater::make('auto_merge_config.group_priorities')
                                ->label('Group Priority Weights')
                                ->helperText('Assign priority weights to specific groups. Higher weight = more preferred as master. Leave empty for default behavior.')
                                ->columnSpanFull()
                                ->columns(2)
                                ->schema([
                                    Select::make('group_id')
                                        ->label('Group')
                                        ->options(fn () => Group::query()
                                            ->with(['playlist'])
                                            ->where(['user_id' => auth()->id(), 'type' => 'live'])
                                            ->get(['name', 'id', 'playlist_id'])
                                            ->transform(fn ($group) => [
                                                'id' => $group->id,
                                                'name' => $group->name.' ('.$group->playlist->name.')',
                                            ])->pluck('name', 'id')
                                        )
                                        ->searchable()
                                        ->required(),
                                    TextInput::make('weight')
                                        ->label('Weight')
                                        ->numeric()
                                        ->default(100)
                                        ->minValue(1)
                                        ->maxValue(1000)
                                        ->helperText('1-1000, higher = more preferred')
                                        ->required(),
                                ])
                                ->reorderable()
                                ->reorderableWithButtons()
                                ->addActionLabel('Add group priority')
                                ->defaultItems(0)
                                ->dehydrateStateUsing(function ($state) {
                                    // Store as an array of objects [{group_id: id|null, weight: weight}, ...]
                                    if (is_array($state) && ! empty($state)) {
                                        $formatted = [];
                                        foreach ($state as $item) {
                                            if (is_array($item) && isset($item['weight'])) {
                                                $groupId = $item['group_id'] ?? null;
                                                if (! $groupId) {
                                                    continue;
                                                }
                                                $formatted[] = [
                                                    'group_id' => $groupId,
                                                    'weight' => (int) $item['weight'],
                                                ];
                                            }
                                        }

                                        return $formatted;
                                    }

                                    return [];
                                }),
                            Repeater::make('auto_merge_config.priority_attributes')
                                ->label('Priority Order')
                                ->helperText('Drag to reorder priority attributes. First attribute has highest priority. Leave empty for default order.')
                                ->columnSpanFull()
                                ->simple(
                                    Select::make('attribute')
                                        ->options([
                                            'playlist_priority' => '📋 Playlist Priority (from failover list order)',
                                            'group_priority' => '📁 Group Priority (from weights above)',
                                            'catchup_support' => '⏪ Catch-up/Replay Support',
                                            'resolution' => '📺 Resolution (requires stream analysis)',
                                            'codec' => '🎬 Codec Preference (HEVC/H264)',
                                            'keyword_match' => '🏷️ Keyword Match',
                                        ])
                                        ->required()
                                )
                                ->reorderable()
                                ->reorderableWithDragAndDrop()
                                ->distinct()
                                ->addActionLabel('Add priority attribute')
                                ->defaultItems(0)
                                ->afterStateHydrated(function ($component, $state) {
                                    // Convert stored format to repeater format (Array of config attributes)
                                    if (is_array($state) && ! empty($state)) {
                                        $formatted = [];
                                        foreach ($state as $item) {
                                            if (is_string($item)) {
                                                $formatted[] = ['attribute' => $item];
                                            } elseif (is_array($item) && isset($item['attribute'])) {
                                                $formatted[] = $item;
                                            }
                                        }
                                        $component->state($formatted);
                                    }
                                }),
                        ]),
                ]),
        ];

        $outputFields = [
            Section::make('Playlist Output')
                ->description('Determines how the playlist is output')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Toggle::make('sync_logs_enabled')
                        ->label('Enable Sync Logs')
                        ->columnSpan('full')
                        ->inline(false)
                        ->live()
                        ->default(true)
                        ->disabled(fn (Get $get): bool => config('dev.disable_sync_logs', false))
                        ->hint(fn (Get $get): string => config('dev.disable_sync_logs', false) ? 'Sync logs disabled globally in settings' : '')
                        ->hintIcon(fn (Get $get): string => config('dev.disable_sync_logs', false) ? 'heroicon-m-lock-closed' : '')
                        ->helperText('Retain logs of playlist syncs. This is useful for debugging and tracking changes to the playlist. This can lead to increased sync time and storage usage depending on the size of the playlist.'),
                    Toggle::make('auto_sort')
                        ->label('Automatically assign sort number based on playlist order')
                        ->columnSpan(1)
                        ->inline(false)
                        ->default(true)
                        ->helperText('NOTE: You will need to re-sync your playlist, or wait for the next scheduled sync, if changing this. This will overwrite any existing channel sort order customization for this playlist.'),
                    Toggle::make('auto_channel_increment')
                        ->label('Auto channel number increment')
                        ->columnSpan(1)
                        ->inline(false)
                        ->live()
                        ->default(false)
                        ->helperText('If no channel number is set, output an automatically incrementing number.'),
                    TextInput::make('channel_start')
                        ->helperText('The starting channel number.')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->hidden(fn (Get $get): bool => ! $get('auto_channel_increment'))
                        ->required(),
                ]),
            Section::make('Streaming Output')
                ->description('Output processing options')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Toggle::make('enable_proxy')
                        ->label('Enable Stream Proxy')
                        ->hint(fn (Get $get): string => $get('enable_proxy') ? 'Proxied' : 'Not proxied')
                        ->hintIcon(fn (Get $get): string => ! $get('enable_proxy') ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
                        ->live()
                        ->helperText(fn (Get $get): string => $get('profiles_enabled')
                            ? 'Proxy mode is required when Provider Profiles are enabled.'
                            : 'When enabled, all streams will be proxied through the application. This allows for better compatibility with various clients and enables features such as stream limiting and output format selection.')
                        ->disabled(fn (Get $get): bool => (bool) $get('profiles_enabled'))
                        ->dehydrated()
                        ->inline(false)
                        ->default(false),
                    Toggle::make('enable_logo_proxy')
                        ->label('Enable Logo Proxy')
                        ->hint(fn (Get $get): string => $get('enable_logo_proxy') ? 'Proxied' : 'Not proxied')
                        ->hintIcon(fn (Get $get): string => ! $get('enable_logo_proxy') ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
                        ->live()
                        ->helperText('When enabled, channel logos will be proxied through the application. Logos will be cached for up to 30 days to reduce bandwidth and speed up loading times.')
                        ->inline(false)
                        ->default(false),
                    TextInput::make('streams')
                        ->label('HDHR/Xtream API Streams')
                        ->helperText('Number of streams available for HDHR and Xtream API service (if using).')
                        ->columnSpan(1)
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Enter 0 to use to use provider defined value. This value is also used when generating the Xtream API user info response.'
                        )
                        ->rules(['min:0'])
                        ->type('number')
                        ->default(0) // Default to 0 streams (unlimited)
                        ->required(),
                    TextInput::make('available_streams')
                        ->label('Available Streams')
                        ->hint('Set to 0 for unlimited streams.')
                        ->helperText('Number of streams available for this provider. If set to a value other than 0, will prevent any streams from starting if the number of active streams exceeds this value.')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->default(0) // Default to 0 streams (for unlimted)
                        ->required()
                        ->hidden(fn (Get $get): bool => ! $get('enable_proxy')),
                    TextInput::make('server_timezone')
                        ->label('Provider Timezone')
                        ->helperText('The portal/provider timezone (DST-aware). Needed to correctly use timeshift functionality when playlist proxy is enabled.')
                        ->placeholder('Etc/UTC')
                        ->hidden(fn (Get $get): bool => ! $get('enable_proxy')),
                    Toggle::make('strict_live_ts')
                        ->label('Enable Strict Live TS Handling')
                        ->hintAction(
                            Action::make('learn_more_strict_live_ts')
                                ->label('Learn More')
                                ->icon('heroicon-o-arrow-top-right-on-square')
                                ->iconPosition('after')
                                ->size('sm')
                                ->url('https://github.com/sparkison/m3u-proxy/blob/master/docs/STRICT_LIVE_TS_MODE.md')
                                ->openUrlInNewTab(true)
                        )
                        ->helperText('Enhanced stability for live MPEG-TS streams with PVR clients like Kodi and HDHomeRun (only used when not using transcoding profiles).')
                        ->inline(false)
                        ->default(false)
                        ->hidden(fn (Get $get): bool => ! $get('enable_proxy')),
                    Fieldset::make('Transcoding Settings (optional)')
                        ->columnSpanFull()
                        ->schema([
                            Select::make('stream_profile_id')
                                ->label('Live Streaming Profile')
                                ->relationship('streamProfile', 'name')
                                ->options(function () {
                                    return StreamProfile::where('user_id', auth()->id())->pluck('name', 'id');
                                })
                                ->searchable()
                                ->preload()
                                ->nullable()
                                ->helperText('Select a transcoding profile to apply to Live streams from this playlist. Leave empty for direct stream proxying.')
                                ->placeholder('Leave empty for direct stream proxying'),
                            Select::make('vod_stream_profile_id')
                                ->label('VOD and Series Streaming Profile')
                                ->relationship('vodStreamProfile', 'name')
                                ->options(function () {
                                    return StreamProfile::where('user_id', auth()->id())->pluck('name', 'id');
                                })
                                ->searchable()
                                ->preload()
                                ->nullable()
                                ->hintIcon(
                                    'heroicon-m-question-mark-circle',
                                    tooltip: 'Time seeking is not supported when transcoding VOD or Series streams. This is a limitation of live-transcoding. Leave empty to allow time seeking.'
                                )
                                ->helperText('Select a transcoding profile to apply to VOD and Series streams from this playlist. Leave empty for direct stream proxying.')
                                ->placeholder('Leave empty for direct stream proxying'),
                        ])->hidden(fn (Get $get): bool => ! $get('enable_proxy')),

                    Fieldset::make('HTTP Headers (optional)')
                        ->columnSpanFull()
                        ->schema([
                            Repeater::make('custom_headers')
                                ->hiddenLabel()
                                ->helperText('Custom headers to use when streaming via the proxy.')
                                ->columnSpanFull()
                                ->columns(2)
                                ->default([])
                                ->schema([
                                    TextInput::make('header')
                                        ->label('Header')
                                        ->required()
                                        ->placeholder('e.g. Authorization'),
                                    TextInput::make('value')
                                        ->label('Value')
                                        ->required()
                                        ->placeholder('e.g. Bearer abc123'),
                                ]),
                        ])->hidden(fn (Get $get): bool => ! $get('enable_proxy')),
                ]),
            Section::make('EPG Output')
                ->description('EPG output options')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Toggle::make('dummy_epg')
                        ->label('Enable dummy EPG')
                        ->columnSpan(1)
                        ->live()
                        ->inline(false)
                        ->default(false)
                        ->helperText('When enabled, dummy EPG data will be generated for the next 5 days. Thus, it is possible to assign channels for which no EPG data is available. As program information, the channel title and the set program length are used.'),
                    Select::make('id_channel_by')
                        ->label('Preferred TVG ID output')
                        ->helperText('How you would like to ID your channels in the EPG.')
                        ->options([
                            'stream_id' => 'TVG ID/Stream ID (default)',
                            'channel_id' => 'Channel ID (recommended for HDHR)',
                            'number' => 'Channel Number',
                            'name' => 'Channel Name',
                            'title' => 'Channel Title',
                        ])
                        ->required()
                        ->default('stream_id') // Default to stream_id
                        ->columnSpan(1),
                    Toggle::make('dummy_epg_category')
                        ->label('Channel group as category')
                        ->columnSpan(1)
                        ->inline(false)
                        ->default(false)
                        ->helperText('When enabled, the channel group will be assigned to the dummy EPG as a <category> tag.')
                        ->hidden(fn (Get $get): bool => ! $get('dummy_epg')),
                    TextInput::make('dummy_epg_length')
                        ->label('Dummy program length (in minutes)')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->default(120)
                        ->hidden(fn (Get $get): bool => ! $get('dummy_epg')),
                ]),
        ];

        $authFields = [
            Select::make('assigned_auth_ids')
                ->label('Assigned Auths')
                ->multiple()
                ->options(function ($record) {
                    $options = [];

                    // Get currently assigned auths for this playlist
                    if ($record) {
                        $currentAuths = $record->playlistAuths()->get();
                        foreach ($currentAuths as $auth) {
                            $options[$auth->id] = $auth->name.' (currently assigned)';
                        }
                    }

                    // Get unassigned auths
                    $unassignedAuths = PlaylistAuth::where('user_id', auth()->id())
                        ->whereDoesntHave('assignedPlaylist')
                        ->get();

                    foreach ($unassignedAuths as $auth) {
                        $options[$auth->id] = $auth->name;
                    }

                    return $options;
                })
                ->searchable()
                ->nullable()
                ->placeholder('Select auths or leave empty')
                ->default(function ($record) {
                    if ($record) {
                        return $record->playlistAuths()->pluck('playlist_auths.id')->toArray();
                    }

                    return [];
                })
                ->afterStateHydrated(function ($component, $state, $record) {
                    if ($record) {
                        $currentAuthIds = $record->playlistAuths()->pluck('playlist_auths.id')->toArray();
                        $component->state($currentAuthIds);
                    }
                })
                ->hintIcon(
                    'heroicon-m-question-mark-circle',
                    tooltip: 'Only unassigned auths are available. Each auth can only be assigned to one playlist at a time. You will also be able to access the Xtream API using any assigned auths.'
                )
                ->helperText('Simple authentication for playlist access.')
                ->afterStateUpdated(function ($state, $record) {
                    if (! $record) {
                        return;
                    }

                    $currentAuthIds = $record->playlistAuths()->pluck('playlist_auths.id')->toArray();
                    $newAuthIds = $state ? (is_array($state) ? $state : [$state]) : [];

                    // Find auths to remove (currently assigned but not in new selection)
                    $authsToRemove = array_diff($currentAuthIds, $newAuthIds);
                    foreach ($authsToRemove as $authId) {
                        $auth = PlaylistAuth::find($authId);
                        if ($auth) {
                            $auth->clearAssignment();
                        }
                    }

                    // Find auths to add (in new selection but not currently assigned)
                    $authsToAdd = array_diff($newAuthIds, $currentAuthIds);
                    foreach ($authsToAdd as $authId) {
                        $auth = PlaylistAuth::find($authId);
                        if ($auth) {
                            $auth->assignTo($record);
                        }
                    }
                })->dehydrated(false), // Don't save this field directly
        ];

        $sections = ['Name' => $nameFields];
        if ($includeAuth) {
            $sections['Auth'] = $authFields;
        }
        $sections['Type'] = $typeFields;
        $sections['Scheduling'] = $schedulingFields;
        $sections['Processing'] = $processingFields;
        $sections['Output'] = $outputFields;

        // Return sections and fields
        return $sections;
    }

    public static function getForm(): array
    {
        $tabs = [];
        foreach (collect(self::getFormSections(creating: false, includeAuth: true)) as $section => $fields) {
            if ($section === 'Name') {
                $section = 'General';
            }

            // Determine icon for section
            $icon = match (strtolower($section)) {
                'general' => 'heroicon-m-cog',
                'auth' => 'heroicon-m-key',
                'type' => 'heroicon-m-document-text',
                'scheduling' => 'heroicon-m-calendar',
                'processing' => 'heroicon-m-arrow-path',
                'output' => 'heroicon-m-arrow-up-right',
                default => null,
            };

            if (! in_array($section, ['Processing', 'Output'])) {
                // Wrap the fields in a section
                $fields = [
                    Section::make($section)
                        ->icon($icon)
                        ->schema($fields),
                ];
            }

            $tabs[] = Tab::make($section)
                ->icon($icon)
                ->schema($fields);
        }

        // Compose the form with tabs and sections
        return [
            Grid::make()
                ->columns(3)
                ->schema([
                    Tabs::make()
                        ->tabs($tabs)
                        ->columnSpanFull()
                        ->contained(false)
                        ->persistTabInQueryString(),
                ])->columnSpanFull(),

        ];
    }

    public static function getFormSteps(): array
    {
        $wizard = [];
        foreach (self::getFormSections(creating: true) as $step => $fields) {
            if (! in_array($step, ['Processing', 'Output'])) {
                // Wrap the fields in a section
                $fields = [
                    Section::make('')
                        ->schema($fields),
                ];
            }
            $wizard[] = Step::make($step)
                ->schema($fields);

            // Add auth after type step
            if ($step === 'Type') {
                $wizard[] = Step::make('Auth')
                    ->schema([
                        Section::make('Auth')
                            ->description('Add or create additional authentication methods for this playlist.')
                            ->schema([
                                ToggleButtons::make('auth_option')
                                    ->label('Authentication Option')
                                    ->options([
                                        'none' => 'No Authentication',
                                        'existing' => 'Use Existing Auth',
                                        'create' => 'Create New Auth',
                                    ])
                                    ->icons([
                                        'none' => 'heroicon-o-lock-open',
                                        'existing' => 'heroicon-o-key',
                                        'create' => 'heroicon-o-plus',
                                    ])
                                    ->default('none')
                                    ->live()
                                    ->inline()
                                    ->grouped()
                                    ->columnSpanFull(),

                                // Existing Auth Selection
                                Select::make('existing_auth_id')
                                    ->label('Select Existing Auth')
                                    ->helperText('Only unassigned auths are available. Each auth can only be assigned to one playlist at a time.')
                                    ->options(function () {
                                        return PlaylistAuth::where('user_id', auth()->id())
                                            ->whereDoesntHave('assignedPlaylist')
                                            ->pluck('name', 'id')
                                            ->toArray();
                                    })
                                    ->searchable()
                                    ->placeholder('Select an auth to assign')
                                    ->columnSpanFull()
                                    ->visible(fn (Get $get): bool => $get('auth_option') === 'existing'),

                                // Create New Auth Fields
                                Grid::make(2)
                                    ->schema([
                                        TextInput::make('auth_name')
                                            ->label('Auth Name')
                                            ->helperText('Internal name for this authentication.')
                                            ->placeholder('Auth for My Playlist')
                                            ->required()
                                            ->columnSpan(2),

                                        TextInput::make('auth_username')
                                            ->label('Username')
                                            ->helperText('Username for playlist access.')
                                            ->required()
                                            ->columnSpan(1),

                                        TextInput::make('auth_password')
                                            ->label('Password')
                                            ->password()
                                            ->revealable()
                                            ->helperText('Password for playlist access.')
                                            ->required()
                                            ->columnSpan(1),
                                    ])
                                    ->visible(fn (Get $get): bool => $get('auth_option') === 'create'),
                            ]),
                    ]);
            }
        }

        return $wizard;
    }

    /**
     * Create a toggle with consistent default configuration.
     */
    private static function makeToggle(string $name): Toggle
    {
        return Toggle::make($name)
            ->inline(false)
            ->default(false);
    }
}
