<?php

namespace App\Filament\Resources\PlaylistAliases;

use App\Facades\PlaylistFacade;
use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\CustomPlaylists\CustomPlaylistResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Tables\SourceCategoriesTable;
use App\Filament\Tables\SourceGroupsTable;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\SourceCategory;
use App\Models\SourceGroup;
use App\Models\StreamProfile;
use App\Rules\UrlIsAllowed;
use App\Services\DateFormatService;
use App\Services\EpgCacheService;
use App\Services\M3uProxyService;
use App\Services\XtreamService;
use App\Traits\HasUserFiltering;
use Carbon\Carbon;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Exception;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlaylistAliasResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;
    use HasUserFiltering;

    protected static ?string $model = PlaylistAlias::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('Playlist');
    }

    public static function getModelLabel(): string
    {
        return __('Playlist Alias');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Playlist Aliases');
    }

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
                    ->description(fn (PlaylistAlias $record): string => $record->description ?? '')
                    ->searchable(),
                Tables\Columns\TextColumn::make('alias_of')
                    ->getStateUsing(function ($record) {
                        $playlist = $record->getEffectivePlaylist();
                        if ($playlist) {
                            $type = $playlist instanceof Playlist ? 'Playlist' : 'Custom Playlist';

                            return $playlist->name.' ('.$type.')';
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
                    ->label(__('Provider Streams'))
                    ->getStateUsing(function ($record) {
                        try {
                            if ($record->xtream_status['user_info'] ?? false) {
                                return $record->xtream_status['user_info']['max_connections'];
                            }
                        } catch (Exception $e) {
                        }

                        return 'N/A';
                    })
                    ->description(fn ($record): string => 'Active: '.($record->xtream_status['user_info']['active_cons'] ?? 0))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('available_streams')
                    ->label(__('Proxy Streams'))
                    ->toggleable()
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? '∞' : (string) $state)
                    ->tooltip(__('Total streams available for this playlist (∞ indicates no limit)'))
                    ->description(function (PlaylistAlias $record): string {
                        // Cache active streams count for 5 seconds to reduce load
                        $count = Cache::remember(
                            "alias_active_streams_{$record->id}",
                            5,
                            fn () => M3uProxyService::getPlaylistActiveStreamsCount($record)
                        );

                        return "Active: {$count}";
                    }),
                Tables\Columns\TextColumn::make('live_count')
                    ->label(__('Live'))
                    ->description(fn (PlaylistAlias $record): string => "Enabled: {$record->enabled_live_channels()->count()}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vod_count')
                    ->label(__('VOD'))
                    ->description(fn (PlaylistAlias $record): string => "Enabled: {$record->enabled_vod_channels()->count()}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('series_count')
                    ->label(__('Series'))
                    ->description(fn (PlaylistAlias $record): string => "Enabled: {$record->enabled_series()->count()}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('enable_proxy')
                    ->label(__('Proxy'))
                    ->toggleable()
                    ->tooltip(__('Toggle proxy status'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('exp_date')
                    ->label(__('Expiry Date'))
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
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Actions\ActionGroup::make([
                    Action::make('Download M3U')
                        ->label(__('Download M3U'))
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn ($record) => PlaylistFacade::getUrls($record)['m3u'])
                        ->openUrlInNewTab(),
                    EpgCacheService::getEpgTableAction(),
                    Action::make('HDHomeRun URL')
                        ->label(__('HDHomeRun URL'))
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn ($record) => PlaylistFacade::getUrls($record)['hdhr'])
                        ->openUrlInNewTab(),
                    Action::make('Public URL')
                        ->label(__('Public URL'))
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn ($record) => '/playlist/v/'.$record->uuid)
                        ->openUrlInNewTab(),
                    Action::make('duplicate')
                        ->label(__('Duplicate'))
                        ->icon('heroicon-o-document-duplicate')
                        ->schema([
                            Forms\Components\TextInput::make('name')
                                ->label(__('Alias name'))
                                ->required()
                                ->default(fn ($record) => "{$record->name} (Copy)")
                                ->helperText(__('This will be the name of the duplicated alias.')),
                        ])
                        ->action(function ($record, $data): void {
                            $new = $record->replicate(except: [
                                'id', 'name', 'uuid',
                                'username', 'password', 'expires_at', 'xtream_status',
                            ]);
                            $new->name = $data['name'];
                            $new->uuid = Str::orderedUuid()->toString();
                            $new->save();

                            Notification::make()
                                ->success()
                                ->title(__('Alias duplicated'))
                                ->body(__("\"{$record->name}\" has been duplicated successfully."))
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-o-document-duplicate')
                        ->modalDescription(__('Duplicate this alias now?'))
                        ->modalSubmitActionLabel(__('Yes, duplicate now')),
                    Actions\DeleteAction::make(),
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
            // ...
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlaylistAliases::route('/'),
            // 'create' => Pages\CreatePlaylistAlias::route('/create'),
            // 'edit' => Pages\EditPlaylistAlias::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            Grid::make()
                ->columns(2)
                ->columnSpan('full')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->helperText(__('Enter the name of the alias. Internal use only.')),
                    Forms\Components\TextInput::make('user_agent')
                        ->helperText(__('User agent string to use for making requests.'))
                        ->default('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36')
                        ->required(),
                ]),

            Grid::make()
                ->columns(2)
                ->columnSpan('full')
                ->schema([
                    Forms\Components\Textarea::make('description')
                        ->helperText(__('Optional description for your reference.')),
                    Forms\Components\Toggle::make('edit_uuid')
                        ->label(__('View/Update Unique Identifier'))
                        ->inline(false)
                        ->live()
                        ->dehydrated(false)
                        ->default(false)
                        ->hiddenOn('create'),
                ]),
            Forms\Components\TextInput::make('uuid')
                ->label(__('Unique Identifier'))
                ->columnSpanFull()
                ->rules(fn ($record) => [
                    'required',
                    'min:3',
                    'max:36',
                    'regex:/^[a-zA-Z0-9_\-]+$/',
                    Rule::unique('playlists', 'uuid'), // Ensure UUID is unique across both playlists and aliases
                    Rule::unique('playlist_aliases', 'uuid')->ignore($record?->id),
                ])
                ->helperText(__('3–36 characters. Only letters, numbers, hyphens, and underscores are allowed.'))
                ->hintIcon(
                    'heroicon-m-exclamation-triangle',
                    tooltip: 'Be careful changing this value as this will change the URLs for the Playlist, its EPG, and HDHR.'
                )
                ->hidden(fn ($get): bool => ! $get('edit_uuid'))
                ->required(),

            Schemas\Components\Fieldset::make(__('Source Playlist'))
                ->schema([
                    Forms\Components\Select::make('playlist_id')
                        ->label(__('Standard Playlist'))
                        ->options(fn () => Playlist::where('user_id', auth()->id())->pluck('name', 'id'))
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
                        ->helperText(__('Select a standard Playlist (only one set of alternative credentials can be configured).'))
                        ->rules(['exists:playlists,id']),
                    Forms\Components\Select::make('custom_playlist_id')
                        ->label(__('Custom Playlist'))
                        ->options(fn () => CustomPlaylist::where('user_id', auth()->id())->pluck('name', 'id'))
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
                        ->helperText(__('Select a Custom Playlist (multiple provider credentials can be configured to match source providers).'))
                        ->dehydrated(true)
                        ->rules(['exists:custom_playlists,id']),
                ]),

            Schemas\Components\Fieldset::make(__('Provider Credentials'))
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Repeater::make('xtream_config')
                        ->label(__('Credentials'))
                        ->helperText(__('Provider credentials to use for this alias. At least one set of credentials is required.'))
                        ->columns(2)
                        ->defaultItems(0)
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'The credential(s) URL will be used to match the provider for credential swap. If a URL in the source playlist matches a credential URL, the credentials will be swapped with the ones defined here.'
                        )
                        ->maxItems(fn ($get) => $get('custom_playlist_id') ? null : 1)
                        ->minItems(1)
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => 'Provider: '.parse_url($state['url'] ?? '', PHP_URL_HOST))
                        ->schema([
                            Forms\Components\TextInput::make('url')
                                ->label(__('Xtream API URL'))
                                ->live()
                                ->helperText(text: 'Enter the full URL using <url>:<port> format - without trailing slash (/).')
                                ->prefixIcon('heroicon-m-globe-alt')
                                ->maxLength(4000)
                                ->url()
                                ->rules([new UrlIsAllowed])
                                ->columnSpan(2)
                                ->required()
                                ->suffixAction(
                                    Action::make('test_xtream_connection')
                                        ->label(__('Test connection'))
                                        ->icon('heroicon-m-signal')
                                        ->tooltip(__('Test Xtream API connection using the credentials below'))
                                        ->action(function (Get $get): void {
                                            $url = $get('url');
                                            $username = $get('username');
                                            $password = $get('password');

                                            if (empty($url) || empty($username) || empty($password)) {
                                                Notification::make()
                                                    ->title(__('Missing Credentials'))
                                                    ->body(__('Please fill in the Xtream API URL, username, and password before testing.'))
                                                    ->warning()
                                                    ->send();

                                                return;
                                            }

                                            try {
                                                $xtream = XtreamService::make(xtream_config: [
                                                    'url' => $url,
                                                    'username' => $username,
                                                    'password' => $password,
                                                ]);

                                                $result = $xtream->userInfo(timeout: 10);

                                                if (empty($result) || ! isset($result['user_info'])) {
                                                    Notification::make()
                                                        ->title(__('Connection Failed'))
                                                        ->body(__('No valid response from the Xtream API. Check your URL, username, and password.'))
                                                        ->danger()
                                                        ->send();

                                                    return;
                                                }

                                                $userInfo = $result['user_info'];
                                                $serverInfo = $result['server_info'] ?? [];

                                                $status = $userInfo['status'] ?? 'Unknown';
                                                $maxConnections = $userInfo['max_connections'] ?? '?';
                                                $activeCons = $userInfo['active_cons'] ?? '0';
                                                $expDate = ! empty($userInfo['exp_date'])
                                                    ? date('Y-m-d', (int) $userInfo['exp_date'])
                                                    : 'Never';
                                                $serverUrl = $serverInfo['url'] ?? $url;
                                                $serverTime = ! empty($serverInfo['time_now'])
                                                    ? $serverInfo['time_now']
                                                    : 'Unknown';

                                                $isActive = $status === 'Active';
                                                $statusIcon = $isActive ? '✅' : '⚠️';

                                                $details = "{$statusIcon} **Status:** {$status}\n\n";
                                                $details .= "**Max Connections:** {$maxConnections}\n\n";
                                                $details .= "**Active Connections:** {$activeCons}\n\n";
                                                $details .= "**Expires:** {$expDate}\n\n";
                                                $details .= "**Server:** {$serverUrl}\n\n";
                                                $details .= "**Server Time:** {$serverTime}";

                                                Notification::make()
                                                    ->title(__('Connection Successful'))
                                                    ->body(Str::markdown($details))
                                                    ->success()
                                                    ->persistent()
                                                    ->send();
                                            } catch (Exception $e) {
                                                Notification::make()
                                                    ->title(__('Connection Failed'))
                                                    ->body($e->getMessage())
                                                    ->danger()
                                                    ->send();
                                            }
                                        }),
                                ),
                            Forms\Components\TextInput::make('username')
                                ->label(__('Xtream API Username'))
                                ->required(),
                            Forms\Components\TextInput::make('password')
                                ->label(__('Xtream API Password'))
                                ->required()
                                ->password()
                                ->revealable(),
                        ])->columnSpanFull(),
                ]),

            Schemas\Components\Fieldset::make(__('Proxy Options'))
                ->columns(2)
                ->hidden(fn () => ! auth()->user()->canUseProxy())
                ->schema([
                    Forms\Components\Toggle::make('enable_proxy')
                        ->label(__('Enable Stream Proxy'))
                        ->hint(fn (Get $get): string => $get('enable_proxy') ? 'Proxied' : 'Not proxied')
                        ->hintIcon(fn (Get $get): string => ! $get('enable_proxy') ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
                        ->live()
                        ->helperText(__('When enabled, all streams will be proxied through the application. This allows for better compatibility with various clients and enables features such as stream limiting and output format selection.'))
                        ->inline(false)
                        ->default(false),
                    Forms\Components\Toggle::make('enable_logo_proxy')
                        ->label(__('Enable Logo Proxy'))
                        ->hint(fn (Get $get): string => $get('enable_logo_proxy') ? 'Proxied' : 'Not proxied')
                        ->hintIcon(fn (Get $get): string => ! $get('enable_logo_proxy') ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
                        ->live()
                        ->helperText(__('When enabled, channel logos will be proxied through the application. Logos will be cached for up to 30 days to reduce bandwidth and speed up loading times.'))
                        ->inline(false)
                        ->default(false),
                    Forms\Components\TextInput::make('streams')
                        ->label(__('HDHR/Xtream API Streams'))
                        ->helperText(__('Number of streams available for HDHR and Xtream API service (if using).'))
                        ->columnSpan(1)
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Enter 0 to use to use provider defined value. This value is also used when generating the Xtream API user info response.'
                        )
                        ->rules(['min:0'])
                        ->type('number')
                        ->default(0) // Default to 0 streams (unlimited)
                        ->required(),
                    Forms\Components\TextInput::make('server_timezone')
                        ->label(__('Provider Timezone'))
                        ->helperText(__('The portal/provider timezone (DST-aware). Needed to correctly use timeshift functionality.'))
                        ->placeholder(__('Etc/UTC'))
                        ->hintAction(
                            Action::make('get_provider_value')
                                ->label(__('Get from playlist status'))
                                ->icon('heroicon-o-clock')
                                ->action(action: function ($record, Set $set) {
                                    $value = $record->getEffectivePlaylist()?->xtream_status['server_info']['timezone'] ?? null;
                                    if ($value) {
                                        $set('server_timezone', $value);
                                        Notification::make()
                                            ->title(__('Current Provider Timezone'))
                                            ->body("Provider timezone retrieved from playlist status: {$value}. Press save changes to apply this value, or you can manually enter a different timezone if needed.")
                                            ->success()
                                            ->send();

                                        return;
                                    }
                                    Notification::make()
                                        ->title(__('Provider Timezone Not Found'))
                                        ->body(__('Provider timezone not found in playlist status. Make sure the playlist is connected and has synced at least once to retrieve this information.'))
                                        ->danger()
                                        ->send();
                                })->hidden(fn ($record) => $record?->playlist_id === null)
                        ),

                    Grid::make()
                        ->columns(1)
                        ->schema([
                            Forms\Components\TextInput::make('available_streams')
                                ->label(__('Available Streams'))
                                ->hint(__('Set to 0 for unlimited streams.'))
                                ->helperText(__('Number of streams available for this provider. If set to a value other than 0, will prevent any streams from starting if the number of active streams exceeds this value.'))
                                ->columnSpan(1)
                                ->rules(['min:1'])
                                ->type('number')
                                ->default(0) // Default to 0 streams (for unlimted)
                                ->required(),
                            Forms\Components\Toggle::make('strict_live_ts')
                                ->label(__('Enable Strict Live TS Handling'))
                                ->hintAction(
                                    Action::make('learn_more_strict_live_ts')
                                        ->label(__('Learn More'))
                                        ->icon('heroicon-o-arrow-top-right-on-square')
                                        ->iconPosition('after')
                                        ->size('sm')
                                        ->url('https://m3ue.sparkison.dev/docs/proxy/strict-live-ts')
                                        ->openUrlInNewTab(true)
                                )
                                ->helperText(__('Enhanced stability for live MPEG-TS streams with PVR clients like Kodi and HDHomeRun (only used when not using transcoding profiles).'))
                                ->inline(false)
                                ->default(false),
                            Forms\Components\Toggle::make('use_sticky_session')
                                ->label(__('Enable Sticky Session Handler'))
                                ->hintAction(
                                    Action::make('learn_more_sticky_session')
                                        ->label(__('Learn More'))
                                        ->icon('heroicon-o-arrow-top-right-on-square')
                                        ->iconPosition('after')
                                        ->size('sm')
                                        ->url('https://m3ue.sparkison.dev/docs/proxy/sticky-sessions')
                                        ->openUrlInNewTab(true)
                                )
                                ->helperText('')
                                ->inline(false)
                                ->default(false)
                                ->helperText(__('Lock clients to specific backend origins after redirects to prevent playback loops when load balancers bounce between origins. Disable if your provider doesn\\\'t use load balancing.')),
                        ])->hidden(fn (Get $get): bool => ! $get('enable_proxy')),

                    Schemas\Components\Fieldset::make(__('Transcoding Settings (optional)'))
                        ->columnSpanFull()
                        ->schema([
                            Forms\Components\Select::make('stream_profile_id')
                                ->label(__('Live Streaming Profile'))
                                ->relationship('streamProfile', 'name')
                                ->options(function () {
                                    return StreamProfile::where('user_id', auth()->id())->pluck('name', 'id');
                                })
                                ->searchable()
                                ->preload()
                                ->nullable()
                                ->helperText(__('Select a transcoding profile to apply to Live streams for external clients (VLC, Kodi, etc.). Does not affect the in-app player. Leave empty for direct stream proxying.'))
                                ->placeholder(__('Leave empty for direct stream proxying')),
                            Forms\Components\Select::make('vod_stream_profile_id')
                                ->label(__('VOD and Series Streaming Profile'))
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
                                ->helperText(__('Select a transcoding profile to apply to VOD and Series streams for external clients (VLC, Kodi, etc.). Does not affect the in-app player. Leave empty for direct stream proxying.'))
                                ->placeholder(__('Leave empty for direct stream proxying')),
                        ])->hidden(fn (Get $get): bool => ! $get('enable_proxy')),
                    Schemas\Components\Fieldset::make(__('HTTP Headers (optional)'))
                        ->columnSpanFull()
                        ->schema([
                            Forms\Components\Repeater::make('custom_headers')
                                ->hiddenLabel()
                                ->helperText(__('Add any custom headers to include when streaming a channel/episode.'))
                                ->columnSpanFull()
                                ->columns(2)
                                ->default([])
                                ->schema([
                                    Forms\Components\TextInput::make('header')
                                        ->label(__('Header'))
                                        ->required()
                                        ->placeholder(__('e.g. Authorization')),
                                    Forms\Components\TextInput::make('value')
                                        ->label(__('Value'))
                                        ->required()
                                        ->placeholder(__('e.g. Bearer abc123')),
                                ]),
                        ])->hidden(fn (Get $get): bool => ! $get('enable_proxy')),
                ])->columnSpanFull(),

            Schemas\Components\Fieldset::make(__('Auth (optional)'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('username')
                        ->label(__('Username'))
                        ->helperText(__('Optional: Set credentials to access this alias via Xtream API. Must be unique across all aliases and playlist auths.'))
                        ->rules(function ($record) {
                            return [
                                'nullable',
                                Rule::unique('playlist_aliases', 'username')->ignore($record?->id),
                                Rule::unique('playlist_auths', 'username'),
                            ];
                        })
                        ->columnSpan(1),
                    Forms\Components\TextInput::make('password')
                        ->label(__('Password'))
                        ->columnSpan(1)
                        ->password()
                        ->revealable(),
                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label(__('Expiration (date & time)'))
                        ->seconds(false)
                        ->native(false)
                        ->prefixIcon('heroicon-o-calendar')
                        ->helperText(__('If set, this alias credentials will stop working at that exact time.'))
                        ->nullable()
                        ->columnSpan(2),
                ]),

            Schemas\Components\Fieldset::make(__('Channel Filter (optional)'))
                ->columnSpanFull()
                ->hidden(fn (Get $get): bool => ! $get('playlist_id'))
                ->schema([
                    Schemas\Components\Fieldset::make(__('Live channel groups'))
                        ->schema([
                            ModalTableSelect::make('group_filter.selected_groups')
                                ->tableConfiguration(SourceGroupsTable::class)
                                ->label(__('Allowed live groups'))
                                ->columnSpanFull()
                                ->multiple()
                                ->helperText(__('Only live channels in these groups will be accessible. Leave empty to allow all live groups.'))
                                ->tableArguments(fn (Get $get): array => [
                                    'playlist_id' => (int) $get('playlist_id'),
                                    'type' => 'live',
                                ])
                                ->selectAction(
                                    fn (Action $action) => $action
                                        ->label(__('Select live groups'))
                                        ->modalHeading(__('Search live groups'))
                                        ->modalSubmitActionLabel(__('Confirm selection'))
                                        ->button(),
                                )
                                ->hintAction(
                                    Action::make('clear_live_groups')
                                        ->label(__('Clear all'))
                                        ->icon('heroicon-o-x-mark')
                                        ->color('danger')
                                        ->action(function (Set $set): void {
                                            $set('group_filter.selected_groups', []);
                                            $set('group_filter.live_group_order', []);
                                        })
                                        ->requiresConfirmation()
                                        ->modalHeading(__('Clear selection'))
                                        ->modalDescription(__('Are you sure you want to clear all selected live groups?'))
                                        ->modalSubmitActionLabel(__('Clear'))
                                )
                                ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name ?? $record->name)
                                ->getOptionLabelsUsing(function (array $values, $record, Get $get): array {
                                    $playlistId = $record?->playlist_id ?? (int) $get('playlist_id');

                                    return SourceGroup::displayLabelsForIds($playlistId, 'live', $values);
                                })
                                ->afterStateHydrated(function ($component, $state, $record): void {
                                    if (! is_array($state) || empty($state)) {
                                        return;
                                    }
                                    // Stored as names — convert to IDs for the select component
                                    if (is_string($state[0] ?? null)) {
                                        $ids = SourceGroup::where('playlist_id', $record?->playlist_id)
                                            ->where('type', 'live')
                                            ->whereIn('name', $state)
                                            ->pluck('id')
                                            ->unique()
                                            ->values()
                                            ->toArray();
                                        $component->state($ids);
                                    }
                                })
                                ->dehydrateStateUsing(function ($state, $record, Get $get) {
                                    if (! is_array($state) || empty($state)) {
                                        return $state;
                                    }
                                    $playlistId = $record?->playlist_id ?? (int) $get('playlist_id');

                                    return SourceGroup::where('playlist_id', $playlistId)
                                        ->where('type', 'live')
                                        ->whereIn('id', $state)
                                        ->pluck('name')
                                        ->unique()
                                        ->values()
                                        ->toArray();
                                })
                                ->live()
                                ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                    // Keep the custom sort list in sync with the current selection:
                                    // append newly-selected groups, drop deselected ones, preserve order.
                                    $playlistId = ((int) $get('playlist_id')) ?: null;
                                    $selectedNames = self::liveGroupSortSelectedNames(is_array($state) ? $state : [], $playlistId);
                                    $currentOrder = self::liveGroupSortNames($get('group_filter.live_group_order'));
                                    $set('group_filter.live_group_order', self::buildLiveGroupSortItems($currentOrder, $selectedNames, $playlistId));
                                }),

                            Forms\Components\Toggle::make('group_filter.sort_live_groups_custom')
                                ->label(__('Sort groups in custom order'))
                                ->helperText(__('When enabled, the selected live groups are delivered to the client in the custom order set below, instead of inheriting the source playlist order.'))
                                ->default(false)
                                ->columnSpanFull()
                                ->live()
                                ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                    if (! $state) {
                                        return;
                                    }
                                    // Seed the order list from the current selection the first time it's enabled.
                                    if (! empty(self::liveGroupSortNames($get('group_filter.live_group_order')))) {
                                        return;
                                    }
                                    $playlistId = ((int) $get('playlist_id')) ?: null;
                                    $selectedNames = self::liveGroupSortSelectedNames((array) $get('group_filter.selected_groups'), $playlistId);
                                    $set('group_filter.live_group_order', self::buildLiveGroupSortItems([], $selectedNames, $playlistId));
                                }),

                            Forms\Components\Repeater::make('group_filter.live_group_order')
                                ->hiddenLabel()
                                ->columnSpanFull()
                                ->visible(fn (Get $get): bool => (bool) $get('group_filter.sort_live_groups_custom'))
                                ->dehydrated(true)
                                ->table([
                                    Forms\Components\Repeater\TableColumn::make(__('Group Name')),
                                ])
                                ->schema([
                                    Forms\Components\TextInput::make('label')
                                        ->hiddenLabel()
                                        ->readOnly()
                                        ->dehydrated(false),
                                    Forms\Components\Hidden::make('name'),
                                ])
                                ->addable(false)
                                ->deletable(false)
                                ->reorderable(true)
                                ->compact()
                                ->helperText(__('Drag the groups into the order you want them delivered to the client.'))
                                ->afterStateHydrated(function (Forms\Components\Repeater $component, $state, $record): void {
                                    $playlistId = ((int) ($record?->playlist_id ?? 0)) ?: null;
                                    $orderedNames = self::liveGroupSortNames($state);
                                    $selectedNames = $record?->group_filter['selected_groups'] ?? [];
                                    if (! is_array($selectedNames)) {
                                        $selectedNames = [];
                                    }
                                    $component->state(self::buildLiveGroupSortItems($orderedNames, $selectedNames, $playlistId));
                                })
                                ->dehydrateStateUsing(fn ($state): array => self::liveGroupSortNames($state)),
                        ]),

                    Schemas\Components\Fieldset::make(__('VOD groups'))
                        ->schema([
                            ModalTableSelect::make('group_filter.selected_vod_groups')
                                ->tableConfiguration(SourceGroupsTable::class)
                                ->label(__('Allowed VOD groups'))
                                ->columnSpanFull()
                                ->multiple()
                                ->helperText(__('Only VOD channels in these groups will be accessible. Leave empty to allow all VOD groups.'))
                                ->tableArguments(fn (Get $get): array => [
                                    'playlist_id' => (int) $get('playlist_id'),
                                    'type' => 'vod',
                                ])
                                ->selectAction(
                                    fn (Action $action) => $action
                                        ->label(__('Select VOD groups'))
                                        ->modalHeading(__('Search VOD groups'))
                                        ->modalSubmitActionLabel(__('Confirm selection'))
                                        ->button(),
                                )
                                ->hintAction(
                                    Action::make('clear_vod_groups')
                                        ->label(__('Clear all'))
                                        ->icon('heroicon-o-x-mark')
                                        ->color('danger')
                                        ->action(fn (Set $set) => $set('group_filter.selected_vod_groups', []))
                                        ->requiresConfirmation()
                                        ->modalHeading(__('Clear selection'))
                                        ->modalDescription(__('Are you sure you want to clear all selected VOD groups?'))
                                        ->modalSubmitActionLabel(__('Clear'))
                                )
                                ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name ?? $record->name)
                                ->getOptionLabelsUsing(function (array $values, $record, Get $get): array {
                                    $playlistId = $record?->playlist_id ?? (int) $get('playlist_id');

                                    return SourceGroup::displayLabelsForIds($playlistId, 'vod', $values);
                                })
                                ->afterStateHydrated(function ($component, $state, $record): void {
                                    if (! is_array($state) || empty($state)) {
                                        return;
                                    }
                                    if (is_string($state[0] ?? null)) {
                                        $ids = SourceGroup::where('playlist_id', $record?->playlist_id)
                                            ->where('type', 'vod')
                                            ->whereIn('name', $state)
                                            ->pluck('id')
                                            ->unique()
                                            ->values()
                                            ->toArray();
                                        $component->state($ids);
                                    }
                                })
                                ->dehydrateStateUsing(function ($state, $record, Get $get) {
                                    if (! is_array($state) || empty($state)) {
                                        return $state;
                                    }
                                    $playlistId = $record?->playlist_id ?? (int) $get('playlist_id');

                                    return SourceGroup::where('playlist_id', $playlistId)
                                        ->where('type', 'vod')
                                        ->whereIn('id', $state)
                                        ->pluck('name')
                                        ->unique()
                                        ->values()
                                        ->toArray();
                                }),
                        ]),

                    Schemas\Components\Fieldset::make(__('Series categories'))
                        ->schema([
                            ModalTableSelect::make('group_filter.selected_categories')
                                ->tableConfiguration(SourceCategoriesTable::class)
                                ->label(__('Allowed series categories'))
                                ->columnSpanFull()
                                ->multiple()
                                ->helperText(__('Only series in these categories will be accessible. Leave empty to allow all series categories.'))
                                ->tableArguments(fn (Get $get): array => [
                                    'playlist_id' => (int) $get('playlist_id'),
                                ])
                                ->selectAction(
                                    fn (Action $action) => $action
                                        ->label(__('Select series categories'))
                                        ->modalHeading(__('Search series categories'))
                                        ->modalSubmitActionLabel(__('Confirm selection'))
                                        ->button(),
                                )
                                ->hintAction(
                                    Action::make('clear_categories')
                                        ->label(__('Clear all'))
                                        ->icon('heroicon-o-x-mark')
                                        ->color('danger')
                                        ->action(fn (Set $set) => $set('group_filter.selected_categories', []))
                                        ->requiresConfirmation()
                                        ->modalHeading(__('Clear selection'))
                                        ->modalDescription(__('Are you sure you want to clear all selected series categories?'))
                                        ->modalSubmitActionLabel(__('Clear'))
                                )
                                ->getOptionLabelFromRecordUsing(fn ($record) => $record->name)
                                ->getOptionLabelsUsing(function (array $values, $record, Get $get): array {
                                    $playlistId = $record?->playlist_id ?? (int) $get('playlist_id');
                                    if (! $playlistId) {
                                        return [];
                                    }
                                    $ids = array_filter($values, fn ($v) => is_numeric($v));

                                    return SourceCategory::where('playlist_id', $playlistId)
                                        ->whereIn('id', $ids)
                                        ->pluck('name', 'id')
                                        ->toArray();
                                })
                                ->afterStateHydrated(function ($component, $state, $record): void {
                                    if (! is_array($state) || empty($state)) {
                                        return;
                                    }
                                    if (is_string($state[0] ?? null)) {
                                        $ids = SourceCategory::where('playlist_id', $record?->playlist_id)
                                            ->whereIn('name', $state)
                                            ->pluck('id')
                                            ->unique()
                                            ->values()
                                            ->toArray();
                                        $component->state($ids);
                                    }
                                })
                                ->dehydrateStateUsing(function ($state, $record, Get $get) {
                                    if (! is_array($state) || empty($state)) {
                                        return $state;
                                    }
                                    $playlistId = $record?->playlist_id ?? (int) $get('playlist_id');

                                    return SourceCategory::where('playlist_id', $playlistId)
                                        ->whereIn('id', $state)
                                        ->pluck('name')
                                        ->unique()
                                        ->values()
                                        ->toArray();
                                }),
                        ]),
                ]),
        ];
    }

    /**
     * Extract the ordered internal group names from the custom-sort repeater state.
     *
     * Handles both the item shape ([uuid => ['name' => ..., 'label' => ...]]) used
     * while editing and the flat list of names persisted to the database.
     *
     * @return array<string>
     */
    public static function liveGroupSortNames(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        $names = [];
        foreach ($state as $item) {
            if (is_array($item)) {
                $name = $item['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            } elseif (is_string($item) && $item !== '') {
                $names[] = $item;
            }
        }

        return $names;
    }

    /**
     * Convert the live group selection state — SourceGroup IDs while editing, or
     * group names once persisted — into an ordered list of internal group names.
     *
     * @return array<string>
     */
    public static function liveGroupSortSelectedNames(mixed $selection, ?int $playlistId): array
    {
        if (! is_array($selection) || empty($selection)) {
            return [];
        }

        $ids = array_values(array_filter($selection, fn ($value): bool => is_numeric($value)));
        if (! empty($ids) && $playlistId) {
            $map = SourceGroup::where('playlist_id', $playlistId)
                ->where('type', 'live')
                ->whereIn('id', $ids)
                ->pluck('name', 'id')
                ->toArray();

            $names = [];
            foreach ($selection as $id) {
                if (isset($map[$id])) {
                    $names[] = $map[$id];
                }
            }

            return array_values(array_unique($names));
        }

        return array_values(array_unique(array_filter(
            $selection,
            fn ($value): bool => is_string($value) && $value !== '',
        )));
    }

    /**
     * Reconcile the saved order with the current selection and resolve display
     * (custom) names, returning repeater items keyed by a generated UUID.
     *
     * @param  array<string>  $orderedNames
     * @param  array<string>  $selectedNames
     * @return array<string, array{name: string, label: string}>
     */
    public static function buildLiveGroupSortItems(array $orderedNames, array $selectedNames, ?int $playlistId): array
    {
        $selectedSet = array_flip($selectedNames);

        // Keep previously-ordered groups that are still selected (preserving order)…
        $kept = array_values(array_filter($orderedNames, fn ($name): bool => isset($selectedSet[$name])));
        $keptSet = array_flip($kept);

        // …then append any newly-selected groups not already present.
        $appended = array_values(array_filter($selectedNames, fn ($name): bool => ! isset($keptSet[$name])));
        $finalNames = array_merge($kept, $appended);

        if (empty($finalNames)) {
            return [];
        }

        // Resolve display (custom) names in a single query to avoid N+1. Constrain
        // to live groups (this pane is live-only) so a VOD group sharing a
        // name_internal can't supply the label; soft-deleted rows are excluded by
        // the Group model's SoftDeletes global scope.
        $labels = [];
        if ($playlistId) {
            $labels = Group::where('playlist_id', $playlistId)
                ->where('type', 'live')
                ->whereIn('name_internal', $finalNames)
                ->pluck('name', 'name_internal')
                ->toArray();
        }

        $items = [];
        foreach ($finalNames as $name) {
            $items[(string) Str::uuid()] = [
                'name' => $name,
                'label' => $labels[$name] ?? $name,
            ];
        }

        return $items;
    }

    /**
     * Reset xtream_config to single-config format when switching to a standard Playlist.
     */
    protected static function initializeXtreamConfigForPlaylist(Set $set, ?int $playlistId): void
    {
        if (! $playlistId) {
            return;
        }

        $playlist = Playlist::find($playlistId);
        if (! $playlist) {
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
            ],
        ]);
    }

    /**
     * Initialize xtream_config for multi-provider format when switching to a Custom Playlist.
     */
    protected static function initializeXtreamConfigForCustomPlaylist(Set $set, ?int $customPlaylistId): void
    {
        if (! $customPlaylistId) {
            return;
        }

        $customPlaylist = CustomPlaylist::find($customPlaylistId);
        if (! $customPlaylist) {
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
