<?php

namespace App\Filament\Resources\MediaServerIntegrations;

use App\Filament\Resources\MediaServerIntegrations\Pages\CreateMediaServerIntegration;
use App\Filament\Resources\MediaServerIntegrations\Pages\EditMediaServerIntegration;
use App\Filament\Resources\MediaServerIntegrations\Pages\ListMediaServerIntegrations;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Jobs\SyncMediaServer;
use App\Models\CustomPlaylist;
use App\Models\MediaServerIntegration;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Services\MediaServerService;
use App\Services\PlexManagementService;
use App\Tables\Columns\ProgressColumn;
use App\Traits\HasUserFiltering;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

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

    /**
     * Check if the user can access this page.
     * Only users with the "integrations" permission can access this page.
     */
    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseIntegrations();
    }

    /**
     * Build the external base URL for HDHR/EPG endpoints.
     * Handles APP_URL values with or without a scheme.
     */
    protected static function buildHdhrBaseUrl(): string
    {
        $appUrl = rtrim(config('app.url'), '/');
        if (! parse_url($appUrl, PHP_URL_SCHEME)) {
            $appUrl = 'http://'.$appUrl;
        }
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'http';
        $host = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';
        $port = parse_url($appUrl, PHP_URL_PORT) ?: config('app.port', 36400);

        return "{$scheme}://{$host}:{$port}";
    }

    /**
     * Resolve a playlist UUID to a human-readable name.
     */
    protected static function resolvePlaylistName(string $uuid): string
    {
        if (! $uuid) {
            return '—';
        }

        $playlist = Playlist::where('uuid', $uuid)->first()
            ?? CustomPlaylist::where('uuid', $uuid)->first()
            ?? MergedPlaylist::where('uuid', $uuid)->first();

        return $playlist ? $playlist->name : $uuid;
    }

    public static function getRecordTitle(?Model $record): string|null|Htmlable
    {
        return $record?->name;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getForm());
    }

    public static function getForm(): array
    {
        $tabs = [];
        foreach (collect(self::getFormSections(creating: false)) as $section => $fields) {
            // Determine icon for section
            $icon = match (strtolower($section)) {
                'connection' => 'heroicon-m-signal',
                'import' => 'heroicon-m-arrow-down-tray',
                'schedule' => 'heroicon-m-calendar',
                'status' => 'heroicon-m-information-circle',
                'plex management' => 'heroicon-m-cog-6-tooth',
                'networks' => 'heroicon-m-tv',
                default => null,
            };

            $tabs[] = Tab::make($section)
                ->icon($icon)
                ->schema($fields);
        }

        return [
            Tabs::make('Media Server Integration')
                ->tabs($tabs)
                ->columnSpanFull()
                ->contained(false)
                ->persistTabInQueryString(),
        ];
    }

    public static function getFormSteps(): array
    {
        $wizard = [];
        foreach (self::getFormSections(creating: true) as $step => $fields) {
            if (in_array($step, ['Status', 'Networks', 'Plex Management'])) {
                continue;
            }

            // Determine icon for step
            $icon = match (strtolower($step)) {
                'connection' => 'heroicon-m-signal',
                'import' => 'heroicon-m-arrow-down-tray',
                'schedule' => 'heroicon-m-calendar',
                default => null,
            };

            $wizard[] = Step::make($step)
                ->icon($icon)
                ->schema($fields);
        }

        return $wizard;
    }

    public static function getFormSections($creating = false): array
    {
        return [
            'Connection' => [
                Section::make('Server Configuration')
                    ->description(fn (callable $get) => match ($get('type')) {
                        'local' => 'Configure your local media library paths',
                        'webdav' => 'Configure your WebDAV server connection and media library paths',
                        default => 'Configure your media server connection',
                    })
                    ->collapsible(! $creating)
                    ->collapsed(! $creating)
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label('Display Name')
                                ->placeholder(fn (callable $get) => match ($get('type')) {
                                    'local' => 'e.g., My Local Movies',
                                    'webdav' => 'e.g., My NAS Media',
                                    default => 'e.g., Living Room Jellyfin',
                                })
                                ->required()
                                ->maxLength(255),

                            Select::make('type')
                                ->label('Server Type')
                                ->options([
                                    'emby' => 'Emby',
                                    'jellyfin' => 'Jellyfin',
                                    'plex' => 'Plex',
                                    'local' => 'Local Media',
                                    'webdav' => 'WebDAV',
                                ])
                                ->required()
                                ->default('emby')
                                ->live()
                                ->afterStateUpdated(function (Set $set, ?string $state): void {
                                    $set('port', match ($state) {
                                        'plex' => 32400,
                                        'webdav' => 5005,
                                        default => 8096,
                                    });
                                })
                                ->disabledOn('edit')
                                ->native(false),
                        ]),

                        // Network server configuration (hidden for local media)
                        Grid::make(3)->schema([
                            TextInput::make('host')
                                ->label('Host / IP Address')
                                ->prefix(fn (callable $get) => $get('ssl') ? 'https://' : 'http://')
                                ->placeholder(fn (callable $get) => $get('type') === 'webdav'
                                    ? '192.168.1.100 or nas.example.com'
                                    : '192.168.1.100 or media.example.com')
                                ->required(fn (callable $get) => $get('type') !== 'local')
                                ->maxLength(255),

                            TextInput::make('port')
                                ->label('Port')
                                ->numeric()
                                ->default(fn (callable $get) => match ($get('type')) {
                                    'plex' => 32400,
                                    'webdav' => 5005,
                                    default => 8096,
                                })
                                ->helperText(fn (callable $get) => match ($get('type')) {
                                    'webdav' => 'e.g., 5005 for Synology, 80/443 for standard WebDAV',
                                    default => 'e.g., 8096 for Emby/Jellyfin, 32400 for Plex',
                                })
                                ->required(fn (callable $get) => $get('type') !== 'local')
                                ->minValue(1)
                                ->maxValue(65535),

                            Toggle::make('ssl')
                                ->live()
                                ->inline(false)
                                ->label('Use HTTPS')
                                ->helperText('Enable if your server uses SSL/TLS')
                                ->default(false),
                        ])->visible(fn (callable $get) => $get('type') !== 'local'),

                        // WebDAV authentication (username/password)
                        Grid::make(2)->schema([
                            TextInput::make('webdav_username')
                                ->label('WebDAV Username')
                                ->placeholder('username')
                                ->helperText('Username for WebDAV authentication'),

                            TextInput::make('webdav_password')
                                ->label('WebDAV Password')
                                ->password()
                                ->revealable()
                                ->dehydrateStateUsing(fn ($state, $record) => filled($state) ? $state : $record?->webdav_password)
                                ->helperText(function (string $operation) {
                                    if ($operation === 'edit') {
                                        return 'Leave blank to keep existing password';
                                    }

                                    return 'Password for WebDAV authentication';
                                }),
                        ])->visible(fn (callable $get) => $get('type') === 'webdav'),

                        TextInput::make('api_key')
                            ->label('API Key/Token')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation, callable $get): bool => $operation === 'create' && ! in_array($get('type'), ['local', 'webdav']))
                            ->dehydrateStateUsing(fn ($state, $record) => filled($state) ? $state : $record?->api_key)
                            ->helperText(function (string $operation, callable $get) {
                                if ($operation === 'edit') {
                                    return 'Leave blank to keep existing API key';
                                }

                                return match ($get('type')) {
                                    'plex' => new HtmlString('See <a class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300" href="https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/" target="_blank">Plex Docs</a> for instructions on finding your token'),
                                    'local', 'webdav' => 'Not required for local media or WebDAV',
                                    default => 'Generate an API key in your media server\'s dashboard under Settings → API Keys',
                                };
                            })->visible(fn (callable $get) => ! in_array($get('type'), ['local', 'webdav'])),

                        Actions::make(self::getServerActions())
                            ->visible(fn (callable $get) => ! in_array($get('type'), ['local', 'webdav']))
                            ->fullWidth(),
                    ]),
            ],
            'Import' => [
                Section::make('Import Settings')
                    ->description('Control what content is synced from the media server')
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Enabled')
                            ->live()
                            ->helperText('Disable to pause syncing without deleting the integration')
                            ->default(true),

                        Grid::make(2)->schema([
                            Toggle::make('import_movies')
                                ->label('Import Movies')
                                ->helperText('Sync movies as VOD channels')
                                ->default(true),

                            Toggle::make('import_series')
                                ->label('Import Series')
                                ->helperText('Sync TV series with episodes')
                                ->default(true),
                        ])->visible(fn (callable $get) => $get('enabled')),

                        Select::make('genre_handling')
                            ->label('Genre Handling')
                            ->options([
                                'primary' => 'Primary Genre Only (recommended)',
                                'all' => 'All Genres (creates duplicates)',
                            ])
                            ->default('primary')
                            ->helperText('How to handle content with multiple genres')
                            ->native(false)
                            ->visible(fn (callable $get) => $get('enabled')),
                    ]),

                // Local Media Configuration Section
                Section::make(fn (callable $get) => $get('type') === 'webdav' ? 'WebDAV Media Libraries' : 'Local Media Libraries')
                    ->description(fn (callable $get) => $get('type') === 'webdav'
                        ? new HtmlString(
                            '<p>Configure paths to your media files on the WebDAV server.</p>'.
                            '<p class="mt-2"><strong>Example paths:</strong> <code>/movies</code>, <code>/tvshows</code>, <code>/media/movies</code></p>'
                        )
                        : new HtmlString(
                            '<p>Configure paths to your local media files.</p>'.
                            '<p class="mt-2 text-warning-600 dark:text-warning-400"><strong>Important:</strong> These paths must be accessible within the Docker container. '.
                            'Mount your media directories in your <code>docker-compose.yml</code> file, e.g.:</p>'.
                            '<pre class="mt-1 text-xs bg-gray-100 dark:bg-gray-800 p-2 rounded">volumes:'."\n".'  - /path/on/host/movies:/media/movies'."\n".'  - /path/on/host/tvshows:/media/tvshows</pre>'
                        )
                    )
                    ->schema([
                        Repeater::make('local_media_paths')
                            ->label('Media Library Paths')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Library Name')
                                    ->placeholder('e.g., Movies, TV Shows')
                                    ->required(),

                                TextInput::make('path')
                                    ->label(fn (callable $get) => $get('../../type') === 'webdav' ? 'WebDAV Path' : 'Container Path')
                                    ->placeholder(fn (callable $get) => $get('../../type') === 'webdav' ? '/movies' : '/media/movies')
                                    ->required()
                                    ->helperText(fn (callable $get) => $get('../../type') === 'webdav'
                                        ? 'Path on the WebDAV server'
                                        : 'Path inside the Docker container'),

                                Select::make('type')
                                    ->label('Content Type')
                                    ->options([
                                        'movies' => 'Movies',
                                        'tvshows' => 'TV Shows',
                                    ])
                                    ->required()
                                    ->default('movies')
                                    ->native(false),
                            ])
                            ->columns(3)
                            ->defaultItems(1)
                            ->addActionLabel('Add Library Path')
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? 'New Library'),

                        Grid::make(2)->schema([
                            Toggle::make('scan_recursive')
                                ->label('Scan Recursively')
                                ->helperText('Scan subdirectories for media files')
                                ->default(true),

                            Toggle::make('auto_fetch_metadata')
                                ->label('Auto-Fetch Metadata')
                                ->helperText('Automatically lookup TMDB metadata after sync completes (Local & WebDAV)')
                                ->default(true),
                        ]),

                        Grid::make(1)->schema([
                            Select::make('metadata_source')
                                ->label('Metadata Source')
                                ->options([
                                    'filename_only' => 'Filename Only (No External Lookup)',
                                    'tmdb' => 'TMDB (The Movie Database)',
                                ])
                                ->default('tmdb')
                                ->helperText('Where to fetch metadata for discovered content (requires TMDB API key in Settings)')
                                ->native(false),
                        ]),

                        TagsInput::make('video_extensions')
                            ->label('Video File Extensions')
                            ->placeholder('Add extension...')
                            ->default(['mp4', 'mkv', 'avi', 'mov', 'wmv', 'ts', 'm4v'])
                            ->helperText('File extensions to scan for (without dots)'),

                        Actions::make(self::getLocalActions())->fullWidth(),
                    ])->visible(fn (callable $get) => in_array($get('type'), ['local', 'webdav'])),

                Section::make('Library Selection')
                    ->description('Select which libraries to import from your media server')
                    ->headerActions(self::getServerActions())
                    ->schema([
                        Hidden::make('available_libraries')
                            ->dehydrateStateUsing(fn ($state) => $state)
                            ->default([])
                            ->rules([
                                fn (callable $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    $enabled = $get('enabled');
                                    $importMovies = $get('import_movies');
                                    $importSeries = $get('import_series');
                                    $type = $get('type');

                                    // For local media and webdav, paths are configured separately
                                    if (in_array($type, ['local', 'webdav'])) {
                                        return;
                                    }

                                    if ($enabled && ($importMovies || $importSeries) && empty($value)) {
                                        $fail('Libraries must be discovered before saving. Use the test connection button above.');
                                    }
                                },
                            ]),

                        Placeholder::make('library_instructions')
                            ->label('')
                            ->content(function (callable $get) {
                                $libraries = $get('available_libraries');
                                $type = $get('type');

                                if (empty($libraries)) {
                                    $buttonLabel = in_array($type, ['local', 'webdav'])
                                        ? 'Scan & Discover Libraries'
                                        : 'Test Connection & Discover Libraries';

                                    return new HtmlString(
                                        '<div class="text-sm text-gray-500 dark:text-gray-400">'.
                                        '<p class="font-medium text-warning-600 dark:text-warning-400">No libraries discovered yet.</p>'.
                                        "<p class=\"mt-1\">Click \"{$buttonLabel}\" above to discover available libraries.</p>".
                                        '</div>'
                                    );
                                }

                                $libraryCount = count($libraries);
                                $selectedCount = count($get('selected_library_ids') ?? []);

                                return new HtmlString(
                                    '<div class="text-sm text-gray-500 dark:text-gray-400">'.
                                    "<p>Found <strong>{$libraryCount}</strong> libraries. <strong>{$selectedCount}</strong> selected for import.</p>".
                                    '<p class="mt-1">Select the libraries you want to sync content from.</p>'.
                                    '</div>'
                                );
                            }),

                        CheckboxList::make('selected_library_ids')
                            ->label('Libraries to Import')
                            ->options(function (callable $get) {
                                $libraries = $get('available_libraries');
                                if (empty($libraries)) {
                                    return [];
                                }

                                $options = [];
                                foreach ($libraries as $library) {
                                    $typeLabel = $library['type'] === 'movies' ? 'Movies' : 'TV Shows';
                                    $itemCount = $library['item_count'] > 0 ? " ({$library['item_count']} items)" : '';
                                    $options[$library['id']] = "{$library['name']} [{$typeLabel}]{$itemCount}";
                                }

                                return $options;
                            })
                            ->descriptions(function (callable $get) {
                                $libraries = $get('available_libraries');
                                if (empty($libraries)) {
                                    return [];
                                }

                                $descriptions = [];
                                foreach ($libraries as $library) {
                                    if (! empty($library['path'])) {
                                        $descriptions[$library['id']] = $library['path'];
                                    }
                                }

                                return $descriptions;
                            })
                            ->columns(1)
                            ->bulkToggleable()
                            ->live()
                            ->required(fn (callable $get) => $get('enabled') && ($get('import_movies') || $get('import_series')) && ! in_array($get('type'), ['local', 'webdav']))
                            ->validationMessages([
                                'required' => 'Please select at least one library to import.',
                            ]),
                    ])->visible(fn (callable $get) => ! in_array($get('type'), ['local', 'webdav'])),
            ],
            'Schedule' => [
                Section::make('Sync Schedule')
                    ->description('Configure automatic sync schedule')
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('auto_sync')
                                ->inline(false)
                                ->live()
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
                                ->native(false)
                                ->disabled(fn (callable $get) => ! $get('auto_sync')),
                        ]),
                    ]),
            ],
            'Status' => [
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
                                        $state = Carbon::parse($state);
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
                    ->visible(! $creating),
            ],
            'Plex Management' => [
                Section::make('Plex Server Management')
                    ->description('Manage your Plex server directly from m3u-editor — register DVR tuners, monitor sessions, and control libraries.')
                    ->schema([
                        Toggle::make('plex_management_enabled')
                            ->label('Enable Plex Management')
                            ->helperText('When enabled, you can manage your Plex server from this integration.')
                            ->live()
                            ->default(false),

                        Grid::make(2)->schema([
                            Placeholder::make('plex_server_info')
                                ->label('Server Info')
                                ->content(function ($record) {
                                    if (! $record || ! $record->isPlex()) {
                                        return new HtmlString('<span class="text-gray-400">Save integration first</span>');
                                    }
                                    try {
                                        $service = PlexManagementService::make($record);
                                        $result = $service->getServerInfo();
                                        if ($result['success']) {
                                            $data = $result['data'];

                                            return new HtmlString(
                                                '<div class="text-sm space-y-1">'
                                                .'<p><strong>'.$data['name'].'</strong></p>'
                                                .'<p>Version: '.$data['version'].'</p>'
                                                .'<p>Platform: '.$data['platform'].'</p>'
                                                .'</div>'
                                            );
                                        }

                                        return new HtmlString('<span class="text-danger-500">Connection failed</span>');
                                    } catch (\Exception $e) {
                                        return new HtmlString('<span class="text-danger-500">Error: '.$e->getMessage().'</span>');
                                    }
                                }),

                            Placeholder::make('plex_dvr_sync_status')
                                ->label('DVR Sync Status')
                                ->content(function ($record) {
                                    if (! $record || ! $record->isPlex()) {
                                        return '—';
                                    }
                                    try {
                                        $service = PlexManagementService::make($record);
                                        $result = $service->verifyDvrSync();
                                        if (! $result['success']) {
                                            return new HtmlString('<span class="text-danger-500">'.e($result['message'] ?? 'Verification failed').'</span>');
                                        }

                                        $data = $result['data'];
                                        $status = $data['status'];

                                        if ($status === 'not_configured') {
                                            return new HtmlString('<span class="text-gray-400">'.e($data['summary']).'</span>');
                                        }

                                        if ($status === 'error') {
                                            return new HtmlString('<span class="text-danger-500">'.e($data['summary']).'</span>');
                                        }

                                        $icon = $status === 'ok'
                                            ? '<svg xmlns="http://www.w3.org/2000/svg" class="inline-block h-5 w-5 text-success-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>'
                                            : '<svg xmlns="http://www.w3.org/2000/svg" class="inline-block h-5 w-5 text-warning-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>';

                                        $colorClass = $status === 'ok' ? 'text-success-500' : 'text-warning-500';
                                        $html = '<div class="text-sm space-y-1">';
                                        $html .= '<p class="'.$colorClass.' font-medium">'.$icon.' '.e($data['summary']).'</p>';

                                        if (! empty($data['tuners'])) {
                                            $totalChannels = $data['total_channels'] ?? 0;
                                            $totalPlex = $data['total_in_plex'] ?? 0;
                                            $totalEpg = $data['total_epg_mapped'] ?? 0;
                                            $html .= '<div class="mt-2 text-xs text-gray-500 dark:text-gray-400">';
                                            $html .= '<p>Channels: '.$totalPlex.'/'.$totalChannels.' in Plex</p>';
                                            $html .= '<p>EPG: '.$totalEpg.'/'.$totalChannels.' mapped</p>';
                                            $html .= '</div>';
                                        }

                                        $html .= '</div>';

                                        return new HtmlString($html);
                                    } catch (\Exception $e) {
                                        return new HtmlString('<span class="text-danger-500">Error: '.e($e->getMessage()).'</span>');
                                    }
                                }),
                        ])->visible(fn (callable $get) => $get('plex_management_enabled')),

                        Section::make('DVR / Live TV Tuner')
                            ->description('Register this playlist as an HDHomeRun tuner in Plex for Live TV & DVR.')
                            ->collapsible()
                            ->schema([
                                Placeholder::make('plex_dvr_status')
                                    ->label('DVR Status')
                                    ->content(function ($record) {
                                        if (! $record || ! $record->isPlex()) {
                                            return new HtmlString('<span class="text-gray-400">Save integration first</span>');
                                        }
                                        if ($record->plex_dvr_id) {
                                            return new HtmlString('<span class="text-success-500 font-medium">DVR registered (ID: '.$record->plex_dvr_id.')</span>');
                                        }

                                        return new HtmlString('<span class="text-warning-500">No DVR tuner registered in Plex</span>');
                                    }),

                                Placeholder::make('plex_dvr_help')
                                    ->label('')
                                    ->content(new HtmlString(
                                        '<div class="text-sm text-gray-500 dark:text-gray-400">'
                                        .'<p>This registers the playlist\'s HDHomeRun emulation endpoint as a DVR tuner in Plex.</p>'
                                        .'<p class="mt-1">Plex will then use it for Live TV &amp; DVR, including the channel guide (EPG).</p>'
                                        .'<p class="mt-1"><strong>Requirements:</strong> The playlist must be accessible from the Plex server (same network or port-forwarded).</p>'
                                        .'</div>'
                                    )),

                                Placeholder::make('plex_dvr_tuners_list')
                                    ->label('Registered Tuners')
                                    ->content(function ($record) {
                                        $tuners = $record->plex_dvr_tuners ?? [];
                                        if (empty($tuners)) {
                                            return new HtmlString('<span class="text-gray-400 text-sm">No tuners registered yet.</span>');
                                        }
                                        $rows = collect($tuners)->map(function (array $tuner) {
                                            $uuid = $tuner['playlist_uuid'] ?? '—';
                                            $key = $tuner['device_key'] ?? '—';
                                            $name = self::resolvePlaylistName($uuid);

                                            return '<tr>'
                                                .'<td class="pr-4 py-1">'.\e($name).'</td>'
                                                .'<td class="pr-4 py-1 text-xs font-mono text-gray-400">'.\e($key).'</td>'
                                                .'</tr>';
                                        })->implode('');

                                        return new HtmlString(
                                            '<table class="text-sm w-full">'
                                            .'<thead><tr><th class="pr-4 text-left">Playlist</th><th class="pr-4 text-left">Device Key</th></tr></thead>'
                                            .'<tbody>'.$rows.'</tbody>'
                                            .'</table>'
                                        );
                                    })
                                    ->visible(fn ($record) => $record && $record->isPlex() && ! empty($record->plex_dvr_tuners)),

                                Actions::make([
                                    Action::make('addTuner')
                                        ->label(fn ($record) => $record && $record->plex_dvr_id ? 'Add Tuner' : 'Register DVR Tuner in Plex')
                                        ->icon('heroicon-o-plus-circle')
                                        ->color('success')
                                        ->requiresConfirmation()
                                        ->modalHeading('Register HDHomeRun Tuner')
                                        ->modalDescription('This will register the playlist\'s HDHR endpoint as a DVR tuner in Plex and configure the EPG guide. The HDHR URL must be reachable from your Plex server.')
                                        ->form([
                                            Select::make('playlist_uuid')
                                                ->label('Playlist')
                                                ->helperText('Select the playlist to use for HDHR/EPG endpoints.')
                                                ->options(function ($record) {
                                                    $userId = Auth::id();
                                                    $existingUuids = collect($record->plex_dvr_tuners ?? [])->pluck('playlist_uuid')->filter()->all();
                                                    $options = [];
                                                    foreach (Playlist::where('user_id', $userId)->get() as $p) {
                                                        if (! in_array($p->uuid, $existingUuids)) {
                                                            $options[$p->uuid] = "{$p->name} (Playlist)";
                                                        }
                                                    }
                                                    foreach (CustomPlaylist::where('user_id', $userId)->get() as $p) {
                                                        if (! in_array($p->uuid, $existingUuids)) {
                                                            $options[$p->uuid] = "{$p->name} (Custom)";
                                                        }
                                                    }
                                                    foreach (MergedPlaylist::where('user_id', $userId)->get() as $p) {
                                                        if (! in_array($p->uuid, $existingUuids)) {
                                                            $options[$p->uuid] = "{$p->name} (Merged)";
                                                        }
                                                    }

                                                    return $options;
                                                })
                                                ->searchable()
                                                ->live()
                                                ->afterStateUpdated(function (Set $set, ?string $state): void {
                                                    if (! $state) {
                                                        return;
                                                    }
                                                    $baseUrl = self::buildHdhrBaseUrl();
                                                    $set('hdhr_base_url', "{$baseUrl}/{$state}/hdhr");
                                                    $set('epg_url', "{$baseUrl}/{$state}/epg.xml");
                                                })
                                                ->required(),
                                            Placeholder::make('tvg_id_warning')
                                                ->content(new HtmlString('<p style="color: #f59e0b; font-weight: 600;">⚠ This playlist\'s TVG ID output is not set to "Channel Number". For HDHR/Plex DVR to match EPG correctly, set the playlist\'s "Preferred TVG ID output" to "Channel Number".</p>'))
                                                ->visible(function (Get $get): bool {
                                                    $uuid = $get('playlist_uuid');
                                                    if (! $uuid) {
                                                        return false;
                                                    }
                                                    $playlist = Playlist::where('uuid', $uuid)->first()
                                                        ?? CustomPlaylist::where('uuid', $uuid)->first()
                                                        ?? MergedPlaylist::where('uuid', $uuid)->first();

                                                    $value = $playlist->id_channel_by?->value ?? $playlist->id_channel_by ?? 'stream_id';

                                                    return $playlist && $value !== 'number';
                                                }),
                                            TextInput::make('hdhr_base_url')
                                                ->label('HDHR Base URL')
                                                ->helperText('This URL must be reachable from your Plex server. Use your machine\'s LAN IP, not localhost.')
                                                ->required(),
                                            TextInput::make('epg_url')
                                                ->label('EPG URL')
                                                ->helperText('XMLTV EPG guide URL. Must also be reachable from Plex.')
                                                ->required(),
                                            TextInput::make('dvr_country')
                                                ->label('Country Code')
                                                ->helperText('ISO country code for the DVR guide (e.g. us, de, gb).')
                                                ->default('us')
                                                ->maxLength(5)
                                                ->required(),
                                            TextInput::make('dvr_language')
                                                ->label('Language Code')
                                                ->helperText('ISO language code for the DVR guide (e.g. en, de, fr).')
                                                ->default('en')
                                                ->maxLength(5)
                                                ->required(),
                                        ])
                                        ->action(function ($record, array $data) {
                                            $service = PlexManagementService::make($record);
                                            $result = $service->addDvrDevice(
                                                $data['hdhr_base_url'],
                                                $data['epg_url'],
                                                $data['dvr_country'],
                                                $data['dvr_language'],
                                                $data['playlist_uuid'],
                                            );
                                            if ($result['success']) {
                                                Notification::make()->success()->title('Tuner Registered')->body($result['message'])->persistent()->send();
                                            } else {
                                                Notification::make()->danger()->title('Registration Failed')->body($result['message'])->persistent()->send();
                                            }
                                        })
                                        ->visible(fn ($record) => $record && $record->isPlex()),

                                    Action::make('removeTuner')
                                        ->label('Remove Tuner')
                                        ->icon('heroicon-o-minus-circle')
                                        ->color('danger')
                                        ->requiresConfirmation()
                                        ->modalHeading('Remove Tuner')
                                        ->modalDescription('Select a tuner to remove from the DVR. If it is the last tuner, the entire DVR will be removed.')
                                        ->form([
                                            Select::make('device_key')
                                                ->label('Tuner')
                                                ->options(function ($record) {
                                                    $tuners = $record->plex_dvr_tuners ?? [];

                                                    return collect($tuners)->mapWithKeys(function (array $t) {
                                                        $key = $t['device_key'] ?? '';
                                                        $name = self::resolvePlaylistName($t['playlist_uuid'] ?? '');

                                                        return [$key => "{$name} ({$key})"];
                                                    })->all();
                                                })
                                                ->required(),
                                        ])
                                        ->action(function ($record, array $data) {
                                            $service = PlexManagementService::make($record);
                                            $result = $service->removeTuner($data['device_key']);
                                            if ($result['success']) {
                                                Notification::make()->success()->title('Tuner Removed')->body($result['message'])->persistent()->send();
                                            } else {
                                                Notification::make()->danger()->title('Removal Failed')->body($result['message'])->persistent()->send();
                                            }
                                        })
                                        ->visible(fn ($record) => $record && $record->isPlex() && ! empty($record->plex_dvr_tuners)),

                                    Action::make('removeDvr')
                                        ->label('Remove Entire DVR')
                                        ->icon('heroicon-o-trash')
                                        ->color('danger')
                                        ->requiresConfirmation()
                                        ->modalHeading('Remove DVR')
                                        ->modalDescription('This will remove the entire DVR and all tuners from Plex. Live TV & DVR will no longer work.')
                                        ->action(function ($record) {
                                            $service = PlexManagementService::make($record);
                                            $result = $service->removeDvr($record->plex_dvr_id);
                                            if ($result['success']) {
                                                Notification::make()->success()->title('DVR Removed')->body($result['message'])->persistent()->send();
                                            } else {
                                                Notification::make()->danger()->title('Removal Failed')->body($result['message'])->persistent()->send();
                                            }
                                        })
                                        ->visible(fn ($record) => $record && $record->isPlex() && $record->plex_dvr_id),

                                    Action::make('refreshDvrGuide')
                                        ->label('Refresh EPG Guide')
                                        ->icon('heroicon-o-arrow-path')
                                        ->requiresConfirmation()
                                        ->modalHeading('Refresh EPG Guide')
                                        ->modalDescription('This will trigger Plex to re-fetch your EPG guide data and configure automatic refreshes.')
                                        ->action(function ($record) {
                                            if (! $record->plex_dvr_id) {
                                                Notification::make()->warning()->title('Not Configured')->body('Register a DVR tuner first.')->persistent()->send();

                                                return;
                                            }
                                            $service = PlexManagementService::make($record);
                                            $result = $service->refreshGuides();
                                            if ($result['success']) {
                                                Notification::make()->success()->title('Guide Refreshed')->body($result['message'])->persistent()->send();
                                            } else {
                                                Notification::make()->danger()->title('Refresh Failed')->body($result['message'])->persistent()->send();
                                            }
                                        })
                                        ->visible(fn ($record) => $record && $record->isPlex() && $record->plex_dvr_id),

                                    Action::make('forceSyncChannels')
                                        ->label('Force Sync Channels')
                                        ->icon('heroicon-o-arrow-path-rounded-square')
                                        ->color('gray')
                                        ->action(function ($record) {
                                            $service = PlexManagementService::make($record);
                                            $result = $service->syncDvrChannels();
                                            if ($result['success']) {
                                                $title = ($result['changed'] ?? false) ? 'Channels Synced' : 'Already In Sync';
                                                Notification::make()->success()->title($title)->body($result['message'])->persistent()->send();
                                            } else {
                                                Notification::make()->danger()->title('Sync Failed')->body($result['message'])->persistent()->send();
                                            }
                                        })
                                        ->visible(fn ($record) => $record && $record->isPlex() && ! empty($record->plex_dvr_tuners)),
                                ])->fullWidth(),
                            ])
                            ->visible(fn (callable $get) => $get('plex_management_enabled')),

                        Section::make('Libraries & Scanning')
                            ->description('Manage Plex libraries and trigger scans.')
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                Placeholder::make('plex_libraries')
                                    ->label('Libraries')
                                    ->content(function ($record) {
                                        if (! $record || ! $record->isPlex()) {
                                            return 'Save integration first';
                                        }
                                        try {
                                            $service = PlexManagementService::make($record);
                                            $result = $service->getAllLibraries();
                                            if ($result['success'] && $result['data']->isNotEmpty()) {
                                                $rows = $result['data']->map(function ($lib) {
                                                    $status = $lib['refreshing'] ? '<span class="text-warning-500">Scanning...</span>' : '<span class="text-success-500">Ready</span>';

                                                    return '<tr><td class="pr-4">'.$lib['title'].'</td><td class="pr-4">'.ucfirst($lib['type']).'</td><td>'.$status.'</td></tr>';
                                                })->implode('');

                                                return new HtmlString('<table class="text-sm"><thead><tr><th class="pr-4 text-left">Name</th><th class="pr-4 text-left">Type</th><th class="text-left">Status</th></tr></thead><tbody>'.$rows.'</tbody></table>');
                                            }

                                            return 'No libraries found';
                                        } catch (\Exception $e) {
                                            return 'Error: '.$e->getMessage();
                                        }
                                    }),

                                Actions::make([
                                    Action::make('scanAllLibraries')
                                        ->label('Scan All Libraries')
                                        ->icon('heroicon-o-magnifying-glass')
                                        ->requiresConfirmation()
                                        ->action(function ($record) {
                                            $service = PlexManagementService::make($record);
                                            $result = $service->scanAllLibraries();
                                            if ($result['success']) {
                                                Notification::make()->success()->title('Scan Started')->body($result['message'])->persistent()->send();
                                            } else {
                                                Notification::make()->danger()->title('Scan Failed')->body($result['message'])->persistent()->send();
                                            }
                                        })
                                        ->visible(fn ($record) => $record && $record->isPlex()),
                                ])->fullWidth(),
                            ])
                            ->visible(fn (callable $get) => $get('plex_management_enabled')),

                        Section::make('Recordings / DVR Subscriptions')
                            ->description('View and manage Plex DVR recording subscriptions.')
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                Placeholder::make('plex_recordings')
                                    ->label('Scheduled Recordings')
                                    ->content(function ($record) {
                                        if (! $record || ! $record->isPlex()) {
                                            return 'Save integration first';
                                        }
                                        try {
                                            $service = PlexManagementService::make($record);
                                            $result = $service->getRecordings();
                                            if ($result['success'] && $result['data']->isNotEmpty()) {
                                                $rows = $result['data']->map(function ($rec) {
                                                    return '<tr><td class="pr-4">'.$rec['title'].'</td><td class="pr-4">'.$rec['type'].'</td><td>'.($rec['created_at'] ?? '—').'</td></tr>';
                                                })->implode('');

                                                return new HtmlString('<table class="text-sm"><thead><tr><th class="pr-4 text-left">Title</th><th class="pr-4 text-left">Type</th><th class="text-left">Created</th></tr></thead><tbody>'.$rows.'</tbody></table>');
                                            }

                                            return 'No recordings found';
                                        } catch (\Exception $e) {
                                            return 'Error: '.$e->getMessage();
                                        }
                                    }),
                            ])
                            ->visible(fn (callable $get) => $get('plex_management_enabled')),
                    ])
                    ->visible(fn (callable $get) => ! $creating && $get('type') === 'plex'),
            ],
            'Networks' => [
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
                            ->hintAction(
                                Action::make('qrCode')
                                    ->label('QR Code')
                                    ->icon('heroicon-o-qr-code')
                                    ->modalHeading('Integration Playlist URL')
                                    ->modalContent(fn ($record) => view('components.qr-code-display', ['text' => $record ? route('networks.playlist', ['user' => $record->user_id]) : 'Save integration first']))
                                    ->modalWidth('sm')
                                    ->modalSubmitAction(false)
                                    ->modalCancelAction(fn ($action) => $action->label('Close'))
                                    ->visible(fn ($record) => $record?->user_id !== null)
                            )
                            ->hint(fn ($record) => $record ? view('components.copy-to-clipboard', ['text' => route('networks.playlist', ['user' => $record->user_id]), 'position' => 'left']) : null)
                            ->helperText('M3U playlist containing all your Networks as live channels'),

                        TextInput::make('networks_epg_url')
                            ->label('Networks EPG URL')
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(fn ($record) => $record
                                ? route('networks.epg', ['user' => $record->user_id])
                                : 'Save integration first'
                            )
                            ->hintAction(
                                Action::make('qrCode')
                                    ->label('QR Code')
                                    ->icon('heroicon-o-qr-code')
                                    ->modalHeading('Integration EPG URL')
                                    ->modalContent(fn ($record) => view('components.qr-code-display', ['text' => $record ? route('networks.epg', ['user' => $record->user_id]) : 'Save integration first']))
                                    ->modalWidth('sm')
                                    ->modalSubmitAction(false)
                                    ->modalCancelAction(fn ($action) => $action->label('Close'))
                                    ->visible(fn ($record) => $record?->user_id !== null)
                            )
                            ->hint(fn ($record) => $record ? view('components.copy-to-clipboard', ['text' => route('networks.epg', ['user' => $record->user_id]), 'position' => 'left']) : null)
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
                    ->visible(! $creating),
            ],
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
                    ->description(function ($record) {
                        if ($record->playlist_id) {
                            $playlist = Playlist::find($record->playlist_id);
                            if (! $playlist) {
                                return null;
                            }

                            return new HtmlString('
                            <div class="flex items-center gap-1">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4">
                                    <path d="M12.75 4a.75.75 0 0 0-.75.75v10.5c0 .414.336.75.75.75h.5a.75.75 0 0 0 .75-.75V4.75a.75.75 0 0 0-.75-.75h-.5ZM17.75 4a.75.75 0 0 0-.75.75v10.5c0 .414.336.75.75.75h.5a.75.75 0 0 0 .75-.75V4.75a.75.75 0 0 0-.75-.75h-.5ZM3.288 4.819A1.5 1.5 0 0 0 1 6.095v7.81a1.5 1.5 0 0 0 2.288 1.277l6.323-3.906a1.5 1.5 0 0 0 0-2.552L3.288 4.819Z" />
                                </svg>
                                Playlist: '.$playlist->name.'
                            </div>');
                        }
                    })
                    ->sortable(),

                ToggleColumn::make('enabled')
                    ->label('Enabled'),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'local' => 'Local Media',
                        'webdav' => 'WebDAV',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'emby' => 'success',
                        'jellyfin' => 'info',
                        'plex' => 'warning',
                        'local' => 'gray',
                        'webdav' => 'purple',
                        default => 'gray',
                    }),

                TextColumn::make('host')
                    ->label('Server')
                    ->formatStateUsing(fn ($record): string => match ($record->type) {
                        'local' => 'Local filesystem',
                        'webdav' => "{$record->host}:{$record->port}",
                        default => "{$record->host}:{$record->port}",
                    })
                    ->toggleable()
                    ->copyable(),

                TextColumn::make('selected_library_ids')
                    ->label('Libraries')
                    ->formatStateUsing(function ($record, $state): string {
                        $available = $record->available_libraries ?? [];

                        if (empty($available)) {
                            return 'Not configured';
                        }

                        return collect($available)
                            ->where('id', '=', (string) $state)->first()['name'] ?? 'N/A';
                    })
                    ->toggleable()
                    ->badge()
                    ->color('success'),

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
                        'local' => 'Local Media',
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
                                // Auto-fetch libraries on successful connection
                                $libraries = $service->fetchLibraries();

                                if ($libraries->isNotEmpty()) {
                                    // Update the integration with available libraries
                                    $record->update([
                                        'available_libraries' => $libraries->toArray(),
                                    ]);

                                    Notification::make()
                                        ->success()
                                        ->title('Connection Successful')
                                        ->body("Connected to {$result['server_name']} (v{$result['version']}). Found {$libraries->count()} libraries. Edit the integration to select which libraries to import.")
                                        ->send();
                                } else {
                                    Notification::make()
                                        ->success()
                                        ->title('Connection Successful')
                                        ->body("Connected to {$result['server_name']} (v{$result['version']}). No movie or TV show libraries found.")
                                        ->send();
                                }
                            } else {
                                Notification::make()
                                    ->danger()
                                    ->title('Connection Failed')
                                    ->body($result['message'])
                                    ->send();
                            }
                        }),

                    Action::make('refreshLibraries')
                        ->label('Refresh Libraries')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function (MediaServerIntegration $record) {
                            $service = MediaServerService::make($record);
                            $libraries = $service->fetchLibraries();

                            if ($libraries->isNotEmpty()) {
                                // Preserve existing selections where possible
                                $existingSelections = $record->selected_library_ids ?? [];
                                $newLibraryIds = $libraries->pluck('id')->toArray();

                                // Filter selections to only include libraries that still exist
                                $validSelections = array_intersect($existingSelections, $newLibraryIds);

                                $record->update([
                                    'available_libraries' => $libraries->toArray(),
                                    'selected_library_ids' => array_values($validSelections),
                                ]);

                                $removedCount = count($existingSelections) - count($validSelections);
                                $message = "Found {$libraries->count()} libraries.";
                                if ($removedCount > 0) {
                                    $message .= " {$removedCount} previously selected libraries no longer exist.";
                                }

                                Notification::make()
                                    ->success()
                                    ->title('Libraries Refreshed')
                                    ->body($message)
                                    ->send();
                            } else {
                                Notification::make()
                                    ->warning()
                                    ->title('No Libraries Found')
                                    ->body('No movie or TV show libraries were found on the server.')
                                    ->send();
                            }
                        }),

                    Action::make('viewPlaylist')
                        ->label('View Playlist')
                        ->icon('heroicon-o-eye')
                        ->url(fn ($record) => $record->playlist_id
                            ? PlaylistResource::getUrl('view', ['record' => $record->playlist_id])
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

                    Action::make('reset')
                        ->label('Reset status')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->action(function (MediaServerIntegration $record) {
                            $record->update([
                                'status' => 'idle',
                                'progress' => 0,
                                'movie_progress' => 0,
                                'series_progress' => 0,
                                'total_movies' => 0,
                                'total_series' => 0,
                            ]);
                        })
                        ->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Media server status reset')
                                ->body('Media server status has been reset.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-o-arrow-uturn-left')
                        ->modalDescription('Reset media server status so it can be synced again. Only perform this action if you are having problems with the media server syncing.')
                        ->modalSubmitActionLabel('Yes, reset now'),

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

                    BulkAction::make('reset')
                        ->label('Reset status')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                $record->update([
                                    'status' => 'idle',
                                    'progress' => 0,
                                    'movie_progress' => 0,
                                    'series_progress' => 0,
                                    'total_movies' => 0,
                                    'total_series' => 0,
                                ]);
                            }
                        })
                        ->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Media server status reset')
                                ->body('Status has been reset for the selected media servers.')
                                ->duration(3000)
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-o-arrow-uturn-left')
                        ->modalDescription('Reset status for the selected media servers so they can be synced again.')
                        ->modalSubmitActionLabel('Yes, reset now'),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MoviesRelationManager::class,
            RelationManagers\SeriesRelationManager::class,
        ];
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

    private static function getLocalActions(): array
    {
        return [
            Action::make('scanLocalMedia')
                ->label('Scan & Discover Libraries')
                ->icon('heroicon-o-folder-open')
                ->action(function (callable $get, callable $set, $livewire) {
                    $paths = $get('local_media_paths') ?? [];

                    if (empty($paths)) {
                        Notification::make()
                            ->warning()
                            ->title('No Paths Configured')
                            ->body('Please add at least one media library path before scanning.')
                            ->send();

                        return;
                    }

                    // Create temporary model from form state
                    $tempIntegration = new MediaServerIntegration([
                        'type' => 'local',
                        'local_media_paths' => $paths,
                        'scan_recursive' => $get('scan_recursive') ?? true,
                        'video_extensions' => $get('video_extensions') ?? null,
                    ]);

                    // Test connection (validates paths)
                    $service = MediaServerService::make($tempIntegration);
                    $result = $service->testConnection();

                    if (! $result['success']) {
                        Notification::make()
                            ->danger()
                            ->title('Path Validation Failed')
                            ->body($result['message'])
                            ->send();

                        return;
                    }

                    // Fetch libraries (returns the configured paths with item counts)
                    $libraries = $service->fetchLibraries();

                    if ($libraries->isEmpty()) {
                        Notification::make()
                            ->warning()
                            ->title('No Media Found')
                            ->body('No video files were found in the configured paths.')
                            ->send();
                        $set('available_libraries', []);

                        return;
                    }

                    // Store libraries in form state
                    $set('available_libraries', $libraries->toArray());

                    // Auto-select all libraries for local media
                    $libraryIds = $libraries->pluck('id')->toArray();
                    $set('selected_library_ids', $libraryIds);

                    Notification::make()
                        ->success()
                        ->title('Scan Complete')
                        ->body($result['message'])
                        ->send();
                }),
        ];
    }

    private static function getServerActions(): array
    {
        return [
            Action::make('testAndDiscover')
                ->label('Test Connection & Discover Libraries')
                ->icon('heroicon-o-signal')
                ->action(function (callable $get, callable $set, $livewire) {
                    // Create temporary model from form state
                    $values = [
                        'type' => $get('type'),
                        'host' => $get('host'),
                        'port' => $get('port'),
                        'ssl' => $get('ssl') ?? false,
                        'api_key' => $get('api_key') ?: $livewire->record?->api_key,
                    ];

                    if (array_filter($values, fn ($value) => empty($value) && ! is_bool($value))) {
                        Notification::make()
                            ->danger()
                            ->title('Validation Error')
                            ->body('Please fill in all required connection fields before testing the connection.')
                            ->send();

                        return;
                    }

                    $tempIntegration = new MediaServerIntegration($values);

                    // Test connection
                    $service = MediaServerService::make($tempIntegration);
                    $result = $service->testConnection();

                    if (! $result['success']) {
                        Notification::make()
                            ->danger()
                            ->title('Connection Failed')
                            ->body($result['message'])
                            ->send();

                        return;
                    }

                    // Fetch libraries
                    $libraries = $service->fetchLibraries();

                    if ($libraries->isEmpty()) {
                        Notification::make()
                            ->warning()
                            ->title('Connected but No Libraries Found')
                            ->body("Connected to {$result['server_name']}. No movie or TV show libraries were found.")
                            ->send();
                        $set('available_libraries', []);

                        return;
                    }

                    // Store libraries in form state
                    $set('available_libraries', $libraries->toArray());

                    // Preserve existing selections if valid
                    $existingSelections = $get('selected_library_ids') ?? [];
                    $newLibraryIds = $libraries->pluck('id')->toArray();
                    $validSelections = array_intersect($existingSelections, $newLibraryIds);
                    $set('selected_library_ids', array_values($validSelections));

                    Notification::make()
                        ->success()
                        ->title('Connection Successful')
                        ->body("Connected to {$result['server_name']} (v{$result['version']}). Found {$libraries->count()} libraries.")
                        ->send();
                }),
        ];
    }
}
