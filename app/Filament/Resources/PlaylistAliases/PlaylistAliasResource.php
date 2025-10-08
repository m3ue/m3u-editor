<?php

namespace App\Filament\Resources\PlaylistAliases;

use App\Facades\PlaylistFacade;
use App\Filament\Resources\CustomPlaylists\CustomPlaylistResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\SharedStream;
use App\Models\User;
use App\Services\EpgCacheService;
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

class PlaylistAliasResource extends Resource
{
    protected static ?string $model = PlaylistAlias::class;

    protected static ?string $recordTitleAttribute = 'name';
    protected static string | \UnitEnum | null $navigationGroup = 'Playlist';

    public static function getRecordTitle(?Model $record): string|null|Htmlable
    {
        return $record->name;
    }

    public static function getNavigationSort(): ?int
    {
        return 6;
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
                    ->description(fn(PlaylistAlias $record): string => "Active: " . SharedStream::active()->where('stream_info->options->playlist_id', $record->uuid)->count()),
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
        $m3uProxyEnabled = ProxyService::m3uProxyEnabled();

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

            Schemas\Components\Fieldset::make('Alias of (choose one)')
                ->schema([
                    Forms\Components\Select::make('playlist_id')
                        ->label('Playlist')
                        ->options(fn() => Playlist::where('user_id', Auth::id())->pluck('name', 'id'))
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Set $set, $state) {
                            if ($state) {
                                $set('custom_playlist_id', null);
                                $set('group', null);
                                $set('group_id', null);
                            }
                        })
                        ->requiredWithout('custom_playlist_id')
                        ->validationMessages([
                            'required_without' => 'Playlist is required if not using a custom playlist.',
                        ])
                        ->rules(['exists:playlists,id']),
                    Forms\Components\Select::make('custom_playlist_id')
                        ->label('Custom Playlist')
                        ->options(fn() => CustomPlaylist::where('user_id', Auth::id())->pluck('name', 'id'))
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Set $set, $state) {
                            if ($state) {
                                $set('playlist_id', null);
                                $set('group', null);
                                $set('group_id', null);
                            }
                        })
                        ->requiredWithout('playlist_id')
                        ->validationMessages([
                            'required_without' => 'Custom Playlist is required if not using a standard playlist.',
                        ])
                        ->dehydrated(true)
                        ->rules(['exists:custom_playlists,id'])
                ]),

            Schemas\Components\Fieldset::make('Xtream API Config')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('xtream_config.url')
                        ->label('Xtream API URL')
                        ->live()
                        ->helperText('Enter the full url, using <url>:<port> format - without trailing slash (/).')
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->maxLength(255)
                        ->url()
                        ->columnSpan(2)
                        ->required(),
                    Forms\Components\TextInput::make('xtream_config.username')
                        ->label('Xtream API Username')
                        ->live()
                        ->required()
                        ->columnSpan(1),
                    Forms\Components\TextInput::make('xtream_config.password')
                        ->label('Xtream API Password')
                        ->live()
                        ->required()
                        ->columnSpan(1)
                        ->password()
                        ->revealable(),
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
                    Forms\Components\Select::make('proxy_options.output')
                        ->label('Proxy Output Format')
                        ->required()
                        ->options([
                            'ts' => 'MPEG-TS (.ts)',
                            'hls' => 'HLS (.m3u8)',
                        ])
                        ->default('ts')
                        ->hidden(fn(Get $get): bool => !$get('enable_proxy') || $m3uProxyEnabled),
                    Forms\Components\TextInput::make('server_timezone')
                        ->label('Provider Timezone')
                        ->helperText('The portal/provider timezone (DST-aware). Needed to correctly use timeshift functionality when playlist proxy is enabled.')
                        ->placeholder('Etc/UTC')
                        ->columnSpanFull()
                        ->hidden(fn(Get $get): bool => !$get('enable_proxy')),
                ])->columnSpanFull(),

            Schemas\Components\Fieldset::make('Auth (optional)')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('username')
                        ->label('Username')
                        ->columnSpan(1),
                    Forms\Components\TextInput::make('password')
                        ->label('Password')
                        ->columnSpan(1)
                        ->password()
                        ->revealable(),
                ]),
        ];
    }
}
