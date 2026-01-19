<?php

namespace App\Filament\Resources\Networks;

use App\Filament\Resources\Networks\Pages\CreateNetwork;
use App\Filament\Resources\Networks\Pages\EditNetwork;
use App\Filament\Resources\Networks\Pages\ListNetworks;
use App\Models\Network;
use App\Services\NetworkBroadcastService;
use App\Services\NetworkScheduleService;
use App\Traits\HasUserFiltering;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
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

class NetworkResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = Network::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Networks';

    protected static ?string $modelLabel = 'Network';

    protected static ?string $pluralModelLabel = 'Networks';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 110;

    public static function getDescription(): ?string
    {
        return 'Networks are your own personal TV station that contain your lineups (local media content). Create custom broadcast channels with scheduled programming from your media library.';
    }

    public static function getRecordTitle(?Model $record): string|null|Htmlable
    {
        return $record?->name;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getFormSections());
    }

    /**
     * Get form sections for edit view (non-wizard).
     */
    public static function getFormSections(): array
    {
        return [
            Tabs::make()
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Media Server')
                        ->icon('heroicon-o-server')
                        ->schema([
                            Section::make('Media Server')
                                ->compact()
                                ->icon('heroicon-o-server')
                                ->description('')
                                ->schema([
                                    Select::make('media_server_integration_id')
                                        ->label('Media Server')
                                        ->relationship('mediaServerIntegration', 'name')
                                        ->helperText('Networks pull VOD content from the linked media server.')
                                        ->required()
                                        ->native(false)
                                        ->disabled(),
                                ]),
                        ]),

                    Tab::make('Network Details')
                        ->icon('heroicon-o-tv')
                        ->schema([
                            Section::make('Network Details')
                                ->compact()
                                ->icon('heroicon-o-tv')
                                ->description('')
                                ->schema([
                                    Grid::make(2)->schema([
                                        TextInput::make('name')
                                            ->label('Network Name')
                                            ->placeholder('e.g., Movie Classics, 80s TV, Kids Zone')
                                            ->required()
                                            ->maxLength(255),

                                        TextInput::make('channel_number')
                                            ->label('Channel Number')
                                            ->numeric()
                                            ->placeholder('e.g., 100')
                                            ->helperText('Optional channel number for EPG')
                                            ->minValue(1),
                                    ]),

                                    Textarea::make('description')
                                        ->label('Description')
                                        ->placeholder('A channel dedicated to classic movies from the golden age of cinema')
                                        ->rows(2)
                                        ->maxLength(1000),

                                    TextInput::make('logo')
                                        ->label('Logo URL')
                                        ->placeholder('https://example.com/logo.png')
                                        ->url()
                                        ->maxLength(500),
                                ]),
                        ]),

                    Tab::make('Schedule Settings')
                        ->icon('heroicon-o-calendar')
                        ->schema([
                            Section::make('Schedule Settings')
                                ->compact()
                                ->icon('heroicon-o-calendar')
                                ->description('')
                                ->schema([
                                    Grid::make(2)->schema([
                                        Select::make('schedule_type')
                                            ->label('Schedule Type')
                                            ->options([
                                                'sequential' => 'Sequential (play in order)',
                                                'shuffle' => 'Shuffle (randomized)',
                                            ])
                                            ->default('sequential')
                                            ->helperText('How content is ordered in the schedule')
                                            ->native(false),

                                        Toggle::make('loop_content')
                                            ->label('Loop Content')
                                            ->inline(false)
                                            ->helperText('Restart from beginning when all content has played')
                                            ->default(true),
                                    ]),

                                    Select::make('network_playlist_id')
                                        ->label('Output Playlist')
                                        ->relationship(
                                            'networkPlaylist',
                                            'name',
                                            fn (Builder $query) => $query->where('is_network_playlist', true)
                                        )
                                        ->helperText('Assign this network to a playlist for M3U/EPG output. Create one if none exist.')
                                        ->createOptionForm([
                                            TextInput::make('name')
                                                ->label('Playlist Name')
                                                ->placeholder('e.g., My Networks')
                                                ->required(),
                                        ])
                                        ->createOptionUsing(function (array $data): int {
                                            $playlist = \App\Models\Playlist::create([
                                                'name' => $data['name'],
                                                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                                                'user_id' => Auth::id(),
                                                'is_network_playlist' => true,
                                            ]);

                                            return $playlist->id;
                                        })
                                        ->nullable()
                                        ->native(false),

                                    Toggle::make('enabled')
                                        ->label('Enabled')
                                        ->helperText('Disable to stop generating schedule without deleting')
                                        ->default(true)
                                        ->live()
                                        ->afterStateUpdated(function ($state, $record) {
                                            // If network is being disabled and is currently broadcasting, stop it
                                            if ($state === false && $record && $record->isBroadcasting()) {
                                                $service = app(NetworkBroadcastService::class);
                                                $service->stop($record);

                                                Notification::make()
                                                    ->warning()
                                                    ->title('Broadcast Stopped')
                                                    ->body("Network disabled - broadcast has been stopped for {$record->name}")
                                                    ->send();
                                            }
                                        }),
                                ]),
                        ]),

                    ...self::getOutputTabs(),
                    ...self::getBroadcastTabs(),
                ])->contained(false),
        ];
    }

    /**
     * Get wizard steps for create view.
     */
    public static function getFormSteps(): array
    {
        return [
            Step::make('Media Server')
                ->description('Select content source')
                ->icon('heroicon-o-server')
                ->schema([
                    Section::make('')
                        ->description('Networks pull their content from a media server integration. Select which media server to use.')
                        ->schema([
                            Select::make('media_server_integration_id')
                                ->label('Media Server')
                                ->relationship('mediaServerIntegration', 'name')
                                ->helperText('This network will use VOD content (movies/series) from this media server.')
                                ->required()
                                ->native(false)
                                ->preload()
                                ->placeholder('Select a media server...'),
                        ]),
                ]),

            Step::make('Network Info')
                ->description('Name and branding')
                ->icon('heroicon-o-tv')
                ->schema([
                    Section::make('')
                        ->description('Give your network a name and optional branding.')
                        ->schema([
                            Grid::make(2)->schema([
                                TextInput::make('name')
                                    ->label('Network Name')
                                    ->placeholder('e.g., Movie Classics, 80s TV, Kids Zone')
                                    ->required()
                                    ->maxLength(255),

                                TextInput::make('channel_number')
                                    ->label('Channel Number')
                                    ->numeric()
                                    ->placeholder('e.g., 100')
                                    ->helperText('Optional channel number for EPG ordering')
                                    ->minValue(1),
                            ]),

                            Textarea::make('description')
                                ->label('Description')
                                ->placeholder('A channel dedicated to classic movies from the golden age of cinema')
                                ->rows(2)
                                ->maxLength(1000),

                            TextInput::make('logo')
                                ->label('Logo URL')
                                ->placeholder('https://example.com/logo.png')
                                ->url()
                                ->maxLength(500),
                        ]),
                ]),

            Step::make('Schedule')
                ->description('Playback settings')
                ->icon('heroicon-o-calendar')
                ->schema([
                    Section::make('')
                        ->description('Configure how content is scheduled and where the network is published.')
                        ->schema([
                            Grid::make(2)->schema([
                                Select::make('schedule_type')
                                    ->label('Schedule Type')
                                    ->options([
                                        'sequential' => 'Sequential (play in order)',
                                        'shuffle' => 'Shuffle (randomized)',
                                    ])
                                    ->default('sequential')
                                    ->helperText('How content is ordered in the schedule')
                                    ->native(false),

                                Toggle::make('loop_content')
                                    ->label('Loop Content')
                                    ->helperText('Restart from beginning when all content has played')
                                    ->default(true),
                            ]),

                            Select::make('network_playlist_id')
                                ->label('Output Playlist')
                                ->relationship(
                                    'networkPlaylist',
                                    'name',
                                    fn (Builder $query) => $query->where('is_network_playlist', true)
                                )
                                ->helperText('Assign to a network playlist for M3U/EPG output.')
                                ->createOptionForm([
                                    TextInput::make('name')
                                        ->label('Playlist Name')
                                        ->placeholder('e.g., My Networks')
                                        ->required(),
                                ])
                                ->createOptionUsing(function (array $data): int {
                                    $playlist = \App\Models\Playlist::create([
                                        'name' => $data['name'],
                                        'uuid' => (string) \Illuminate\Support\Str::uuid(),
                                        'user_id' => Auth::id(),
                                        'is_network_playlist' => true,
                                    ]);

                                    return $playlist->id;
                                })
                                ->nullable()
                                ->native(false),

                            Toggle::make('enabled')
                                ->label('Enabled')
                                ->helperText('Enable this network for schedule generation')
                                ->default(true),
                        ]),
                ]),

            Step::make('Broadcast')
                ->description('Live streaming (optional)')
                ->icon('heroicon-o-signal')
                ->schema([
                    Section::make('')
                        ->description('Enable live broadcasting to stream content like a real TV channel. This is optional - you can enable it later.')
                        ->schema([
                            Toggle::make('broadcast_enabled')
                                ->label('Enable Broadcasting')
                                ->helperText('When enabled, this network will continuously broadcast content according to the schedule.')
                                ->default(false)
                                ->live(),

                            Grid::make(2)->schema([
                                Select::make('output_format')
                                    ->label('Output Format')
                                    ->options([
                                        'hls' => 'HLS (recommended)',
                                        'mpegts' => 'MPEG-TS',
                                    ])
                                    ->default('hls')
                                    ->native(false),

                                TextInput::make('segment_duration')
                                    ->label('Segment Duration')
                                    ->numeric()
                                    ->default(6)
                                    ->suffix('seconds')
                                    ->minValue(2)
                                    ->maxValue(30),
                            ])->visible(fn (Get $get): bool => $get('broadcast_enabled')),
                        ]),
                ]),
        ];
    }

    /**
     * Output sections (EPG/Stream URLs) - visible on edit only.
     */
    private static function getOutputTabs(): array
    {
        return [
            Tab::make('EPG Output')
                ->icon('heroicon-o-document-text')
                ->schema([
                    Section::make('EPG Output')
                        ->compact()
                        ->icon('heroicon-o-document-text')
                        ->description('')
                        ->schema([
                            TextInput::make('epg_url')
                                ->label('EPG URL')
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(fn ($record) => $record?->epg_url ?? 'Save network first')
                                ->hint(fn ($record) => $record?->epg_url ? view('components.copy-to-clipboard', ['text' => $record->epg_url, 'position' => 'left']) : null),

                            TextInput::make('schedule_info')
                                ->label('Schedule Info')
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(function ($record) {
                                    if (! $record) {
                                        return 'Not generated';
                                    }
                                    $count = $record->programmes()->count();
                                    $last = $record->programmes()->latest('end_time')->first();

                                    return $count > 0
                                        ? "{$count} programmes until ".($last?->end_time?->format('M j, Y H:i') ?? 'unknown')
                                        : 'No programmes - generate schedule first';
                                }),
                        ]),
                ])
                ->visibleOn('edit'),

            Tab::make('Stream Output')
                ->icon('heroicon-o-play')
                ->schema([
                    Section::make('Stream Output')
                        ->compact()
                        ->icon('heroicon-o-play')
                        ->description('')
                        ->schema([
                            TextInput::make('stream_url')
                                ->label('Stream URL')
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(fn ($record) => $record?->stream_url ?? 'Save network first')
                                ->hint(fn ($record) => $record?->stream_url ? view('components.copy-to-clipboard', ['text' => $record->stream_url, 'position' => 'left']) : null),

                            TextInput::make('m3u_url')
                                ->label('M3U Playlist URL')
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(fn ($record) => $record ? route('network.playlist', ['network' => $record->uuid]) : 'Save network first')
                                ->hint(fn ($record) => $record ? view('components.copy-to-clipboard', ['text' => route('network.playlist', ['network' => $record->uuid]), 'position' => 'left']) : null),
                        ]),
                ])
                ->visibleOn('edit'),
        ];
    }

    /**
     * Broadcast settings sections - visible on edit only.
     */
    private static function getBroadcastTabs(): array
    {
        return [
            Tab::make('Broadcast Settings')
                ->icon('heroicon-o-signal')
                ->schema([
                    Section::make('Broadcast Settings')
                        ->compact()
                        ->icon('heroicon-o-signal')
                        ->columns(2)
                        ->description('')
                        ->schema([
                            Toggle::make('broadcast_enabled')
                                ->label('Enable Broadcasting')
                                ->helperText('When enabled, this network will continuously broadcast content according to the schedule.')
                                ->default(false)
                                ->columnSpan(1)
                                ->live()
                                ->afterStateUpdated(function ($state, $record) {
                                    // If broadcast is being disabled and is currently running, stop it
                                    if ($state === false && $record && $record->isBroadcasting()) {
                                        $service = app(NetworkBroadcastService::class);
                                        $service->stop($record);

                                        Notification::make()
                                            ->warning()
                                            ->title('Broadcast Stopped')
                                            ->body("Broadcasting disabled - stream stopped for {$record->name}")
                                            ->send();
                                    }
                                }),

                            Toggle::make('broadcast_schedule_enabled')
                                ->label('Schedule Start Time')
                                ->helperText('Wait until a specific date/time before starting the broadcast.')
                                ->default(false)
                                ->columnSpan(1)
                                ->live()
                                ->visible(fn (Get $get): bool => $get('broadcast_enabled')),

                            DateTimePicker::make('broadcast_scheduled_start')
                                ->label('Scheduled Start Time')
                                ->helperText('Broadcast will wait until this time to start. Leave empty to start immediately.')
                                ->native(false)
                                ->seconds(true)
                                ->minDate(now())
                                ->columnSpanFull()
                                ->timezone(config('app.timezone'))
                                ->displayFormat('M j, Y H:i:s')
                                ->nullable()
                                ->visible(fn (Get $get): bool => $get('broadcast_enabled') && $get('broadcast_schedule_enabled'))
                                ->afterStateUpdated(function ($state, $record) {
                                    if ($state && $record) {
                                        $scheduledTime = \Carbon\Carbon::parse($state);
                                        if ($scheduledTime->isPast()) {
                                            Notification::make()
                                                ->warning()
                                                ->title('Invalid Time')
                                                ->body('Scheduled start time must be in the future.')
                                                ->send();
                                        }
                                    }
                                }),

                            Grid::make(2)->schema([
                                Select::make('output_format')
                                    ->label('Output Format')
                                    ->options([
                                        'hls' => 'HLS (recommended)',
                                        'mpegts' => 'MPEG-TS',
                                    ])
                                    ->default('hls')
                                    ->native(false)
                                    ->helperText('HLS provides better compatibility'),

                                TextInput::make('segment_duration')
                                    ->label('Segment Duration')
                                    ->numeric()
                                    ->default(6)
                                    ->suffix('seconds')
                                    ->minValue(2)
                                    ->maxValue(30)
                                    ->helperText('HLS segment length (6s recommended)'),
                            ])->visible(fn (Get $get): bool => $get('broadcast_enabled')),

                            Section::make('Transcoding')
                                ->compact()
                                ->description('Control how media is transcoded')
                                ->schema([
                                    Toggle::make('transcode_on_server')
                                        ->label('Transcode on Media Server')
                                        ->helperText('Let Jellyfin/Emby transcode with hardware acceleration.')
                                        ->default(true)
                                        ->live(),

                                    Grid::make(3)->schema([
                                        TextInput::make('video_bitrate')
                                            ->label('Video Bitrate')
                                            ->numeric()
                                            ->suffix('kbps')
                                            ->placeholder('Source')
                                            ->nullable(),

                                        TextInput::make('audio_bitrate')
                                            ->label('Audio Bitrate')
                                            ->numeric()
                                            ->suffix('kbps')
                                            ->default(192),

                                        Select::make('video_resolution')
                                            ->label('Resolution')
                                            ->options([
                                                null => 'Source (no scaling)',
                                                '3840x2160' => '4K',
                                                '1920x1080' => '1080p',
                                                '1280x720' => '720p',
                                                '854x480' => '480p',
                                            ])
                                            ->placeholder('Source')
                                            ->native(false)
                                            ->nullable(),
                                    ])->visible(fn (Get $get): bool => $get('transcode_on_server')),
                                ])
                                ->visible(fn (Get $get): bool => $get('broadcast_enabled')),

                            Section::make('Broadcast Status')
                                ->compact()
                                ->schema([
                                    TextInput::make('broadcast_status')
                                        ->label('Status')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->formatStateUsing(fn ($record) => $record?->isBroadcasting() ? '🟢 Broadcasting (PID: '.$record->broadcast_pid.')' : '⚪ Not broadcasting'),

                                    TextInput::make('broadcast_started_at_display')
                                        ->label('Started At')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->formatStateUsing(fn ($record) => $record?->broadcast_started_at?->format('M j, Y H:i:s') ?? '-'),

                                    TextInput::make('hls_url')
                                        ->label('HLS Playlist URL')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->formatStateUsing(fn ($record) => $record ? route('network.hls.playlist', ['network' => $record->uuid]) : 'Save network first')
                                        ->hint(fn ($record) => $record ? view('components.copy-to-clipboard', ['text' => route('network.hls.playlist', ['network' => $record->uuid]), 'position' => 'left']) : null),
                                ])
                                ->visible(fn (Get $get): bool => $get('broadcast_enabled')),
                        ]),
                ])
                ->visibleOn('edit'),
        ];
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
                    ->sortable(),

                ToggleColumn::make('enabled')
                    ->label('Enabled')
                    ->afterStateUpdated(function ($record, $state) {
                        // If network is being disabled and is currently broadcasting, stop it
                        if ($state === false && $record->isBroadcasting()) {
                            $service = app(NetworkBroadcastService::class);
                            $service->stop($record);

                            Notification::make()
                                ->warning()
                                ->title('Broadcast Stopped')
                                ->body("Network disabled - broadcast has been stopped for {$record->name}")
                                ->send();
                        }
                    }),

                TextColumn::make('channel_number')
                    ->label('Ch #')
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('schedule_type')
                    ->label('Schedule')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'shuffle' => 'warning',
                        'sequential' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('network_content_count')
                    ->label('Content')
                    ->counts('networkContent')
                    ->sortable(),

                TextColumn::make('schedule_generated_at')
                    ->label('Schedule Generated')
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->placeholder('Never'),

                TextColumn::make('mediaServerIntegration.name')
                    ->label('Media Server')
                    ->placeholder('None'),

                TextColumn::make('broadcast_status')
                    ->label('Broadcast')
                    ->badge()
                    ->getStateUsing(function (Network $record): string {
                        if (! $record->broadcast_enabled) {
                            return 'Disabled';
                        }
                        if ($record->broadcast_schedule_enabled && $record->broadcast_scheduled_start && now()->lt($record->broadcast_scheduled_start)) {
                            return 'Scheduled';
                        }
                        if ($record->isBroadcasting()) {
                            return 'Live';
                        }
                        if (! $record->broadcast_requested) {
                            return 'Stopped';
                        }

                        return 'Starting';
                    })
                    ->description(function (Network $record): ?string {
                        if ($record->broadcast_schedule_enabled && $record->broadcast_scheduled_start && now()->lt($record->broadcast_scheduled_start)) {
                            return 'Starts: '.$record->broadcast_scheduled_start->diffForHumans();
                        }

                        return null;
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Live' => 'success',
                        'Starting' => 'info',
                        'Scheduled' => 'warning',
                        'Stopped' => 'warning',
                        'Disabled' => 'gray',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'Live' => 'heroicon-o-signal',
                        'Starting' => 'heroicon-o-arrow-path',
                        'Scheduled' => 'heroicon-o-clock',
                        'Stopped' => 'heroicon-o-stop',
                        'Disabled' => 'heroicon-o-no-symbol',
                        default => 'heroicon-o-question-mark-circle',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('schedule_type')
                    ->options([
                        'sequential' => 'Sequential',
                        'shuffle' => 'Shuffle',
                    ]),
                Tables\Filters\TernaryFilter::make('enabled')
                    ->label('Enabled'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('generateSchedule')
                        ->label('Generate Schedule')
                        ->icon('heroicon-o-calendar')
                        ->requiresConfirmation()
                        ->modalHeading('Generate Schedule')
                        ->modalDescription('This will generate a 7-day programme schedule for this network. Existing future programmes will be replaced.')
                        ->disabled(fn (Network $record): bool => $record->network_playlist_id === null)
                        ->tooltip(fn (Network $record): ?string => $record->network_playlist_id === null ? 'Assign to a playlist first' : null)
                        ->action(function (Network $record) {
                            $service = app(NetworkScheduleService::class);
                            $service->generateSchedule($record);

                            Notification::make()
                                ->success()
                                ->title('Schedule Generated')
                                ->body("Generated programme schedule for {$record->name}")
                                ->send();
                        }),

                    Action::make('startBroadcast')
                        ->label('Start Broadcast')
                        ->icon('heroicon-o-play')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Start Broadcasting')
                        ->modalDescription(function (Network $record): string {
                            $base = 'Start continuous HLS broadcasting for this network. The stream will be available at the network\'s HLS URL.';

                            if ($record->broadcast_schedule_enabled && $record->broadcast_scheduled_start && now()->lt($record->broadcast_scheduled_start)) {
                                return $base."\n\nNote: Broadcast is scheduled to start at ".$record->broadcast_scheduled_start->format('M j, Y H:i:s').' ('.$record->broadcast_scheduled_start->diffForHumans().')';
                            }

                            return $base;
                        })
                        ->visible(fn (Network $record): bool => $record->broadcast_enabled && ! $record->isBroadcasting())
                        ->disabled(fn (Network $record): bool => $record->network_playlist_id === null || $record->programmes()->count() === 0)
                        ->tooltip(function (Network $record): ?string {
                            if ($record->network_playlist_id === null) {
                                return 'Assign to a playlist first';
                            }
                            if ($record->programmes()->count() === 0) {
                                return 'Generate schedule first';
                            }

                            return null;
                        })
                        ->action(function (Network $record) {
                            $service = app(NetworkBroadcastService::class);

                            // Mark as requested so worker will start it when time comes
                            $record->update(['broadcast_requested' => true]);

                            $result = $service->start($record);

                            // Refresh to get updated error message
                            $record->refresh();

                            if ($result) {
                                Notification::make()
                                    ->success()
                                    ->title('Broadcast Started')
                                    ->body("Broadcasting started for {$record->name}")
                                    ->send();
                            } elseif ($record->broadcast_schedule_enabled && $record->broadcast_scheduled_start && now()->lt($record->broadcast_scheduled_start)) {
                                Notification::make()
                                    ->info()
                                    ->title('Broadcast Scheduled')
                                    ->body("Broadcast will start at {$record->broadcast_scheduled_start->format('M j, Y H:i:s')} ({$record->broadcast_scheduled_start->diffForHumans()})")
                                    ->send();
                            } else {
                                $errorMsg = $record->broadcast_error ?? 'Could not start broadcast. Check that there is content scheduled.';

                                Notification::make()
                                    ->danger()
                                    ->title('Failed to Start')
                                    ->body($errorMsg)
                                    ->send();
                            }
                        }),

                    Action::make('stopBroadcast')
                        ->label('Stop Broadcast')
                        ->icon('heroicon-o-stop')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Stop Broadcasting')
                        ->modalDescription('Stop the current broadcast. Viewers will be disconnected.')
                        ->visible(fn (Network $record): bool => $record->isBroadcasting())
                        ->action(function (Network $record) {
                            $service = app(NetworkBroadcastService::class);
                            $service->stop($record);

                            Notification::make()
                                ->warning()
                                ->title('Broadcast Stopped')
                                ->body("Broadcasting stopped for {$record->name}")
                                ->send();
                        }),

                    Action::make('viewPlaylist')
                        ->label('View Playlist')
                        ->icon('heroicon-o-eye')
                        ->visible(fn (Network $record): bool => $record->network_playlist_id !== null)
                        ->url(fn (Network $record): string => \App\Filament\Resources\Playlists\PlaylistResource::getUrl('view', ['record' => $record->network_playlist_id])),

                    DeleteAction::make(),
                ])->button()->hiddenLabel()->size('sm'),
                EditAction::make()->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('generateAllSchedules')
                        ->label('Generate Schedules')
                        ->icon('heroicon-o-calendar')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $service = app(NetworkScheduleService::class);
                            foreach ($records as $record) {
                                $service->generateSchedule($record);
                            }

                            Notification::make()
                                ->success()
                                ->title('Schedules Generated')
                                ->body('Generated schedules for '.$records->count().' networks.')
                                ->send();
                        }),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\NetworkContentRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNetworks::route('/'),
            'create' => CreateNetwork::route('/create'),
            'edit' => EditNetwork::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', Auth::id());
    }
}
