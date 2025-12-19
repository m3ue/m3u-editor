<?php

namespace App\Filament\Resources\PlaylistAliases;

use App\Facades\PlaylistFacade;
use App\Filament\Resources\CustomPlaylists\CustomPlaylistResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\SharedStream;
use App\Models\StreamProfile;
use App\Models\User;
use App\Services\EpgCacheService;
use App\Services\M3uProxyService;
use App\Services\ProxyService;
use Carbon\Carbon;
use Exception;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use App\Traits\HasUserFiltering;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;

class PlaylistAliasResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = PlaylistAlias::class;

    protected static ?string $recordTitleAttribute = 'name';
    protected static string | \UnitEnum | null $navigationGroup = 'Playlist';

    public static function getRecordTitle(?Model $record): string|null|Htmlable
    {
        return $record->name;
    }

    public static function getNavigationSort(): ?int
    {
        return 5;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['playlist', 'customPlaylist']);
            })
            ->deferLoading()
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->description(fn(PlaylistAlias $record): string => $record->description ?? '')
                    ->searchable(),
                Tables\Columns\TextColumn::make('alias_of')
                    ->getStateUsing(function ($record) {
                        $playlist = $record->getEffectivePlaylist();
                        if ($playlist) {
                            $type = $playlist instanceof Playlist ? 'Playlist' : 'Custom Playlist';
                            return $playlist->name . ' (' . $type . ')';
                        }
                        return 'N/A';
                    })
                    ->url(function ($record) {
                        $playlist = $record->getEffectivePlaylist();
                        if ($playlist instanceof Playlist) {
                            return PlaylistResource::getUrl('edit', ['record' => $playlist->id]);
                        } elseif ($playlist instanceof CustomPlaylist) {
                            return CustomPlaylistResource::getUrl('edit', ['record' => $playlist->id]);
                        }
                        return null;
                    }),
                // Tables\Columns\ToggleColumn::make('enabled'),
                Tables\Columns\TextColumn::make('user_info')
                    ->label('Provider Streams')
                    ->getStateUsing(function ($record) {
                        try {
                            if ($record->xtream_status['user_info'] ?? false) {
                                return $record->xtream_status['user_info']['max_connections'];
                            }
                        } catch (Exception $e) {
                        }
                        return 'N/A';
                    })
                    ->description(fn($record): string => "Active: " . ($record->xtream_status['user_info']['active_cons'] ?? 0))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('available_streams')
                    ->label('Proxy Streams')
                    ->toggleable()
                    ->formatStateUsing(fn(int $state): string => $state === 0 ? '∞' : (string)$state)
                    ->tooltip('Total streams available for this playlist (∞ indicates no limit)')
                    ->description(fn(PlaylistAlias $record): string => "Active: " . M3uProxyService::getPlaylistActiveStreamsCount($record)),
                Tables\Columns\TextColumn::make('live_count')
                    ->label('Live')
                    ->description(fn(PlaylistAlias $record): string => "Enabled: {$record->enabled_live_channels()->count()}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vod_count')
                    ->label('VOD')
                    ->description(fn(PlaylistAlias $record): string => "Enabled: {$record->enabled_vod_channels()->count()}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('series_count')
                    ->label('Series')
                    ->description(fn(PlaylistAlias $record): string => "Enabled: {$record->enabled_series()->count()}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('enable_proxy')
                    ->label('Proxy')
                    ->toggleable()
                    ->tooltip('Toggle proxy status')
                    ->sortable(),
                Tables\Columns\TextColumn::make('exp_date')
                    ->label('Expiry Date')
                    ->getStateUsing(function ($record) {
                        try {
                            if ($record->xtream_status['user_info']['exp_date'] ?? false) {
                                $expires = Carbon::createFromTimestamp($record->xtream_status['user_info']['exp_date']);
                                return $expires->toDayDateTimeString();
                            }
                        } catch (Exception $e) {
                        }
                        return 'N/A';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Actions\ActionGroup::make([
                    Actions\Action::make('Download M3U')
                        ->label('Download M3U')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn($record) => PlaylistFacade::getUrls($record)['m3u'])
                        ->openUrlInNewTab(),
                    EpgCacheService::getEpgTableAction(),
                    Actions\Action::make('HDHomeRun URL')
                        ->label('HDHomeRun URL')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn($record) => PlaylistFacade::getUrls($record)['hdhr'])
                        ->openUrlInNewTab(),
                    Actions\Action::make('Public URL')
                        ->label('Public URL')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn($record) => '/playlist/v/' . $record->uuid)
                        ->openUrlInNewTab(),
                    Actions\DeleteAction::make()
                ])->button()->hiddenLabel()->size('sm'),
                Actions\EditAction::make()
                    ->slideOver()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => Pages\ListPlaylistAliases::route('/'),
            //'create' => Pages\CreatePlaylistAlias::route('/create'),
            //'edit' => Pages\EditPlaylistAlias::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            // Forms\Components\Toggle::make('enabled')
            //     ->default(true)
            //     ->columnSpan('full'),
            Grid::make()
                ->columns(2)
                ->columnSpan('full')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->helperText('Enter the name of the alias. Internal use only.'),
                    Forms\Components\TextInput::make('user_agent')
                        ->helperText('User agent string to use for making requests.')
                        ->default('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36')
                        ->required(),
                ]),

            Grid::make()
                ->columns(2)
                ->columnSpan('full')
                ->schema([
                    Forms\Components\Textarea::make('description')
                        ->helperText('Optional description for your reference.'),
                    Forms\Components\Toggle::make('edit_uuid')
                        ->label('View/Update Unique Identifier')
                        ->inline(false)
                        ->live()
                        ->dehydrated(false)
                        ->default(false)
                        ->hiddenOn('create'),
                ]),
            Forms\Components\TextInput::make('uuid')
                ->label('Unique Identifier')
                ->columnSpanFull()
                ->rules(function ($record) {
                    return [
                        'required',
                        'min:3',
                        'max:36',
                        Rule::unique('playlists', 'uuid'), // Ensure UUID is unique across both playlists and aliases
                        Rule::unique('playlist_aliases', 'uuid')->ignore($record?->id),
                    ];
                })
                ->helperText('Value must be between 3 and 36 characters.')
                ->hintIcon(
                    'heroicon-m-exclamation-triangle',
                    tooltip: 'Be careful changing this value as this will change the URLs for the Playlist, its EPG, and HDHR.'
                )
                ->hidden(fn($get): bool => !$get('edit_uuid'))
                ->required(),

            Schemas\Components\Fieldset::make('Source Playlist')
                ->schema([
                    Forms\Components\Select::make('playlist_id')
                        ->label('Standard Playlist')
                        ->options(fn() => Playlist::where('user_id', auth()->id())->pluck('name', 'id'))
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Set $set, Get $get, $state) {
                            if ($state) {
                                $set('custom_playlist_id', null);
                                $set('group', null);
                                $set('group_id', null);
                                // Reset to single-provider config when switching to standard playlist
                                self::initializeXtreamConfigForPlaylist($set, $state);
                            }
                        })
                        ->requiredWithout('custom_playlist_id')
                        ->validationMessages([
                            'required_without' => 'Playlist is required if not using a custom playlist.',
                        ])
                        ->helperText('Select a standard Playlist (only one set of alternative credentials can be configured).')
                        ->rules(['exists:playlists,id']),
                    Forms\Components\Select::make('custom_playlist_id')
                        ->label('Custom Playlist')
                        ->options(fn() => CustomPlaylist::where('user_id', auth()->id())->pluck('name', 'id'))
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Set $set, Get $get, $state) {
                            if ($state) {
                                $set('playlist_id', null);
                                $set('group', null);
                                $set('group_id', null);
                                // Initialize multi-provider config when switching to custom playlist
                                self::initializeXtreamConfigForCustomPlaylist($set, $state);
                            }
                        })
                        ->requiredWithout('playlist_id')
                        ->validationMessages([
                            'required_without' => 'Custom Playlist is required if not using a standard playlist.',
                        ])
                        ->helperText('Select a Custom Playlist (multiple provider credentials can be configured to match source providers).')
                        ->dehydrated(true)
                        ->rules(['exists:custom_playlists,id'])
                ]),

            Schemas\Components\Fieldset::make('Provider Credentials')
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Repeater::make('xtream_config')
                        ->label('Credentials')
                        ->columns(2)
                        ->defaultItems(0)
                        ->maxItems(fn($get) => $get('custom_playlist_id') ? null : 1)
                        ->schema([
                            Forms\Components\TextInput::make('url')
                                ->label('Xtream API URL')
                                ->live()
                                ->hintIcon(
                                    'heroicon-m-question-mark-circle',
                                    tooltip: 'The URL will be used to match the provider for credential swap. If a URL in the source playlist matches this URL, the credentials will be swapped with the ones defined here.'
                                )
                                ->helperText(text: 'Enter the full URL using <url>:<port> format - without trailing slash (/).')
                                ->prefixIcon('heroicon-m-globe-alt')
                                ->maxLength(4000)
                                ->url()
                                ->columnSpan(2)
                                ->required(),
                            Forms\Components\TextInput::make('username')
                                ->label('Xtream API Username')
                                ->required(),
                            Forms\Components\TextInput::make('password')
                                ->label('Xtream API Password')
                                ->required()
                                ->password()
                                ->revealable(),
                        ])->columnSpanFull(),
                ]),

            Schemas\Components\Fieldset::make('Proxy Options')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('enable_proxy')
                        ->label('Enable Stream Proxy')
                        ->hint(fn(Get $get): string => $get('enable_proxy') ? 'Proxied' : 'Not proxied')
                        ->hintIcon(fn(Get $get): string => !$get('enable_proxy') ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
                        ->live()
                        ->helperText('When enabled, all streams will be proxied through the application. This allows for better compatibility with various clients and enables features such as stream limiting and output format selection.')
                        ->inline(false)
                        ->default(false),
                    Forms\Components\Toggle::make('enable_logo_proxy')
                        ->label('Enable Logo Proxy')
                        ->hint(fn(Get $get): string => $get('enable_logo_proxy') ? 'Proxied' : 'Not proxied')
                        ->hintIcon(fn(Get $get): string => !$get('enable_logo_proxy') ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
                        ->live()
                        ->helperText('When enabled, channel logos will be proxied through the application. Logos will be cached for up to 30 days to reduce bandwidth and speed up loading times.')
                        ->inline(false)
                        ->default(false),
                    Forms\Components\TextInput::make('streams')
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
                    Forms\Components\TextInput::make('available_streams')
                        ->label('Available Streams')
                        ->hint('Set to 0 for unlimited streams.')
                        ->helperText('Number of streams available for this provider. If set to a value other than 0, will prevent any streams from starting if the number of active streams exceeds this value.')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->default(0) // Default to 0 streams (for unlimted)
                        ->required()
                        ->hidden(fn(Get $get): bool => !$get('enable_proxy')),
                    Forms\Components\TextInput::make('server_timezone')
                        ->label('Provider Timezone')
                        ->helperText('The portal/provider timezone (DST-aware). Needed to correctly use timeshift functionality when playlist proxy is enabled.')
                        ->placeholder('Etc/UTC')
                        ->hidden(fn(Get $get): bool => !$get('enable_proxy')),
                    Forms\Components\Toggle::make('strict_live_ts')
                        ->label('Enable Strict Live TS Handling')
                        ->hintAction(
                            Actions\Action::make('learn_more_strict_live_ts')
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
                        ->hidden(fn(Get $get): bool => ! $get('enable_proxy')),
                    Schemas\Components\Fieldset::make('Transcoding Settings (optional)')
                        ->columnSpanFull()
                        ->schema([
                            Forms\Components\Select::make('stream_profile_id')
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
                            Forms\Components\Select::make('vod_stream_profile_id')
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
                        ])->hidden(fn(Get $get): bool => ! $get('enable_proxy')),
                    Schemas\Components\Fieldset::make('HTTP Headers (optional)')
                        ->columnSpanFull()
                        ->schema([
                            Forms\Components\Repeater::make('custom_headers')
                                ->hiddenLabel()
                                ->helperText('Add any custom headers to include when streaming a channel/episode.')
                                ->columnSpanFull()
                                ->columns(2)
                                ->default([])
                                ->schema([
                                    Forms\Components\TextInput::make('header')
                                        ->label('Header')
                                        ->required()
                                        ->placeholder('e.g. Authorization'),
                                    Forms\Components\TextInput::make('value')
                                        ->label('Value')
                                        ->required()
                                        ->placeholder('e.g. Bearer abc123'),
                                ])
                        ])->hidden(fn(Get $get): bool => !$get('enable_proxy')),
                ])->columnSpanFull(),

            Schemas\Components\Fieldset::make('Auth (optional)')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('username')
                        ->label('Username')
                        ->helperText('Optional: Set credentials to access this alias via Xtream API.')
                        ->columnSpan(1),
                    Forms\Components\TextInput::make('password')
                        ->label('Password')
                        ->columnSpan(1)
                        ->password()
                        ->revealable(),
                ]),
        ];
    }


    /**
     * Reset xtream_config to single-config format when switching to a standard Playlist.
     */
    protected static function initializeXtreamConfigForPlaylist(Set $set, ?int $playlistId): void
    {
        if (!$playlistId) {
            return;
        }

        $playlist = Playlist::find($playlistId);
        if (!$playlist) {
            $set('xtream_config', [[]]);
            return;
        }

        // Pre-fill with the playlist's existing xtream config URL if available
        $xtreamConfig = $playlist->xtream_config ?? [];
        $set('xtream_config', [
            [
                'url' => $xtreamConfig['url'] ?? '',
                'username' => '',
                'password' => '',
            ]
        ]);
    }

    /**
     * Initialize xtream_config for multi-provider format when switching to a Custom Playlist.
     */
    protected static function initializeXtreamConfigForCustomPlaylist(Set $set, ?int $customPlaylistId): void
    {
        if (!$customPlaylistId) {
            return;
        }

        $customPlaylist = CustomPlaylist::find($customPlaylistId);
        if (!$customPlaylist) {
            return;
        }

        // Get all source playlists and pre-populate URLs
        $sourcePlaylists = $customPlaylist->getSourcePlaylistsForAlias();

        if (empty($sourcePlaylists)) {
            $set('xtream_config', [[]]);
            return;
        }

        // Create a config entry for each source playlist with the URL pre-filled
        $configs = [];
        foreach ($sourcePlaylists as $source) {
            $configs[] = [
                'url' => $source['url'] ?? '',
                'username' => '',
                'password' => '',
            ];
        }

        $count = count($configs);
        $set('xtream_config', $configs);
    }
}
