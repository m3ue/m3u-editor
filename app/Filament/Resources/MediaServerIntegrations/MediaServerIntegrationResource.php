<?php

namespace App\Filament\Resources\MediaServerIntegrations;

use App\Filament\Resources\MediaServerIntegrations\Pages\CreateMediaServerIntegration;
use App\Filament\Resources\MediaServerIntegrations\Pages\EditMediaServerIntegration;
use App\Filament\Resources\MediaServerIntegrations\Pages\ListMediaServerIntegrations;
use App\Jobs\SyncMediaServer;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Services\MediaServerService;
use App\Traits\HasUserFiltering;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use RyanChandler\FilamentProgressColumn\ProgressColumn;

class MediaServerIntegrationResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = MediaServerIntegration::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Media Servers';

    protected static ?string $modelLabel = 'Media Server';

    protected static ?string $pluralModelLabel = 'Media Servers';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 100;

    public static function getRecordTitle(?Model $record): string|null|Htmlable
    {
        return $record?->name;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Server Configuration')
                    ->description('Configure your Emby or Jellyfin media server connection')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label('Display Name')
                                ->placeholder('e.g., Living Room Jellyfin')
                                ->required()
                                ->maxLength(255),

                            Select::make('type')
                                ->label('Server Type')
                                ->options([
                                    'emby' => 'Emby',
                                    'jellyfin' => 'Jellyfin',
                                    'plex' => 'Plex',
                                ])
                                ->required()
                                ->default('emby')
                                ->live()
                                ->native(false),
                        ]),

                        Grid::make(3)->schema([
                            TextInput::make('host')
                                ->label('Host / IP Address')
                                ->prefix(fn (callable $get) => $get('ssl') ? 'https://' : 'http://')
                                ->placeholder('192.168.1.100 or media.example.com')
                                ->required()
                                ->maxLength(255),

                            TextInput::make('port')
                                ->label('Port')
                                ->numeric()
                                ->default(fn (callable $get) => $get('type') === 'plex' ? 32400 : 8096)
                                ->required()
                                ->minValue(1)
                                ->maxValue(65535),

                            Toggle::make('ssl')
                                ->live()
                                ->inline(false)
                                ->label('Use HTTPS')
                                ->helperText('Enable if your server uses SSL/TLS')
                                ->default(false),
                        ]),

                        TextInput::make('api_key')
                            ->label('API Key')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrateStateUsing(fn ($state, $record) => filled($state) ? $state : $record?->api_key)
                            ->helperText(function (string $operation, callable $get) {
                                if ($operation === 'edit') {
                                    return 'Leave blank to keep existing API key';
                                }

                                return match ($get('type')) {
                                    'plex' => new HtmlString('See <a href="https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/" target="_blank">Plex Docs</a> for instructions on finding your token'),
                                    default => 'Generate an API key in your media server\'s dashboard under Settings → API Keys',
                                };
                            }),
                    ]),

                Section::make('Import Settings')
                    ->description('Control what content is synced from the media server')
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('import_movies')
                                ->label('Import Movies')
                                ->helperText('Sync movies as VOD channels')
                                ->default(true),

                            Toggle::make('import_series')
                                ->label('Import Series')
                                ->helperText('Sync TV series with episodes')
                                ->default(true),
                        ]),

                        Select::make('genre_handling')
                            ->label('Genre Handling')
                            ->options([
                                'primary' => 'Primary Genre Only (recommended)',
                                'all' => 'All Genres (creates duplicates)',
                            ])
                            ->default('primary')
                            ->helperText('How to handle content with multiple genres')
                            ->native(false),

                        Toggle::make('enabled')
                            ->label('Enabled')
                            ->helperText('Disable to pause syncing without deleting the integration')
                            ->default(true),
                    ]),

                Section::make('Sync Schedule')
                    ->description('Configure automatic sync schedule')
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('auto_sync')
                                ->label('Auto Sync')
                                ->helperText('Automatically sync content on schedule')
                                ->default(true),

                            Select::make('sync_interval')
                                ->label('Sync Interval')
                                ->options([
                                    '0 * * * *' => 'Every hour',
                                    '0 */3 * * *' => 'Every 3 hours',
                                    '0 */6 * * *' => 'Every 6 hours',
                                    '0 */12 * * *' => 'Every 12 hours',
                                    '0 0 * * *' => 'Once daily (midnight)',
                                    '0 0 * * 0' => 'Once weekly (Sunday)',
                                ])
                                ->default('0 */6 * * *')
                                ->native(false),
                        ]),
                    ]),

                Section::make('Sync Status')
                    ->description('Information about the last sync operation')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('last_synced_at')
                                ->label('Last Synced')
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(function ($state) {
                                    if (! $state) {
                                        return 'Never';
                                    }
                                    if (is_string($state)) {
                                        $state = \Carbon\Carbon::parse($state);
                                    }

                                    return $state->diffForHumans();
                                }),

                            TextInput::make('sync_stats_summary')
                                ->label('Last Sync Stats')
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(function ($record) {
                                    if (! $record || ! $record->sync_stats) {
                                        return 'No sync data';
                                    }
                                    $stats = $record->sync_stats;

                                    return sprintf(
                                        '%d movies, %d series, %d episodes',
                                        $stats['movies_synced'] ?? 0,
                                        $stats['series_synced'] ?? 0,
                                        $stats['episodes_synced'] ?? 0
                                    );
                                }),
                        ]),
                    ])
                    ->visibleOn('edit'),

                Section::make('Networks (Pseudo-Live Channels)')
                    ->description('Create live TV channels from your media server content')
                    ->schema([
                        TextInput::make('networks_playlist_url')
                            ->label('Networks Playlist URL')
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(fn ($record) => $record
                                ? route('networks.playlist', ['user' => $record->user_id])
                                : 'Save integration first'
                            )
                            ->copyable()
                            ->helperText('M3U playlist containing all your Networks as live channels'),

                        TextInput::make('networks_epg_url')
                            ->label('Networks EPG URL')
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(fn ($record) => $record
                                ? route('networks.epg', ['user' => $record->user_id])
                                : 'Save integration first'
                            )
                            ->copyable()
                            ->helperText('EPG data for your Networks'),

                        TextInput::make('networks_count')
                            ->label('Networks')
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(function ($record) {
                                if (! $record) {
                                    return '0 networks';
                                }
                                $count = $record->networks()->where('enabled', true)->count();

                                return $count.' '.str('network')->plural($count);
                            })
                            ->helperText('Create Networks in the Networks section to build pseudo-live channels'),
                    ])
                    ->visibleOn('edit'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->description(function ($record) {
                        if ($record->playlist_id) {
                            $playlist = Playlist::find($record->playlist_id);
                            if (! $playlist) {
                                return null;
                            }
                            $playlistLink = route('filament.admin.resources.playlists.edit', $record->playlist_id);

                            return new HtmlString('
                            <div class="flex items-center gap-1">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4">
                                    <path d="M12.75 4a.75.75 0 0 0-.75.75v10.5c0 .414.336.75.75.75h.5a.75.75 0 0 0 .75-.75V4.75a.75.75 0 0 0-.75-.75h-.5ZM17.75 4a.75.75 0 0 0-.75.75v10.5c0 .414.336.75.75.75h.5a.75.75 0 0 0 .75-.75V4.75a.75.75 0 0 0-.75-.75h-.5ZM3.288 4.819A1.5 1.5 0 0 0 1 6.095v7.81a1.5 1.5 0 0 0 2.288 1.277l6.323-3.906a1.5 1.5 0 0 0 0-2.552L3.288 4.819Z" />
                                </svg>
                                <a class="inline m-0 p-0 hover:underline" href="'.$playlistLink.'">Playlist: '.$playlist->name.'</a>
                            </div>');
                        }
                    })
                    ->sortable(),

                ToggleColumn::make('enabled')
                    ->label('Enabled'),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'emby' => 'success',
                        'jellyfin' => 'info',
                        'plex' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('host')
                    ->label('Server')
                    ->formatStateUsing(fn ($record): string => "{$record->host}:{$record->port}")
                    ->toggleable()
                    ->copyable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'processing' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                ProgressColumn::make('movie_progress')
                    ->label('Movie Sync')
                    ->poll(fn ($record) => $record->status !== 'completed' && $record->status !== 'failed' ? '3s' : null)
                    ->toggleable(),

                ProgressColumn::make('series_progress')
                    ->label('Series Sync')
                    ->poll(fn ($record) => $record->status !== 'completed' && $record->status !== 'failed' ? '3s' : null)
                    ->toggleable(),

                TextColumn::make('last_synced_at')
                    ->label('Last Synced')
                    ->dateTime()
                    ->since()
                    ->sortable(),

            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'emby' => 'Emby',
                        'jellyfin' => 'Jellyfin',
                        'plex' => 'Plex',
                    ]),
                Tables\Filters\TernaryFilter::make('enabled')
                    ->label('Enabled'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('sync')
                        ->disabled(fn ($record) => $record->status === 'processing')
                        ->label('Sync Now')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->modalHeading('Sync Media Server')
                        ->modalDescription('This will sync all content from the media server. For large libraries, this may take several minutes.')
                        ->action(function (MediaServerIntegration $record) {
                            // Update status to processing
                            $record->update([
                                'status' => 'processing',
                                'progress' => 0,
                                'movie_progress' => 0,
                                'series_progress' => 0,
                            ]);

                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new SyncMediaServer($record->id));

                            Notification::make()
                                ->success()
                                ->title('Sync Started')
                                ->body("Syncing content from {$record->name}. You'll be notified when complete.")
                                ->send();
                        }),
                    Action::make('test')
                        ->label('Test Connection')
                        ->icon('heroicon-o-signal')
                        ->action(function (MediaServerIntegration $record) {
                            $service = MediaServerService::make($record);
                            $result = $service->testConnection();

                            if ($result['success']) {
                                Notification::make()
                                    ->success()
                                    ->title('Connection Successful')
                                    ->body("Connected to {$result['server_name']} (v{$result['version']})")
                                    ->send();
                            } else {
                                Notification::make()
                                    ->danger()
                                    ->title('Connection Failed')
                                    ->body($result['message'])
                                    ->send();
                            }
                        }),

                    Action::make('viewPlaylist')
                        ->label('View Playlist')
                        ->icon('heroicon-o-queue-list')
                        ->url(fn ($record) => $record->playlist_id
                            ? route('filament.admin.resources.playlists.edit', $record->playlist_id)
                            : null
                        )
                        ->visible(fn ($record) => $record->playlist_id !== null),

                    Action::make('cleanupDuplicates')
                        ->label('Cleanup Duplicates')
                        ->icon('heroicon-o-trash')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Cleanup Duplicate Series')
                        ->modalDescription('This will find and merge duplicate series entries that were created due to sync format changes. Duplicate series without episodes will be removed, and their seasons will be merged into the series that has episodes.')
                        ->action(function (MediaServerIntegration $record) {
                            $result = static::cleanupDuplicateSeries($record);

                            if ($result['duplicates'] === 0) {
                                Notification::make()
                                    ->info()
                                    ->title('No Duplicates Found')
                                    ->body('No duplicate series were found for this media server.')
                                    ->send();
                            } else {
                                Notification::make()
                                    ->success()
                                    ->title('Cleanup Complete')
                                    ->body("Merged {$result['duplicates']} duplicate series and deleted {$result['deleted']} orphaned entries.")
                                    ->send();
                            }
                        })
                        ->visible(fn ($record) => $record->playlist_id !== null),

                    DeleteAction::make()
                        ->before(function (MediaServerIntegration $record) {
                            // Optionally delete the associated playlist
                            // For now, we leave the playlist intact (sidecar philosophy)
                        }),
                ])->button()->hiddenLabel()->size('sm'),
                EditAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('syncAll')
                        ->label('Sync Selected')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new SyncMediaServer($record->id));
                            }

                            Notification::make()
                                ->success()
                                ->title('Sync Started')
                                ->body('Syncing '.$records->count().' media servers.')
                                ->send();
                        }),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMediaServerIntegrations::route('/'),
            'create' => CreateMediaServerIntegration::route('/create'),
            'edit' => EditMediaServerIntegration::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', Auth::id());
    }

    /**
     * Clean up duplicate series created by sync format changes.
     *
     * When the sync switched from storing raw media_server_id to crc32() hashed values,
     * it created duplicate series entries. This method finds duplicates (same metadata.media_server_id)
     * and merges them, keeping the one with the correct CRC format.
     */
    protected static function cleanupDuplicateSeries(MediaServerIntegration $integration): array
    {
        $playlistId = $integration->playlist_id;
        $stats = ['duplicates' => 0, 'deleted' => 0, 'merged_episodes' => 0, 'merged_seasons' => 0];

        // Group series by media_server_id
        $seriesByMediaServerId = [];
        Series::where('playlist_id', $playlistId)
            ->whereNotNull('metadata->media_server_id')
            ->each(function ($series) use (&$seriesByMediaServerId, $integration) {
                $mediaServerId = $series->metadata['media_server_id'] ?? null;
                if ($mediaServerId) {
                    $expectedCrc = crc32("media-server-{$integration->id}-{$mediaServerId}");
                    $hasCrcFormat = $series->source_series_id == $expectedCrc;

                    $seriesByMediaServerId[$mediaServerId][] = [
                        'series' => $series,
                        'has_crc_format' => $hasCrcFormat,
                        'episode_count' => $series->episodes()->count(),
                        'season_count' => $series->seasons()->count(),
                    ];
                }
            });

        foreach ($seriesByMediaServerId as $mediaServerId => $entries) {
            if (count($entries) < 2) {
                continue;
            }

            $stats['duplicates']++;

            // Find the "keeper" (prefer CRC format, then most episodes)
            $keeper = null;
            $toDelete = [];

            foreach ($entries as $entry) {
                if ($entry['has_crc_format'] && (! $keeper || $entry['episode_count'] > $keeper['episode_count'])) {
                    if ($keeper) {
                        $toDelete[] = $keeper;
                    }
                    $keeper = $entry;
                } else {
                    $toDelete[] = $entry;
                }
            }

            // If no CRC format series exists, keep the one with most episodes
            if (! $keeper) {
                usort($entries, fn ($a, $b) => $b['episode_count'] <=> $a['episode_count']);
                $keeper = array_shift($entries);
                $toDelete = $entries;
            }

            $keeperSeries = $keeper['series'];

            foreach ($toDelete as $entry) {
                $oldSeries = $entry['series'];

                DB::transaction(function () use ($oldSeries, $keeperSeries, &$stats) {
                    // Map old seasons to keeper seasons by season_number
                    $seasonMap = [];
                    $keeperSeasons = $keeperSeries->seasons()->get()->keyBy('season_number');

                    foreach ($oldSeries->seasons as $oldSeason) {
                        $keeperSeason = $keeperSeasons->get($oldSeason->season_number);
                        if ($keeperSeason) {
                            $seasonMap[$oldSeason->id] = $keeperSeason->id;
                        } else {
                            // Move the season to the keeper series
                            $oldSeason->update(['series_id' => $keeperSeries->id]);
                            $seasonMap[$oldSeason->id] = $oldSeason->id;
                            $stats['merged_seasons']++;
                        }
                    }

                    // Move episodes to keeper series
                    foreach ($oldSeries->episodes as $episode) {
                        $newSeasonId = $seasonMap[$episode->season_id] ?? null;
                        $episode->update([
                            'series_id' => $keeperSeries->id,
                            'season_id' => $newSeasonId ?? $episode->season_id,
                        ]);
                        $stats['merged_episodes']++;
                    }

                    // Delete old seasons that were mapped (not moved)
                    Season::where('series_id', $oldSeries->id)->delete();

                    // Delete the old series
                    $oldSeries->delete();
                });

                $stats['deleted']++;
            }
        }

        return $stats;
    }
}
