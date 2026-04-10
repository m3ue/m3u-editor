<?php

namespace App\Filament\Resources\Networks;

use App\Enums\TranscodeMode;
use App\Filament\Actions\AssetPickerAction;
use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\Networks\Pages\CreateNetwork;
use App\Filament\Resources\Networks\Pages\EditNetwork;
use App\Filament\Resources\Networks\Pages\ListNetworks;
use App\Filament\Resources\Networks\Pages\ManualScheduleBuilder;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Models\Network;
use App\Models\Playlist;
use App\Services\LogoCacheService;
use App\Services\NetworkBroadcastService;
use App\Services\NetworkScheduleService;
use App\Traits\HasUserFiltering;
use Carbon\Carbon;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
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
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class NetworkResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;
    use HasUserFiltering;

    protected static ?string $model = Network::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Networks');
    }

    public static function getModelLabel(): string
    {
        return __('Network');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Networks');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Integrations');
    }

    protected static ?int $navigationSort = 110;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            EditNetwork::class,
            ManualScheduleBuilder::class,
        ]);
    }

    /**
     * Check if the user can access this page.
     * Only users with the "integrations" permission can access this page.
     */
    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseIntegrations();
    }

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
                    Tab::make(__('Media Server'))
                        ->icon('heroicon-o-server')
                        ->schema([
                            Section::make(__('Media Server'))
                                ->compact()
                                ->icon('heroicon-o-server')
                                ->description('')
                                ->schema([
                                    Select::make('media_server_integration_id')
                                        ->label(__('Media Server'))
                                        ->relationship('mediaServerIntegration', 'name')
                                        ->helperText(__('Networks pull VOD content from the linked media server.'))
                                        ->required()
                                        ->native(false)
                                        ->disabled(),
                                ]),
                        ]),

                    Tab::make(__('Network Details'))
                        ->icon('heroicon-o-tv')
                        ->schema([
                            Section::make(__('Network Details'))
                                ->compact()
                                ->icon('heroicon-o-tv')
                                ->description('')
                                ->schema([
                                    Grid::make(2)->schema([
                                        TextInput::make('name')
                                            ->label(__('Network Name'))
                                            ->placeholder(__('e.g., Movie Classics, 80s TV, Kids Zone'))
                                            ->required()
                                            ->maxLength(255),

                                        TextInput::make('channel_number')
                                            ->label(__('Channel Number'))
                                            ->numeric()
                                            ->placeholder(__('e.g., 100'))
                                            ->helperText(__('Optional channel number for EPG'))
                                            ->minValue(1),
                                    ]),

                                    Textarea::make('description')
                                        ->label(__('Description'))
                                        ->placeholder(__('A channel dedicated to classic movies from the golden age of cinema'))
                                        ->rows(2)
                                        ->maxLength(1000),

                                    TextInput::make('logo')
                                        ->label(__('Logo URL'))
                                        ->placeholder(__('https://example.com/logo.png'))
                                        ->url()
                                        ->maxLength(500)
                                        ->suffixActions([
                                            AssetPickerAction::upload('logo'),
                                            AssetPickerAction::browse('logo'),
                                        ]),

                                    TextInput::make('group_name')
                                        ->label(__('Group Name'))
                                        ->placeholder(__('Networks'))
                                        ->helperText(__('Group name used in the M3U playlist. Defaults to "Networks" if left empty.'))
                                        ->maxLength(255),
                                ]),
                        ]),

                    Tab::make(__('Schedule Settings'))
                        ->icon('heroicon-o-calendar')
                        ->schema([
                            Section::make(__('Schedule Settings'))
                                ->compact()
                                ->icon('heroicon-o-calendar')
                                ->description('')
                                ->schema([
                                    Grid::make(2)->schema([
                                        Select::make('schedule_type')
                                            ->label(__('Schedule Type'))
                                            ->options([
                                                'sequential' => 'Sequential (play in order)',
                                                'shuffle' => 'Shuffle (randomized)',
                                                'manual' => 'Manual (schedule builder)',
                                            ])
                                            ->default('sequential')
                                            ->helperText(__('How content is ordered in the schedule. Manual lets you place items on a visual timeline.'))
                                            ->native(false)
                                            ->live(),

                                        Select::make('manual_schedule_recurrence')
                                            ->label(__('Recurrence Mode'))
                                            ->options([
                                                'per_day' => 'Per Day (each day independent)',
                                                'weekly' => 'Weekly Template (Mon-Sun repeating)',
                                                'one_shot' => 'One Shot (fill window once)',
                                            ])
                                            ->default('per_day')
                                            ->helperText(__('How the manual schedule repeats across the schedule window'))
                                            ->native(false)
                                            ->visible(fn (Get $get): bool => $get('schedule_type') === 'manual'),

                                        TextInput::make('schedule_gap_seconds')
                                            ->label(__('Gap Between Programmes'))
                                            ->numeric()
                                            ->default(0)
                                            ->suffix('seconds')
                                            ->minValue(0)
                                            ->maxValue(3600)
                                            ->helperText(__('Space between consecutive programmes during cascade bump (0 = no gap)'))
                                            ->visible(fn (Get $get): bool => $get('schedule_type') === 'manual'),

                                        Toggle::make('loop_content')
                                            ->label(__('Loop Content'))
                                            ->inline(false)
                                            ->helperText(__('Restart from beginning when all content has played'))
                                            ->default(true),
                                    ]),

                                    Select::make('network_playlist_id')
                                        ->label(__('Output Playlist'))
                                        ->relationship(
                                            'networkPlaylist',
                                            'name',
                                            fn (Builder $query) => $query->where('is_network_playlist', true)
                                        )
                                        ->required()
                                        ->helperText(__('Assign this network to a playlist for M3U/EPG output. Create one if none exist.'))
                                        ->createOptionForm([
                                            TextInput::make('name')
                                                ->label(__('Playlist Name'))
                                                ->placeholder(__('e.g., My Networks'))
                                                ->required(),
                                        ])
                                        ->createOptionUsing(function (array $data): int {
                                            $playlist = Playlist::create([
                                                'name' => $data['name'],
                                                'uuid' => (string) Str::uuid(),
                                                'user_id' => Auth::id(),
                                                'is_network_playlist' => true,
                                            ]);

                                            return $playlist->id;
                                        })
                                        ->nullable()
                                        ->native(false),

                                    Toggle::make('enabled')
                                        ->label(__('Enabled'))
                                        ->helperText(__('Disable to stop generating schedule without deleting'))
                                        ->default(true)
                                        ->live()
                                        ->afterStateUpdated(function ($state, $record) {
                                            // If network is being disabled and is currently broadcasting, stop it
                                            if ($state === false && $record && $record->isBroadcasting()) {
                                                $service = app(NetworkBroadcastService::class);
                                                $service->stop($record);

                                                Notification::make()
                                                    ->warning()
                                                    ->title(__('Broadcast Stopped'))
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
            Step::make(__('Media Server'))
                ->description(__('Select content source'))
                ->icon('heroicon-o-server')
                ->schema([
                    Section::make('')
                        ->description(__('Networks pull their content from a media server integration. Select which media server to use.'))
                        ->schema([
                            Select::make('media_server_integration_id')
                                ->label(__('Media Server'))
                                ->relationship('mediaServerIntegration', 'name')
                                ->helperText(__('This network will use VOD content (movies/series) from this media server.'))
                                ->required()
                                ->native(false)
                                ->preload()
                                ->placeholder(__('Select a media server...')),
                        ]),
                ]),

            Step::make(__('Network Info'))
                ->description(__('Name and branding'))
                ->icon('heroicon-o-tv')
                ->schema([
                    Section::make('')
                        ->description(__('Give your network a name and optional branding.'))
                        ->schema([
                            Grid::make(2)->schema([
                                TextInput::make('name')
                                    ->label(__('Network Name'))
                                    ->placeholder(__('e.g., Movie Classics, 80s TV, Kids Zone'))
                                    ->required()
                                    ->maxLength(255),

                                TextInput::make('channel_number')
                                    ->label(__('Channel Number'))
                                    ->numeric()
                                    ->placeholder(__('e.g., 100'))
                                    ->helperText(__('Optional channel number for EPG ordering'))
                                    ->minValue(1),
                            ]),

                            Textarea::make('description')
                                ->label(__('Description'))
                                ->placeholder(__('A channel dedicated to classic movies from the golden age of cinema'))
                                ->rows(2)
                                ->maxLength(1000),

                            TextInput::make('logo')
                                ->label(__('Logo URL'))
                                ->placeholder(__('https://example.com/logo.png'))
                                ->url()
                                ->maxLength(500)
                                ->suffixActions([
                                    AssetPickerAction::upload('logo'),
                                    AssetPickerAction::browse('logo'),
                                ]),

                            TextInput::make('group_name')
                                ->label(__('Group Name'))
                                ->placeholder(__('Networks'))
                                ->helperText(__('Group name used in the M3U playlist. Defaults to "Networks" if left empty.'))
                                ->maxLength(255),
                        ]),
                ]),

            Step::make(__('Schedule'))
                ->description(__('Playback settings'))
                ->icon('heroicon-o-calendar')
                ->schema([
                    Section::make('')
                        ->description(__('Configure how content is scheduled and where the network is published.'))
                        ->schema([
                            Grid::make(2)->schema([
                                Select::make('schedule_type')
                                    ->label(__('Schedule Type'))
                                    ->options([
                                        'sequential' => 'Sequential (play in order)',
                                        'shuffle' => 'Shuffle (randomized)',
                                        'manual' => 'Manual (schedule builder)',
                                    ])
                                    ->default('sequential')
                                    ->helperText(__('How content is ordered in the schedule. Manual lets you place items on a visual timeline.'))
                                    ->native(false)
                                    ->live(),

                                Select::make('manual_schedule_recurrence')
                                    ->label(__('Recurrence Mode'))
                                    ->options([
                                        'per_day' => 'Per Day (each day independent)',
                                        'weekly' => 'Weekly Template (Mon-Sun repeating)',
                                        'one_shot' => 'One Shot (fill window once)',
                                    ])
                                    ->default('per_day')
                                    ->helperText(__('How the manual schedule repeats'))
                                    ->native(false)
                                    ->visible(fn (Get $get): bool => $get('schedule_type') === 'manual'),

                                TextInput::make('schedule_gap_seconds')
                                    ->label(__('Gap Between Programmes'))
                                    ->numeric()
                                    ->default(0)
                                    ->suffix('seconds')
                                    ->minValue(0)
                                    ->maxValue(3600)
                                    ->helperText(__('Space between consecutive programmes during cascade bump (0 = no gap)'))
                                    ->visible(fn (Get $get): bool => $get('schedule_type') === 'manual'),

                                Toggle::make('loop_content')
                                    ->label(__('Loop Content'))
                                    ->helperText(__('Restart from beginning when all content has played'))
                                    ->default(true),
                            ]),

                            Select::make('network_playlist_id')
                                ->label(__('Output Playlist'))
                                ->relationship(
                                    'networkPlaylist',
                                    'name',
                                    fn (Builder $query) => $query->where('is_network_playlist', true)
                                )
                                ->helperText(__('Assign to a network playlist for M3U/EPG output.'))
                                ->createOptionForm([
                                    TextInput::make('name')
                                        ->label(__('Playlist Name'))
                                        ->placeholder(__('e.g., My Networks'))
                                        ->required(),
                                ])
                                ->createOptionUsing(function (array $data): int {
                                    $playlist = Playlist::create([
                                        'name' => $data['name'],
                                        'uuid' => (string) Str::uuid(),
                                        'user_id' => Auth::id(),
                                        'is_network_playlist' => true,
                                    ]);

                                    return $playlist->id;
                                })
                                ->nullable()
                                ->native(false),

                            Toggle::make('enabled')
                                ->label(__('Enabled'))
                                ->helperText(__('Enable this network for schedule generation'))
                                ->default(true),
                        ]),
                ]),

            Step::make(__('Broadcast'))
                ->description(__('Live streaming (optional)'))
                ->icon('heroicon-o-signal')
                ->schema([
                    Section::make('')
                        ->description(__('Enable live broadcasting to stream content like a real TV channel. This is optional - you can enable it later.'))
                        ->schema([
                            Toggle::make('broadcast_enabled')
                                ->label(__('Enable Broadcasting'))
                                ->helperText(__('When enabled, this network will continuously broadcast content according to the schedule.'))
                                ->default(false)
                                ->live(),

                            Toggle::make('broadcast_on_demand')
                                ->label(__('Start On Viewer Connection'))
                                ->helperText(__('When enabled, broadcast waits for a viewer connection before starting automatically. Manual Start still forces immediate startup.'))
                                ->default(false)
                                ->visible(fn (Get $get): bool => $get('broadcast_enabled')),

                            Grid::make(2)->schema([
                                Select::make('output_format')
                                    ->label(__('Output Format'))
                                    ->options([
                                        'hls' => 'HLS (recommended)',
                                        'mpegts' => 'MPEG-TS',
                                    ])
                                    ->default('hls')
                                    ->native(false),

                                TextInput::make('segment_duration')
                                    ->label(__('Segment Duration'))
                                    ->numeric()
                                    ->default(6)
                                    ->suffix('seconds')
                                    ->minValue(2)
                                    ->maxValue(30),

                                TextInput::make('schedule_window_days')
                                    ->label(__('Schedule Window'))
                                    ->numeric()
                                    ->default(7)
                                    ->suffix('days')
                                    ->minValue(1)
                                    ->maxValue(30)
                                    ->helperText(__('Days of schedule to generate')),

                                Toggle::make('auto_regenerate_schedule')
                                    ->label(__('Auto-regenerate Schedule'))
                                    ->inline(false)
                                    ->helperText(__('Automatically regenerate when schedule is about to expire.'))
                                    ->default(true),
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
            Tab::make(__('EPG Output'))
                ->icon('heroicon-o-document-text')
                ->schema([
                    Section::make(__('EPG Output'))
                        ->compact()
                        ->icon('heroicon-o-document-text')
                        ->description('')
                        ->schema([
                            TextInput::make('epg_url')
                                ->label(__('EPG URL'))
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(fn ($record) => $record?->epg_url ?? 'Save network first')
                                ->hintAction(
                                    Action::make('qrCode')
                                        ->label(__('QR Code'))
                                        ->icon('heroicon-o-qr-code')
                                        ->modalHeading(__('EPG URL'))
                                        ->modalContent(fn ($record) => view('components.qr-code-display', ['text' => $record?->epg_url]))
                                        ->modalWidth('sm')
                                        ->modalSubmitAction(false)
                                        ->modalCancelAction(fn ($action) => $action->label(__('Close')))
                                        ->visible(fn ($record) => $record?->epg_url !== null)
                                )
                                ->hint(fn ($record) => $record?->epg_url ? view('components.copy-to-clipboard', ['text' => $record->epg_url, 'position' => 'left']) : null),

                            TextInput::make('schedule_info')
                                ->label(__('Schedule Info'))
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

            Tab::make(__('Stream Output'))
                ->icon('heroicon-o-play')
                ->schema([
                    Section::make(__('Stream Output'))
                        ->compact()
                        ->icon('heroicon-o-play')
                        ->description('')
                        ->schema([
                            TextInput::make('stream_url')
                                ->label(__('Stream URL'))
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(fn ($record) => $record?->stream_url ?? 'Save network first')
                                ->hintAction(
                                    Action::make('qrCode')
                                        ->label(__('QR Code'))
                                        ->icon('heroicon-o-qr-code')
                                        ->modalHeading(__('Stream URL'))
                                        ->modalContent(fn ($record) => view('components.qr-code-display', ['text' => $record?->stream_url]))
                                        ->modalWidth('sm')
                                        ->modalSubmitAction(false)
                                        ->modalCancelAction(fn ($action) => $action->label(__('Close')))
                                        ->visible(fn ($record) => $record?->stream_url !== null)
                                )
                                ->hint(fn ($record) => $record?->stream_url ? view('components.copy-to-clipboard', ['text' => $record->stream_url, 'position' => 'left']) : null),

                            TextInput::make('m3u_url')
                                ->label(__('M3U Playlist URL'))
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(fn ($record) => $record ? route('network.playlist', ['network' => $record->uuid]) : 'Save network first')
                                ->hintAction(
                                    Action::make('qrCode')
                                        ->label(__('QR Code'))
                                        ->icon('heroicon-o-qr-code')
                                        ->modalHeading(__('M3U Playlist URL'))
                                        ->modalContent(fn ($record) => view('components.qr-code-display', ['text' => $record ? route('network.playlist', ['network' => $record->uuid]) : 'Save network first']))
                                        ->modalWidth('sm')
                                        ->modalSubmitAction(false)
                                        ->modalCancelAction(fn ($action) => $action->label(__('Close')))
                                        ->visible(fn ($record) => $record?->uuid !== null)
                                )
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
            Tab::make(__('Broadcast Settings'))
                ->icon('heroicon-o-signal')
                ->schema([
                    Section::make(__('Broadcast Settings'))
                        ->compact()
                        ->icon('heroicon-o-signal')
                        ->columns(2)
                        ->description('')
                        ->schema([
                            Toggle::make('broadcast_enabled')
                                ->label(__('Enable Broadcasting'))
                                ->helperText(__('When enabled, this network will continuously broadcast content according to the schedule.'))
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
                                            ->title(__('Broadcast Stopped'))
                                            ->body("Broadcasting disabled - stream stopped for {$record->name}")
                                            ->send();
                                    }
                                }),

                            Toggle::make('broadcast_on_demand')
                                ->label(__('Start On Viewer Connection'))
                                ->helperText(__('When enabled, worker waits for viewer activity before auto-starting. Manual Start still starts immediately.'))
                                ->default(false)
                                ->columnSpan(1)
                                ->visible(fn (Get $get): bool => $get('broadcast_enabled')),

                            Toggle::make('broadcast_schedule_enabled')
                                ->label(__('Schedule Start Time'))
                                ->helperText(__('Wait until a specific date/time before starting the broadcast.'))
                                ->default(false)
                                ->columnSpan(1)
                                ->live()
                                ->visible(fn (Get $get): bool => $get('broadcast_enabled')),

                            DateTimePicker::make('broadcast_scheduled_start')
                                ->label(__('Scheduled Start Time'))
                                ->helperText(__('Broadcast will wait until this time to start. Leave empty to start immediately.'))
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
                                        $scheduledTime = Carbon::parse($state);
                                        if ($scheduledTime->isPast()) {
                                            Notification::make()
                                                ->warning()
                                                ->title(__('Invalid Time'))
                                                ->body(__('Scheduled start time must be in the future.'))
                                                ->send();
                                        }
                                    }
                                }),

                            Grid::make(2)->schema([
                                Select::make('output_format')
                                    ->label(__('Output Format'))
                                    ->options([
                                        'hls' => 'HLS (recommended)',
                                        'mpegts' => 'MPEG-TS',
                                    ])
                                    ->default('hls')
                                    ->native(false)
                                    ->helperText(__('HLS provides better compatibility')),

                                TextInput::make('segment_duration')
                                    ->label(__('Segment Duration'))
                                    ->numeric()
                                    ->default(6)
                                    ->suffix('seconds')
                                    ->minValue(2)
                                    ->maxValue(30)
                                    ->helperText(__('HLS segment length (6s recommended)')),

                                TextInput::make('schedule_window_days')
                                    ->label(__('Schedule Window'))
                                    ->numeric()
                                    ->default(7)
                                    ->suffix('days')
                                    ->minValue(1)
                                    ->maxValue(30)
                                    ->helperText(__('How many days of programme schedule to generate in advance.')),

                                Toggle::make('auto_regenerate_schedule')
                                    ->label(__('Auto-regenerate Schedule'))
                                    ->inline(false)
                                    ->helperText(__('Automatically regenerate when schedule is about to expire (within 24 hours).'))
                                    ->default(true),
                            ])->visible(fn (Get $get): bool => $get('broadcast_enabled')),

                            Section::make(__('Transcoding'))
                                ->compact()
                                ->description(__('Control how media is transcoded'))
                                ->schema([
                                    ToggleButtons::make('transcode_mode')
                                        ->label(__('Transcode Mode'))
                                        ->grouped()
                                        ->live()
                                        ->options([
                                            TranscodeMode::Direct->value => 'Direct (Passthrough)',
                                            TranscodeMode::Server->value => 'Media Server (Jellyfin/Emby/Plex)',
                                            TranscodeMode::Local->value => 'Local (FFmpeg via Proxy)',
                                        ])
                                        ->icons([
                                            TranscodeMode::Direct->value => 'heroicon-s-check',
                                            TranscodeMode::Server->value => 'heroicon-s-server-stack',
                                            TranscodeMode::Local->value => 'heroicon-s-arrows-right-left',
                                        ])
                                        ->colors([
                                            TranscodeMode::Direct->value => 'gray',
                                            TranscodeMode::Server->value => 'success',
                                            TranscodeMode::Local->value => 'primary',
                                        ])
                                        ->default(TranscodeMode::Local->value)
                                        ->inline()
                                        ->helperText(__('Choose if and where transcoding should occur. Restart the broadcast after changing this setting.')),

                                    Grid::make(3)->schema([
                                        TextInput::make('video_bitrate')
                                            ->label(__('Video Bitrate'))
                                            ->numeric()
                                            ->suffix('kbps')
                                            ->placeholder(__('Source'))
                                            ->nullable(),

                                        TextInput::make('audio_bitrate')
                                            ->label(__('Audio Bitrate'))
                                            ->numeric()
                                            ->suffix('kbps')
                                            ->default(192),

                                        Select::make('video_resolution')
                                            ->label(__('Resolution'))
                                            ->options([
                                                null => 'Source (no scaling)',
                                                '3840x2160' => '4K',
                                                '1920x1080' => '1080p',
                                                '1280x720' => '720p',
                                                '854x480' => '480p',
                                            ])
                                            ->placeholder(__('Source'))
                                            ->native(false)
                                            ->nullable(),
                                    ])->visible(fn (Get $get): bool => $get('transcode_mode') !== TranscodeMode::Direct->value),

                                    Grid::make(3)->schema([
                                        TextInput::make('video_codec')
                                            ->label(__('Video Codec'))
                                            ->helperText(__('e.g. libx264, h264_nvenc'))
                                            ->placeholder(__('libx264'))
                                            ->nullable(),

                                        TextInput::make('audio_codec')
                                            ->label(__('Audio Codec'))
                                            ->helperText(__('e.g. aac'))
                                            ->placeholder(__('aac'))
                                            ->nullable(),

                                        TextInput::make('transcode_preset')
                                            ->label(__('Encoder Preset'))
                                            ->helperText(__('e.g. veryfast, fast, medium'))
                                            ->placeholder(__('veryfast'))
                                            ->nullable(),
                                    ])->visible(fn (Get $get): bool => $get('transcode_mode') === TranscodeMode::Local->value),

                                    Select::make('hwaccel')
                                        ->label(__('Hardware Acceleration'))
                                        ->placeholder(__('Auto/Default'))
                                        ->options([
                                            'none' => 'None',
                                            'cuda' => 'CUDA (NVIDIA)',
                                            'vaapi' => 'VA-API',
                                        ])
                                        ->helperText(__('Hint for proxy to enable hardware acceleration if available'))
                                        ->nullable()
                                        ->visible(fn (Get $get): bool => $get('transcode_mode') === TranscodeMode::Local->value),
                                ])
                                ->visible(fn (Get $get): bool => $get('broadcast_enabled')),

                            Section::make(__('Broadcast Status'))
                                ->compact()
                                ->schema([
                                    TextInput::make('broadcast_status')
                                        ->label(__('Status'))
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->formatStateUsing(function ($record) {
                                            if (! $record) {
                                                return '⚪ Not broadcasting';
                                            }

                                            if ($record->isBroadcasting()) {
                                                return '🟢 Broadcasting (PID: '.$record->broadcast_pid.')';
                                            }

                                            if ($record->isWaitingForConnection()) {
                                                return '🟡 Started (waiting for connection)';
                                            }

                                            return '⚪ Not broadcasting';
                                        }),

                                    TextInput::make('broadcast_started_at_display')
                                        ->label(__('Started At'))
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->formatStateUsing(fn ($record) => $record?->broadcast_started_at?->format('M j, Y H:i:s') ?? '-'),

                                    TextInput::make('hls_url')
                                        ->label(__('HLS Playlist URL'))
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->formatStateUsing(fn ($record) => $record ? route('network.hls.playlist', ['network' => $record->uuid]) : 'Save network first')
                                        ->hintAction(
                                            Action::make('qrCode')
                                                ->label(__('QR Code'))
                                                ->icon('heroicon-o-qr-code')
                                                ->modalHeading(__('HLS Playlist URL'))
                                                ->modalContent(fn ($record) => view('components.qr-code-display', ['text' => $record ? route('network.hls.playlist', ['network' => $record->uuid]) : 'Save network first']))
                                                ->modalWidth('sm')
                                                ->modalSubmitAction(false)
                                                ->modalCancelAction(fn ($action) => $action->label(__('Close')))
                                                ->visible(fn ($record) => $record?->uuid !== null)
                                        )
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
                return $action->button()->label(__('Filters'));
            })
            ->reorderRecordsTriggerAction(function ($action) {
                return $action->button()->label(__('Sort'));
            })
            ->reorderable('channel_number')
            ->columns([
                ImageColumn::make('logo')
                    ->label(__('Logo'))
                    ->checkFileExistence(false)
                    ->size('inherit', 'inherit')
                    ->extraImgAttributes(fn (): array => [
                        'style' => 'height:2.5rem; width:auto; border-radius:4px;',
                    ])
                    ->defaultImageUrl(url('/placeholder.png'))
                    ->toggleable(),

                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),

                ToggleColumn::make('enabled')
                    ->label(__('Enabled'))
                    ->afterStateUpdated(function ($record, $state) {
                        // If network is being disabled and is currently broadcasting, stop it
                        if ($state === false && $record->isBroadcasting()) {
                            $service = app(NetworkBroadcastService::class);
                            $service->stop($record);

                            Notification::make()
                                ->warning()
                                ->title(__('Broadcast Stopped'))
                                ->body("Network disabled - broadcast has been stopped for {$record->name}")
                                ->send();
                        }
                    }),

                TextColumn::make('channel_number')
                    ->label(__('Ch #'))
                    ->sortable()
                    ->placeholder(__('-')),

                TextColumn::make('schedule_type')
                    ->label(__('Schedule'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'shuffle' => 'warning',
                        'sequential' => 'info',
                        'manual' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('network_content_count')
                    ->label(__('Content'))
                    ->counts('networkContent')
                    ->sortable(),

                TextColumn::make('schedule_generated_at')
                    ->label(__('Schedule Generated'))
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->placeholder(__('Never')),

                TextColumn::make('mediaServerIntegration.name')
                    ->label(__('Media Server'))
                    ->placeholder(__('None')),

                TextColumn::make('transcode_mode')
                    ->label(__('Transcode'))
                    ->badge()
                    ->formatStateUsing(fn (?TranscodeMode $state): string => $state?->getLabel() ?? 'Not Set')
                    ->color(fn (?TranscodeMode $state): string => match ($state) {
                        TranscodeMode::Local => 'warning',
                        TranscodeMode::Server => 'info',
                        TranscodeMode::Direct => 'success',
                        default => 'gray',
                    })
                    ->toggleable(),

                TextColumn::make('broadcast_status')
                    ->label(__('Broadcast'))
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
                        if ($record->isWaitingForConnection()) {
                            return 'Waiting';
                        }
                        if ($record->broadcast_on_demand && $record->broadcast_requested) {
                            return 'Waiting';
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
                        'Waiting' => 'info',
                        'Scheduled' => 'warning',
                        'Stopped' => 'warning',
                        'Disabled' => 'gray',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'Live' => 'heroicon-s-signal',
                        'Starting' => 'heroicon-s-arrow-path',
                        'Waiting' => 'heroicon-s-pause-circle',
                        'Scheduled' => 'heroicon-s-clock',
                        'Stopped' => 'heroicon-s-stop',
                        'Disabled' => 'heroicon-s-no-symbol',
                        default => 'heroicon-s-question-mark-circle',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('schedule_type')
                    ->options([
                        'sequential' => 'Sequential',
                        'shuffle' => 'Shuffle',
                        'manual' => 'Manual',
                    ]),
                Tables\Filters\TernaryFilter::make('enabled')
                    ->label(__('Enabled')),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('generateSchedule')
                        ->label(__('Generate Schedule'))
                        ->icon('heroicon-o-calendar')
                        ->requiresConfirmation()
                        ->modalHeading(__('Generate Schedule'))
                        ->modalDescription(fn (Network $record): string => 'This will generate a '.($record->schedule_window_days ?? 7).'-day programme schedule for this network. Existing future programmes will be replaced.')
                        ->disabled(fn (Network $record): bool => $record->network_playlist_id === null)
                        ->tooltip(fn (Network $record): ?string => $record->network_playlist_id === null ? 'Assign to a playlist first' : null)
                        ->action(function (Network $record) {
                            $service = app(NetworkScheduleService::class);
                            $service->generateSchedule($record);

                            Notification::make()
                                ->success()
                                ->title(__('Schedule Generated'))
                                ->body("Generated programme schedule for {$record->name}")
                                ->send();
                        }),

                    Action::make('viewPlaylist')
                        ->label(__('View Playlist'))
                        ->icon('heroicon-o-eye')
                        ->visible(fn (Network $record): bool => $record->network_playlist_id !== null)
                        ->url(fn (Network $record): string => PlaylistResource::getUrl('view', ['record' => $record->network_playlist_id])),

                    DeleteAction::make(),
                ])->button()->hiddenLabel()->size('sm'),
                EditAction::make()->button()->hiddenLabel()->size('sm'),
                Action::make('startBroadcast')
                    ->label(__('Start Broadcast'))
                    ->icon('heroicon-s-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('Start Broadcasting'))
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

                        $result = $service->startNow($record);

                        // Refresh to get updated error message
                        $record->refresh();

                        if ($result) {
                            Notification::make()
                                ->success()
                                ->title(__('Broadcast Started'))
                                ->body("Broadcasting started for {$record->name}")
                                ->send();
                        } elseif ($record->broadcast_schedule_enabled && $record->broadcast_scheduled_start && now()->lt($record->broadcast_scheduled_start)) {
                            Notification::make()
                                ->info()
                                ->title(__('Broadcast Scheduled'))
                                ->body("Broadcast will start at {$record->broadcast_scheduled_start->format('M j, Y H:i:s')} ({$record->broadcast_scheduled_start->diffForHumans()})")
                                ->send();
                        } else {
                            $errorMsg = $record->broadcast_error ?? 'Could not start broadcast. Check that there is content scheduled.';

                            Notification::make()
                                ->danger()
                                ->title(__('Failed to Start'))
                                ->body($errorMsg)
                                ->send();
                        }
                    })->button()->hiddenLabel()->size('sm'),

                Action::make('stopBroadcast')
                    ->label(__('Stop Broadcast'))
                    ->icon('heroicon-s-stop')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Stop Broadcasting'))
                    ->modalDescription(__('Stop the current broadcast. Viewers will be disconnected.'))
                    ->visible(fn (Network $record): bool => $record->isBroadcasting())
                    ->action(function (Network $record) {
                        $service = app(NetworkBroadcastService::class);
                        $service->stop($record);

                        Notification::make()
                            ->warning()
                            ->title(__('Broadcast Stopped'))
                            ->body("Broadcasting stopped for {$record->name}")
                            ->send();
                    })->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('generateAllSchedules')
                        ->label(__('Generate Schedules'))
                        ->icon('heroicon-o-calendar')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $service = app(NetworkScheduleService::class);
                            foreach ($records as $record) {
                                $service->generateSchedule($record);
                            }

                            Notification::make()
                                ->success()
                                ->title(__('Schedules Generated'))
                                ->body('Generated schedules for '.$records->count().' networks.')
                                ->send();
                        }),

                    BulkAction::make('startBroadcastSelected')
                        ->label(__('Start Broadcast'))
                        ->icon('heroicon-s-play')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading(__('Start Broadcasting'))
                        ->modalDescription(__('Start broadcasting for the selected networks.'))
                        ->action(function (Collection $records): void {
                            $service = app(NetworkBroadcastService::class);

                            foreach ($records as $record) {
                                $record->update(['broadcast_requested' => true]);
                                $service->startNow($record);
                            }

                            Notification::make()
                                ->success()
                                ->title(__('Broadcast Started'))
                                ->body('Broadcast start requested for '.$records->count().' networks.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('stopBroadcastSelected')
                        ->label(__('Stop Broadcast'))
                        ->icon('heroicon-s-stop')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('Stop Broadcasting'))
                        ->modalDescription(__('Stop broadcasting for the selected networks.'))
                        ->action(function (Collection $records): void {
                            $service = app(NetworkBroadcastService::class);

                            foreach ($records as $record) {
                                $service->stop($record);
                            }

                            Notification::make()
                                ->warning()
                                ->title(__('Broadcast Stopped'))
                                ->body('Broadcast stopped for '.$records->count().' networks.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('set_logo_url')
                        ->label(__('Set logo URL'))
                        ->schema([
                            TextInput::make('logo')
                                ->label(__('Logo URL'))
                                ->url()
                                ->nullable()
                                ->helperText(__('Leave empty to remove the logo.')),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            Network::whereIn('id', $records->pluck('id')->toArray())
                                ->update([
                                    'logo' => empty($data['logo']) ? null : $data['logo'],
                                ]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Logo updated'))
                                ->body(__('The logo URL has been updated for the selected networks.'))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-link')
                        ->modalIcon('heroicon-o-link')
                        ->modalDescription(__('Apply a single logo URL to all selected networks. Leave empty to remove logos.'))
                        ->modalSubmitActionLabel(__('Apply URL')),

                    BulkAction::make('refresh_logo_cache')
                        ->label(__('Refresh logo cache (selected)'))
                        ->action(function (Collection $records): void {
                            $urls = [];

                            foreach ($records as $record) {
                                $urls[] = $record->logo;
                            }

                            $cleared = LogoCacheService::clearByUrls($urls);

                            Notification::make()
                                ->success()
                                ->title(__('Selected logo cache refreshed'))
                                ->body("Removed {$cleared} cache file(s) for selected networks.")
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription(__('Clear cached logos for selected networks so they are fetched again on the next request.'))
                        ->modalSubmitActionLabel(__('Refresh selected cache')),

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
            'schedule-builder' => ManualScheduleBuilder::route('/{record}/schedule-builder'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', Auth::id());
    }
}
