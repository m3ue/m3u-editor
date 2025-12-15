<?php

namespace App\Filament\Pages;

use App\Jobs\RestartQueue;
use App\Models\StreamProfile;
use App\Rules\CheckIfUrlOrLocalPath;
use App\Rules\Cron;
use App\Services\FfmpegCodecService;
use App\Services\M3uProxyService;
use App\Services\PlaylistService;
use App\Services\ProxyService;
use App\Settings\GeneralSettings;
use Cron\CronExpression;
use Dom\Text;
use Exception;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionProperty;

class Preferences extends SettingsPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $settings = GeneralSettings::class;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    /**
     * Check if the user can access this page.
     * Only admin users can access the Preferences page.
     */
    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    protected function getActions(): array
    {
        return [
            Action::make('Reset Queue')
                ->label('Reset Queue')
                ->action(function () {
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new RestartQueue);
                })
                ->after(function () {
                    Notification::make()
                        ->success()
                        ->title('Queue reset')
                        ->body('The queue workers have been restarted and any pending jobs flushed. You may need to manually sync any Playlists or EPGs that were in progress.')
                        ->duration(10000)
                        ->send();
                })
                ->color('gray')
                ->requiresConfirmation()
                ->icon('heroicon-o-exclamation-triangle')
                ->modalIcon('heroicon-o-exclamation-triangle')
                ->modalDescription('Resetting the queue will restart the queue workers and flush any pending jobs. Any syncs or background processes will be stopped and removed. Only perform this action if you are having sync issues.')
                ->modalSubmitActionLabel('I understand, reset now'),
            Action::make('Clear Logo Cache')
                ->label('Clear Logo Cache')
                ->action(fn() => Artisan::call('app:logo-cleanup --force --all'))
                ->after(function () {
                    Notification::make()
                        ->success()
                        ->title('Logo cache cleared')
                        ->body('The logo cache has been cleared successfully.')
                        ->duration(10000)
                        ->send();
                })
                ->color('gray')
                ->requiresConfirmation()
                ->icon('heroicon-o-exclamation-triangle')
                ->modalIcon('heroicon-o-exclamation-triangle')
                ->modalDescription('Clearing the logo cache will remove all cached logo images. This action cannot be undone.')
                ->modalSubmitActionLabel('I understand, clear logo cache now'),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $m3uPublicUrl = rtrim(config('proxy.m3u_proxy_public_url'), '/');
        $m3uToken = config('proxy.m3u_proxy_token', null);
        if (empty($m3uPublicUrl)) {
            $m3uPublicUrl = url('/m3u-proxy');
        }
        $m3uProxyDocs = $m3uPublicUrl . '/docs';

        // Setup the service
        $service = new M3uProxyService();
        $mode = $service->mode();
        $embedded = $mode === 'embedded';

        $vodExample = PlaylistService::getVodExample();
        $seriesExample = PlaylistService::getEpisodeExample();

        return $schema
            ->components([
                Tabs::make()
                    ->persistTabInQueryString()
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Appearance')
                            ->schema([
                                Section::make('Layout & Display Options')
                                    ->schema([
                                        Grid::make()
                                            ->columnSpanFull()
                                            ->columns(2)
                                            ->schema([
                                                Toggle::make('show_breadcrumbs')
                                                    ->label('Show breadcrumbs')
                                                    ->helperText('Show breadcrumbs under the page titles'),
                                                Toggle::make('output_wan_address')
                                                    ->label('Output WAN address in menu')
                                                    ->helperText('When enabled, the application will output the WAN address of the server m3u-editor is currently running on.')
                                                    ->default(function () {
                                                        return config('dev.show_wan_details') !== null
                                                            ? (bool) config('dev.show_wan_details')
                                                            : false;
                                                    })
                                                    ->afterStateHydrated(function (Toggle $component, $state) {
                                                        if (config('dev.show_wan_details') !== null) {
                                                            $component->state((bool) config('dev.show_wan_details'));
                                                        }
                                                    })->disabled(fn() => config('dev.show_wan_details') !== null)
                                                    ->hint(fn() => config('dev.show_wan_details') !== null ? 'Already set by environment variable!' : null)
                                                    ->dehydrated(fn() => config('dev.show_wan_details') === null),
                                            ]),
                                        Grid::make()
                                            ->columnSpanFull()
                                            ->columns(2)
                                            ->schema([
                                                Select::make('navigation_position')
                                                    ->label('Navigation position')
                                                    ->helperText('Choose the position of primary navigation')
                                                    ->options([
                                                        'left' => 'Left',
                                                        'top' => 'Top',
                                                    ]),
                                                Select::make('content_width')
                                                    ->label('Max width of the page content')
                                                    ->options([
                                                        Width::ScreenMedium->value => 'Medium',
                                                        Width::ScreenLarge->value => 'Large',
                                                        Width::ScreenExtraLarge->value => 'XL',
                                                        Width::ScreenTwoExtraLarge->value => '2XL',
                                                        Width::Full->value => 'Full',
                                                    ]),
                                            ]),
                                    ]),
                            ]),
                        Tab::make('Proxy')
                            ->schema([
                                Section::make('M3U Proxy')
                                    ->description('m3u proxy integration is enabled and will be used to proxy all streams when proxy is enabled')
                                    ->columnSpanFull()
                                    ->columns(4)
                                    ->schema([
                                        Fieldset::make('URL resolution')
                                            ->schema([
                                                Toggle::make('m3u_proxy_public_url_auto_resolve')
                                                    ->label('Resolve proxy public URL dynamically at request time')
                                                    ->columnSpanFull()
                                                    ->hintIcon(
                                                        'heroicon-m-question-mark-circle',
                                                        tooltip: 'When enabled, the application will resolve the public-facing proxy URL using the incoming request host/scheme instead of the M3U_PROXY_PUBLIC_URL variable.'
                                                    )
                                                    ->helperText('Useful for multi-host access (VPN/Tailscale/etc.)')
                                                    ->default(false),
                                            ]),

                                        Fieldset::make('Proxy URL override')
                                            ->schema([
                                                TextInput::make('url_override')
                                                    ->label('Override URL')
                                                    ->columnSpanFull()
                                                    ->url()
                                                    ->live()
                                                    ->hintIcon(
                                                        'heroicon-m-question-mark-circle',
                                                        tooltip: 'If you would like the proxied streams to use a different base URL than the configured app url. Useful for local network access or when using a different domain for streaming.'
                                                    )
                                                    ->disabled(fn() => ! empty(config('proxy.url_override')))
                                                    ->hint(fn() => ! empty(config('proxy.url_override')) ? 'Already set by environment variable!' : null)
                                                    ->prefixIcon('heroicon-m-link')
                                                    ->disabled(fn() => ! empty(config('proxy.url_override')))
                                                    ->hint(fn() => ! empty(config('proxy.url_override')) ? 'Already set by environment variable!' : null)
                                                    ->default(fn() => ! empty(config('proxy.url_override')) ? config('proxy.url_override') : '')
                                                    ->afterStateHydrated(function (TextInput $component, $state) {
                                                        if (! empty(config('proxy.url_override'))) {
                                                            $component->state((string) config('proxy.url_override'));
                                                        }
                                                    })
                                                    ->dehydrated(fn() => empty(config('proxy.url_override')))
                                                    ->placeholder('http://192.168.0.123:36400')
                                                    ->helperText(fn() => 'Leave empty to use the configured app url (default).'),

                                                Toggle::make('url_override_include_logos')
                                                    ->label('Include logos in proxy URL override')
                                                    ->columnSpanFull()
                                                    ->hintIcon(
                                                        'heroicon-m-question-mark-circle',
                                                        tooltip: 'This is useful for Plex which need HTTPS for logo images. When using a domain with HTTPS for the frontend, but proxy URL override points to a local HTTP address, Plex may not load the logos due to HTTPS requirements. By enabling this option you can keep the stream proxy override for local access while logos still use the HTTPS domain URL that Plex requires.'
                                                    )
                                                    ->disabled(fn() => config('proxy.url_override_include_logos') !== null)
                                                    ->hint(fn() => config('proxy.url_override_include_logos') !== null ? 'Already set by environment variable!' : null)
                                                    ->default(fn() => config('proxy.url_override_include_logos') !== null)
                                                    ->afterStateHydrated(function (Toggle $component, $state) {
                                                        if (config('proxy.url_override_include_logos') !== null) {
                                                            $component->state((bool)  config('proxy.url_override_include_logos'));
                                                        }
                                                    })
                                                    ->hidden(fn($get) => empty(config('proxy.url_override')) && empty($get('url_override')))
                                                    ->dehydrated(fn() => empty(config('proxy.url_override_include_logos')))
                                                    ->helperText('Whether or not to use the URL override for logos and images too (default is enabled).'),
                                            ]),

                                        Fieldset::make('Failover settings')
                                            ->schema([
                                                Toggle::make('enable_failover_resolver')
                                                    ->label('Enable advanced failover logic')
                                                    ->columnSpanFull()
                                                    ->hintIcon(
                                                        'heroicon-m-question-mark-circle',
                                                        tooltip: 'When enabled, the proxy will make a call to the editor to determine which failover to use based on available capacity. When disabled, a list of failover URLs will be sent to the proxy and it will loop through them without any capacity checks when a stream failure occurs.'
                                                    )
                                                    ->live()
                                                    ->disabled(fn() => ! empty(config('proxy.resolver_url')))
                                                    ->hint(fn() => ! empty(config('proxy.resolver_url')) ? 'Already set by environment variable!' : null)
                                                    ->default(false)
                                                    ->afterStateHydrated(function (Toggle $component, $state) {
                                                        if (! empty(config('proxy.resolver_url'))) {
                                                            $component->state((bool) config('proxy.resolver_url'));
                                                        }
                                                    })
                                                    ->dehydrated(fn() => empty(config('proxy.resolver_url')))
                                                    ->helperText('Use to enable advanced failover checking and resolution.'),

                                                TextInput::make('failover_resolver_url')
                                                    ->label('Failover Resolver URL')
                                                    ->columnSpanFull()
                                                    ->url()
                                                    ->live()
                                                    ->prefixIcon('heroicon-m-link')
                                                    ->disabled(fn() => ! empty(config('proxy.resolver_url')))
                                                    ->hint(fn() => ! empty(config('proxy.resolver_url')) ? 'Already set by environment variable!' : null)
                                                    ->default(fn() => ! empty(config('proxy.resolver_url')) ? config('proxy.resolver_url') : '')
                                                    ->afterStateHydrated(function (TextInput $component, $state) {
                                                        if (! empty(config('proxy.resolver_url'))) {
                                                            $component->state((string) config('proxy.resolver_url'));
                                                        }
                                                    })
                                                    ->required(fn($get) => !! $get('enable_failover_resolver'))
                                                    ->hidden(fn($get) => ! $get('enable_failover_resolver'))
                                                    ->dehydrated(fn() => empty(config('proxy.resolver_url')))
                                                    ->placeholder(fn() => $embedded ? 'http://127.0.0.1:' . config('app.port') : 'http://m3u-editor:36400')
                                                    ->helperText(fn() => $embedded
                                                        ? 'For embedded mode, you should use localhost, e.g.: "http://127.0.0.1:36400" or "http://localhost:36400".'
                                                        : 'Domain the proxy can use to access the editor for faillover resolution, e.g.: "http://m3u-editor:36400", "http://192.168.0.101:36400", "http://your-domain.dev", etc.'),

                                                Action::make('test_failover_connection')
                                                    ->label('Test failover resolver connection')
                                                    ->icon('heroicon-m-signal')
                                                    ->action(function ($get) use ($service) {
                                                        $configUrl = config('proxy.resolver_url');
                                                        $url = $configUrl ?? $get('failover_resolver_url');
                                                        $url = rtrim($url, '/');
                                                        $result = $service->testResolver($url);

                                                        if ($result['success']) {
                                                            Notification::make()
                                                                ->success()
                                                                ->title('Connection Successful')
                                                                ->body(Str::markdown(
                                                                    "**Proxy can reach the editor!**\n\n" .
                                                                        "URL tested: `{$result['url_tested']}`\n\n"
                                                                ))
                                                                ->duration(8000)
                                                                ->send();
                                                        } else {
                                                            Notification::make()
                                                                ->danger()
                                                                ->title('Connection Failed')
                                                                ->body(Str::markdown(
                                                                    "**The proxy cannot reach the editor**\n\n" .
                                                                        $result['message'] . "\n\n" .
                                                                        "Please verify the Failover Resolver URL is correct and accessible from the proxy container/service."
                                                                ))
                                                                ->duration(10000)
                                                                ->send();
                                                        }
                                                    })->hidden(fn($get) => ! $get('enable_failover_resolver')),
                                            ]),

                                        Fieldset::make('In-app player transcoding settings')
                                            ->schema([
                                                Select::make('default_stream_profile_id')
                                                    ->label('Default Live Transcoding Profile')
                                                    ->columnSpan(2)
                                                    ->searchable()
                                                    ->options(function () {
                                                        return StreamProfile::where('user_id', auth()->id())->pluck('name', 'id');
                                                    })
                                                    ->hintAction(
                                                        Action::make('manage_profiles')
                                                            ->label('Manage Profiles')
                                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                                            ->iconPosition('after')
                                                            ->size('sm')
                                                            ->url('/stream-profiles')
                                                            ->openUrlInNewTab(false)
                                                    )
                                                    ->helperText('The default transcoding profile used for the in-app player for Live content. Leave empty to disable transcoding (some streams may not be playable in the player).'),
                                                Select::make('default_vod_stream_profile_id')
                                                    ->label('VOD and Series Transcoding Profile')
                                                    ->columnSpan(2)
                                                    ->searchable()
                                                    ->options(function () {
                                                        return StreamProfile::where('user_id', auth()->id())->pluck('name', 'id');
                                                    })
                                                    ->hintAction(
                                                        Action::make('manage_profiles')
                                                            ->label('Manage Profiles')
                                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                                            ->iconPosition('after')
                                                            ->size('sm')
                                                            ->url('/stream-profiles')
                                                            ->openUrlInNewTab(false)
                                                    )
                                                    ->helperText('The default transcoding profile used for the in-app player for VOD/Series content. Leave empty to disable transcoding (some streams may not be playable in the player).'),
                                            ]),

                                        Action::make('test_connection')
                                            ->color('gray')
                                            ->label('Test m3u proxy connection')
                                            ->icon('heroicon-m-signal')
                                            ->action(function () use ($service, $mode) {
                                                try {
                                                    $result = $service->getProxyInfo();

                                                    if ($result['success']) {
                                                        $info = $result['info'];

                                                        // Build a nice detailed message
                                                        $mode = ucfirst($mode);
                                                        $details = "**Version:** {$info['version']}\n\n";
                                                        if ($service->mode() === 'external') {
                                                            $details .= "**Deployment Mode:** ✅ {$mode}\n\n";
                                                            $details .= " Standalone external proxy service\n\n";
                                                        } else {
                                                            $details .= "**Deployment Mode:** ⚠️ {$mode}\n\n";
                                                            $details .= " Embedded proxy service\n\n";
                                                        }

                                                        // Hardware Acceleration
                                                        $hwStatus = $info['hardware_acceleration']['enabled'] ? '✅ Enabled' : '❌ Disabled';
                                                        $details .= "**Hardware Acceleration:** {$hwStatus}\n";
                                                        if ($info['hardware_acceleration']['enabled']) {
                                                            $details .= "- Type: {$info['hardware_acceleration']['type']}\n";
                                                            $details .= "- Device: {$info['hardware_acceleration']['device']}\n";
                                                        }
                                                        $details .= "\n";

                                                        // FFmpeg Version
                                                        $ffmpegVersion = $info['ffmpeg_version'] ?? 'Unknown';
                                                        $details .= "**FFmpeg Version:** \n\n{$ffmpegVersion}\n\n";

                                                        // Transcoding is available in all modes
                                                        $details .= "**Transcoding:** ✅ Available\n";
                                                        $details .= "\n";

                                                        // Redis Pooling
                                                        $poolingEnabled = $info['redis']['pooling_enabled'];
                                                        $redisStatus = $poolingEnabled ? '✅ Enabled' : '❌ Disabled';
                                                        $details .= "**Redis Pooling:** {$redisStatus}\n";
                                                        if ($poolingEnabled) {
                                                            $details .= "- Max clients per stream: {$info['redis']['max_clients_per_stream']}\n";
                                                            $details .= "- Sharing strategy: {$info['redis']['sharing_strategy']}\n";
                                                        }
                                                        $details .= "\n";

                                                        // Ignore this for now, not sure if it will confuse...
                                                        // // Transcoding Profiles
                                                        // $profileCount = count($info['transcoding']['profiles']);
                                                        // $details .= "**Transcoding Profiles:** {$profileCount} available\n";
                                                        // $details .= "- " . implode(', ', array_keys($info['transcoding']['profiles']));

                                                        Notification::make()
                                                            ->title('Connection Successful')
                                                            ->body(Str::markdown($details))
                                                            ->success()
                                                            ->persistent()
                                                            ->send();
                                                    } else {
                                                        Notification::make()
                                                            ->title('Connection Failed')
                                                            ->body($result['error'] ?? 'Could not connect to the m3u proxy instance. Please check the URL and ensure the service is running.')
                                                            ->danger()
                                                            ->send();
                                                    }
                                                } catch (Exception $e) {
                                                    Notification::make()
                                                        ->title('Connection Failed')
                                                        ->body('Could not connect to the m3u proxy instance. ' . $e->getMessage())
                                                        ->danger()
                                                        ->send();
                                                }
                                            }),
                                        Action::make('get_api_key')
                                            ->color('gray')
                                            ->label('Get m3u proxy API key')
                                            ->icon('heroicon-m-key')
                                            ->action(function () use ($m3uToken) {
                                                Notification::make()
                                                    ->title('Your m3u proxy API key')
                                                    ->body($m3uToken)
                                                    ->info()
                                                    ->send();
                                            })->hidden(! $m3uToken),
                                        Action::make('m3u_proxy_info')
                                            ->label('m3u proxy API docs')
                                            ->url($m3uProxyDocs)
                                            ->openUrlInNewTab(true)
                                            ->icon('heroicon-m-arrow-top-right-on-square'),
                                        Action::make('github')
                                            ->label('m3u proxy GitHub')
                                            ->url('https://github.com/sparkison/m3u-proxy')
                                            ->openUrlInNewTab(true)
                                            ->icon('heroicon-m-arrow-top-right-on-square'),
                                    ]),
                                Section::make('MediaFlow Proxy')
                                    ->description('If you have MediaFlow Proxy installed, you can use it to proxy your m3u editor playlist streams. When enabled, the app will auto-generate URLs for you to use via MediaFlow Proxy.')
                                    ->columnSpan('full')
                                    ->columns(3)
                                    ->collapsible()
                                    ->collapsed(true)
                                    ->headerActions([
                                        Action::make('mfproxy_git')
                                            ->label('GitHub')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->color('gray')
                                            ->size('sm')
                                            ->url('https://github.com/mhdzumair/mediaflow-proxy')
                                            ->openUrlInNewTab(true),
                                    ])
                                    ->schema([
                                        TextInput::make('mediaflow_proxy_url')
                                            ->label('Proxy URL')
                                            ->columnSpan(1)
                                            ->placeholder('socks5://user:pass@host:port or http://user:pass@host:port'),
                                        TextInput::make('mediaflow_proxy_port')
                                            ->label('Proxy Port (Alternative)')
                                            ->numeric()
                                            ->columnSpan(1)
                                            ->helperText('Alternative port if not specified in URL. Not commonly used.'),

                                        TextInput::make('mediaflow_proxy_password')
                                            ->label('Proxy Password (Alternative)')
                                            ->columnSpan(1)
                                            ->password()
                                            ->revealable()
                                            ->helperText('Alternative password if not specified in URL. Not commonly used.'),
                                        Toggle::make('mediaflow_proxy_playlist_user_agent')
                                            ->label('Use playlist user agent')
                                            ->inline(false)
                                            ->live()
                                            ->label('Use Proxy User Agent for Playlists (M3U8/MPD)')
                                            ->helperText('If enabled, the User Agent will also be used for fetching playlist files. Otherwise, the default FFmpeg User Agent is used for playlists.'),
                                        TextInput::make('mediaflow_proxy_user_agent')
                                            ->label('Proxy User Agent for Media Streams')
                                            ->placeholder('VLC/3.0.21 LibVLC/3.0.21')
                                            ->columnSpan(2),
                                    ]),
                            ]),

                        Tab::make('Sync Options')
                            ->schema([
                                Section::make('Provider Request Delay')
                                    ->description('Add a delay between requests to providers to avoid rate limiting.')
                                    ->columnSpan('full')
                                    ->columns(1)
                                    ->collapsible(false)
                                    ->schema([
                                        Toggle::make('enable_provider_request_delay')
                                            ->label('Enable request delay')
                                            ->live()
                                            ->helperText('When enabled, a delay will be added between requests to the provider during playlist and EPG syncs.'),
                                        TextInput::make('provider_request_delay_ms')
                                            ->label('Request delay (milliseconds)')
                                            ->integer()
                                            ->minValue(100)
                                            ->maxValue(10000)
                                            ->step(100)
                                            ->suffix('ms')
                                            ->hidden(fn($get) => ! $get('enable_provider_request_delay'))
                                            ->helperText('Delay in milliseconds between requests. Recommended: 500-2000ms. Higher values reduce load on provider but increase sync time.'),
                                        TextInput::make('provider_max_concurrent_requests')
                                            ->label('Max concurrent requests')
                                            ->integer()
                                            ->minValue(1)
                                            ->maxValue(10)
                                            ->default(2)
                                            ->hidden(fn($get) => ! $get('enable_provider_request_delay'))
                                            ->helperText('Maximum number of simultaneous requests to the provider. Lower values (1-2) are safer but slower. Set to 1 to process requests sequentially.'),
                                    ]),
                                Section::make('Sync Invalidation')
                                    ->description('Prevent sync from proceeding if conditions are met.')
                                    ->columnSpan('full')
                                    ->columns(1)
                                    ->collapsible(false)
                                    ->schema([
                                        Toggle::make('invalidate_import')
                                            ->label('Enable import invalidation')
                                            ->disabled(fn() => ! empty(config('dev.invalidate_import')))
                                            ->hint(fn() => ! empty(config('dev.invalidate_import')) ? 'Already set by environment variable!' : null)
                                            ->default(function () {
                                                return ! empty(config('dev.invalidate_import')) ? (bool) config('dev.invalidate_import') : false;
                                            })
                                            ->afterStateHydrated(function (Toggle $component, $state) {
                                                if (! empty(config('dev.invalidate_import'))) {
                                                    $component->state((bool) config('dev.invalidate_import'));
                                                }
                                            })
                                            ->dehydrated(fn() => empty(config('dev.invalidate_import')))
                                            ->helperText('Invalidate Playlist sync if conditon met.'),
                                        TextInput::make('invalidate_import_threshold')
                                            ->label('Import invalidation threshold')
                                            ->suffixIcon(fn() => ! empty(config('dev.invalidate_import_threshold')) ? 'heroicon-m-lock-closed' : null)
                                            ->disabled(fn() => ! empty(config('dev.invalidate_import_threshold')))
                                            ->hint(fn() => ! empty(config('dev.invalidate_import_threshold')) ? 'Already set by environment variable!' : null)
                                            ->dehydrated(fn() => empty(config('dev.invalidate_import_threshold')))
                                            ->placeholder(fn() => empty(config('dev.invalidate_import_threshold')) ? 100 : config('dev.invalidate_import_threshold'))
                                            ->numeric()
                                            ->helperText('If the current sync will have less channels than the current channel count (less this value), the sync will be invalidated and canceled.'),
                                    ]),
                                Section::make('Series stream file settings')
                                    ->description('Generate .strm files and sync them to a local file path. Options can be overriden per Series on the Series edit page.')
                                    ->columnSpan('full')
                                    ->columns(1)
                                    ->collapsible(false)
                                    ->schema([
                                        Toggle::make('stream_file_sync_enabled')
                                            ->live()
                                            ->label('Enable .strm file generation'),
                                        TextInput::make('stream_file_sync_location')
                                            ->label('Series Sync Location')
                                            ->live()
                                            ->rules([new CheckIfUrlOrLocalPath(localOnly: true, isDirectory: true)])
                                            ->helperText(function ($get) use ($seriesExample) {
                                                $path = $get('stream_file_sync_location') ?? '';
                                                $pathStructure = $get('stream_file_sync_path_structure') ?? [];
                                                $filenameMetadata = $get('stream_file_sync_filename_metadata') ?? [];
                                                $tmdbIdFormat = $get('stream_file_sync_tmdb_id_format') ?? 'square';

                                                // Build path preview
                                                $preview = 'Preview: ' . $path;

                                                if (in_array('category', $pathStructure)) {
                                                    $preview .= '/' . $seriesExample->category;
                                                }
                                                if (in_array('series', $pathStructure)) {
                                                    $preview .= '/' . $seriesExample->series->metadata['name'];
                                                }
                                                if (in_array('season', $pathStructure)) {
                                                    $preview .= '/Season ' . str_pad($seriesExample->info->season, 2, '0', STR_PAD_LEFT);
                                                }

                                                // Build filename preview
                                                $season = str_pad($seriesExample->info->season, 2, '0', STR_PAD_LEFT);
                                                $episode = str_pad($seriesExample->episode_num, 2, '0', STR_PAD_LEFT);
                                                $filename = PlaylistService::makeFilesystemSafe("S{$season}E{$episode} - {$seriesExample->title}", $get('stream_file_sync_replace_char') ?? ' ');

                                                // Add metadata to filename
                                                if (in_array('year', $filenameMetadata) && ! empty($seriesExample->series->release_date)) {
                                                    $year = substr($seriesExample->series->release_date, 0, 4);
                                                    $filename .= " ({$year})";
                                                }
                                                if (in_array('tmdb_id', $filenameMetadata) && ! empty($seriesExample->info->tmdb_id)) {
                                                    $bracket = $tmdbIdFormat === 'curly' ? ['{', '}'] : ['[', ']'];
                                                    $filename .= " {$bracket[0]}tmdb-{$seriesExample->info->tmdb_id}{$bracket[1]}";
                                                }

                                                $preview .= '/' . $filename . '.strm';

                                                return $preview;
                                            })
                                            ->maxLength(255)
                                            ->required()
                                            ->hidden(fn($get) => ! $get('stream_file_sync_enabled'))
                                            ->placeholder('/Series'),
                                        Forms\Components\ToggleButtons::make('stream_file_sync_path_structure')
                                            ->label('Path structure (folders)')
                                            ->live()
                                            ->multiple()
                                            ->grouped()
                                            ->options([
                                                'category' => 'Category',
                                                'series' => 'Series',
                                                'season' => 'Season',
                                            ])
                                            ->dehydrateStateUsing(function ($state, Set $set) {
                                                // Update the old boolean fields for backwards compatibility
                                                $state = $state ?? [];
                                                $set('stream_file_sync_include_category', in_array('category', $state));
                                                $set('stream_file_sync_include_series', in_array('series', $state));
                                                $set('stream_file_sync_include_season', in_array('season', $state));

                                                return $state;
                                            })
                                            ->hidden(fn($get) => ! $get('stream_file_sync_enabled')),
                                        Fieldset::make('Include Metadata')
                                            ->columnSpanFull()
                                            ->schema([
                                                Forms\Components\ToggleButtons::make('stream_file_sync_filename_metadata')
                                                    ->label('Filename metadata')
                                                    ->live()
                                                    ->inline()
                                                    ->multiple()
                                                    ->columnSpanFull()
                                                    ->options([
                                                        'year' => 'Year',
                                                        // 'resolution' => 'Resolution',
                                                        // 'codec' => 'Codec',
                                                        'tmdb_id' => 'TMDB ID',
                                                    ])
                                                    ->dehydrateStateUsing(function ($state, Set $set) {
                                                        // Update the old boolean fields for backwards compatibility
                                                        $state = $state ?? [];
                                                        $set('stream_file_sync_filename_year', in_array('year', $state));
                                                        $set('stream_file_sync_filename_resolution', in_array('resolution', $state));
                                                        $set('stream_file_sync_filename_codec', in_array('codec', $state));
                                                        $set('stream_file_sync_filename_tmdb_id', in_array('tmdb_id', $state));

                                                        return $state;
                                                    }),
                                                Forms\Components\ToggleButtons::make('stream_file_sync_tmdb_id_format')
                                                    ->label('TMDB ID format')
                                                    ->inline()
                                                    ->grouped()
                                                    ->live()
                                                    ->options([
                                                        'square' => '[square]',
                                                        'curly' => '{curly}',
                                                    ])->hidden(fn($get) => ! in_array('tmdb_id', $get('stream_file_sync_filename_metadata') ?? [])),

                                            ])
                                            ->hidden(fn($get) => ! $get('stream_file_sync_enabled')),
                                        Fieldset::make('Filename Cleansing')
                                            ->columnSpanFull()
                                            ->schema([
                                                Toggle::make('stream_file_sync_clean_special_chars')
                                                    ->label('Clean special characters')
                                                    ->helperText('Remove or replace special characters in filenames')
                                                    ->inline(false),
                                                Toggle::make('stream_file_sync_remove_consecutive_chars')
                                                    ->label('Remove consecutive replacement characters')
                                                    ->inline(false)
                                                    ->live(),
                                                Forms\Components\ToggleButtons::make('stream_file_sync_replace_char')
                                                    ->label('Replace with')
                                                    ->live()
                                                    ->inline()
                                                    ->grouped()
                                                    ->columnSpanFull()
                                                    ->options([
                                                        'space' => 'Space',
                                                        'dash' => '-',
                                                        'underscore' => '_',
                                                        'period' => '.',
                                                        'remove' => 'Remove',
                                                    ]),
                                            ])
                                            ->hidden(fn($get) => ! $get('stream_file_sync_enabled')),
                                    ]),
                                Section::make('VOD stream file settings')
                                    ->description('Generate .strm files and sync them to a local file path. Options can be overriden per VOD in the VOD edit panel.')
                                    ->columnSpan('full')
                                    ->columns(1)
                                    ->collapsible(false)
                                    ->schema([
                                        Toggle::make('vod_stream_file_sync_enabled')
                                            ->live()
                                            ->label('Enable .strm file generation'),
                                        TextInput::make('vod_stream_file_sync_location')
                                            ->label('VOD Sync Location')
                                            ->live()
                                            ->rules([new CheckIfUrlOrLocalPath(localOnly: true, isDirectory: true)])
                                            ->helperText(function ($get) use ($vodExample) {
                                                $path = $get('vod_stream_file_sync_location') ?? '';
                                                $pathStructure = $get('vod_stream_file_sync_path_structure') ?? [];
                                                $filenameMetadata = $get('vod_stream_file_sync_filename_metadata') ?? [];
                                                $tmdbIdFormat = $get('vod_stream_file_sync_tmdb_id_format') ?? 'square';

                                                // Build path preview
                                                $preview = 'Preview: ' . $path;

                                                if (in_array('group', $pathStructure)) {
                                                    $preview .= '/' . $vodExample->group;
                                                }
                                                if (in_array('title', $pathStructure)) {
                                                    $preview .= '/' . PlaylistService::makeFilesystemSafe($vodExample->title, $get('vod_stream_file_sync_replace_char') ?? ' ');
                                                }

                                                // Build filename preview
                                                $filename = PlaylistService::makeFilesystemSafe($vodExample->title, $get('vod_stream_file_sync_replace_char') ?? ' ');

                                                // Add metadata to filename (year might already be in title, but we'll add others)
                                                if (in_array('tmdb_id', $filenameMetadata) && ! empty($vodExample->info['tmdb_id'])) {
                                                    $bracket = $tmdbIdFormat === 'curly' ? ['{', '}'] : ['[', ']'];
                                                    $filename .= " {$bracket[0]}tmdb-{$vodExample->info['tmdb_id']}{$bracket[1]}";
                                                }

                                                $preview .= '/' . $filename . '.strm';

                                                return $preview;
                                            })
                                            ->maxLength(255)
                                            ->required()
                                            ->hidden(fn($get) => ! $get('vod_stream_file_sync_enabled'))
                                            ->placeholder('/VOD/movies'),
                                        Forms\Components\ToggleButtons::make('vod_stream_file_sync_path_structure')
                                            ->label('Path structure (folders)')
                                            ->live()
                                            ->inline()
                                            ->multiple()
                                            ->grouped()
                                            ->options([
                                                'group' => 'Group',
                                                'title' => 'Title',
                                            ])
                                            ->dehydrateStateUsing(function ($state, Set $set) {
                                                // Update the old boolean field for backwards compatibility
                                                $state = $state ?? [];
                                                $set('vod_stream_file_sync_include_season', in_array('group', $state));

                                                return $state;
                                            })
                                            ->hidden(fn($get) => ! $get('vod_stream_file_sync_enabled')),
                                        Fieldset::make('Include Metadata')
                                            ->columnSpanFull()
                                            ->schema([
                                                Forms\Components\ToggleButtons::make('vod_stream_file_sync_filename_metadata')
                                                    ->label('Filename metadata')
                                                    ->live()
                                                    ->inline()
                                                    ->multiple()
                                                    ->columnSpanFull()
                                                    ->options([
                                                        'year' => 'Year',
                                                        // 'resolution' => 'Resolution',
                                                        // 'codec' => 'Codec',
                                                        'tmdb_id' => 'TMDB ID',
                                                    ])
                                                    ->dehydrateStateUsing(function ($state, Set $set) {
                                                        // Update the old boolean fields for backwards compatibility
                                                        $state = $state ?? [];
                                                        $set('vod_stream_file_sync_filename_year', in_array('year', $state));
                                                        $set('vod_stream_file_sync_filename_resolution', in_array('resolution', $state));
                                                        $set('vod_stream_file_sync_filename_codec', in_array('codec', $state));
                                                        $set('vod_stream_file_sync_filename_tmdb_id', in_array('tmdb_id', $state));

                                                        return $state;
                                                    }),
                                                Forms\Components\ToggleButtons::make('vod_stream_file_sync_tmdb_id_format')
                                                    ->label('TMDB ID format')
                                                    ->inline()
                                                    ->grouped()
                                                    ->live()
                                                    ->options([
                                                        'square' => '[square]',
                                                        'curly' => '{curly}',
                                                    ])->hidden(fn($get) => ! in_array('tmdb_id', $get('vod_stream_file_sync_filename_metadata') ?? [])),
                                            ])
                                            ->hidden(fn($get) => ! $get('vod_stream_file_sync_enabled')),
                                        Fieldset::make('Filename Cleansing')
                                            ->columnSpanFull()
                                            ->schema([
                                                Toggle::make('vod_stream_file_sync_clean_special_chars')
                                                    ->label('Clean special characters')
                                                    ->helperText('Remove or replace special characters in filenames')
                                                    ->inline(false),
                                                Toggle::make('vod_stream_file_sync_remove_consecutive_chars')
                                                    ->label('Remove consecutive replacement characters')
                                                    ->inline(false)
                                                    ->live(),
                                                Forms\Components\ToggleButtons::make('vod_stream_file_sync_replace_char')
                                                    ->label('Replace with')
                                                    ->inline()
                                                    ->grouped()
                                                    ->columnSpanFull()
                                                    ->options([
                                                        'space' => 'Space',
                                                        'dash' => '-',
                                                        'underscore' => '_',
                                                        'period' => '.',
                                                        'remove' => 'Remove',
                                                    ]),
                                            ])
                                            ->hidden(fn($get) => ! $get('vod_stream_file_sync_enabled')),
                                    ]),
                            ]),

                        Tab::make('Backups')
                            ->schema([
                                Section::make('Automated backups')
                                    ->headerActions([
                                        Action::make('view_cron_example')
                                            ->label('CRON Example')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('https://crontab.guru')
                                            ->openUrlInNewTab(true),
                                    ])->schema([
                                        Toggle::make('auto_backup_database')
                                            ->label('Enable Automatic Database Backups')
                                            ->live()
                                            ->helperText('When enabled, automatic database backups will be created based on the specified schedule.'),
                                        Group::make()
                                            ->columnSpanFull()
                                            ->columns(2)
                                            ->schema([
                                                TextInput::make('auto_backup_database_schedule')
                                                    ->label('Backup Schedule')
                                                    ->suffix(config('app.timezone'))
                                                    ->rules([new Cron])
                                                    ->live()
                                                    ->helperText(fn($get) => CronExpression::isValidExpression($get('auto_backup_database_schedule'))
                                                        ? 'Next scheduled backup: ' . (new CronExpression($get('auto_backup_database_schedule')))->getNextRunDate()->format('Y-m-d H:i:s')
                                                        : 'Specify the CRON schedule for automatic backups, e.g. "0 3 * * *".'),
                                                TextInput::make('auto_backup_database_max_backups')
                                                    ->label('Max Backups')
                                                    ->type('number')
                                                    ->minValue(0)
                                                    ->helperText('Specify the maximum number of backups to keep. Enter 0 for no limit.'),
                                            ])->hidden(fn($get) => ! $get('auto_backup_database')),
                                    ]),
                            ]),

                        Tab::make('SMTP')
                            ->columns(2)
                            ->schema([
                                Section::make('SMTP Settings')
                                    ->description('Configure SMTP settings to send emails from the application.')
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->headerActions([
                                        Action::make('send_test_email')
                                            ->label('Send Test Email')
                                            ->icon('heroicon-o-envelope')
                                            ->iconPosition('after')
                                            ->color('gray')
                                            ->size('sm')
                                            ->modalWidth('md')
                                            ->schema([
                                                TextInput::make('to_email')
                                                    ->label('To Email Address')
                                                    ->email()
                                                    ->required()
                                                    ->placeholder('Enter To Email Address')
                                                    ->helperText('A test email will be sent to this address using the entered SMTP settings.'),
                                            ])
                                            ->action(function (array $data, $get): void {
                                                try {
                                                    // Get SMTP settings from the form state
                                                    $formState = $this->form->getState();

                                                    // Make sure all required fields are present
                                                    if (empty($formState['smtp_host']) || empty($formState['smtp_port']) || empty($formState['smtp_username']) || empty($formState['smtp_password'])) {
                                                        Notification::make()
                                                            ->danger()
                                                            ->title('Missing SMTP Fields')
                                                            ->body('Please fill in all required SMTP fields before sending a test email.')
                                                            ->send();

                                                        return;
                                                    }

                                                    // Configure mail settings temporarily
                                                    Config::set('mail.default', 'smtp');
                                                    Config::set('mail.from.address', $formState['smtp_from_address'] ?? 'no-reply@m3u-editor.dev');
                                                    Config::set('mail.from.name', 'm3u editor');
                                                    Config::set('mail.mailers.smtp.host', $formState['smtp_host']);
                                                    Config::set('mail.mailers.smtp.username', $formState['smtp_username']);
                                                    Config::set('mail.mailers.smtp.password', $formState['smtp_password']);
                                                    Config::set('mail.mailers.smtp.port', $formState['smtp_port']);
                                                    Config::set('mail.mailers.smtp.encryption', $formState['smtp_encryption']);

                                                    Mail::raw('This is a test email to verify your SMTP settings.', function ($message) use ($data) {
                                                        $message->to($data['to_email'])
                                                            ->subject('Test Email from m3u editor');
                                                    });

                                                    Notification::make()
                                                        ->success()
                                                        ->title('Test Email Sent')
                                                        ->body('Test email sent successfully to ' . $data['to_email'])
                                                        ->send();
                                                } catch (Exception $e) {
                                                    Notification::make()
                                                        ->danger()
                                                        ->title('Error Sending Test Email')
                                                        ->body($e->getMessage())
                                                        ->send();
                                                }
                                            }),
                                    ])
                                    ->schema([
                                        TextInput::make('smtp_host')
                                            ->label('SMTP Host')
                                            ->placeholder('Enter SMTP Host')
                                            ->requiredWith('smtp_port')
                                            ->helperText('Required to send emails.'),
                                        TextInput::make('smtp_port')
                                            ->label('SMTP Port')
                                            ->placeholder('Enter SMTP Port')
                                            ->requiredWith('smtp_host')
                                            ->numeric()
                                            ->helperText('Required to send emails.'),
                                        TextInput::make('smtp_username')
                                            ->label('SMTP Username')
                                            ->placeholder('Enter SMTP Username')
                                            ->requiredWith('smtp_password')
                                            ->helperText('Required to send emails, if your provider requires authentication.'),
                                        TextInput::make('smtp_password')
                                            ->label('SMTP Password')
                                            ->revealable()
                                            ->placeholder('Enter SMTP Password')
                                            ->requiredWith('smtp_username')
                                            ->password()
                                            ->helperText('Required to send emails, if your provider requires authentication.'),
                                        Select::make('smtp_encryption')
                                            ->label('SMTP Encryption')
                                            ->options([
                                                'tls' => 'TLS',
                                                'ssl' => 'SSL',
                                                null => 'None',
                                            ])
                                            ->placeholder('Select encryption type (optional)'),
                                        TextInput::make('smtp_from_address')
                                            ->label('SMTP From Address')
                                            ->placeholder('Enter SMTP From Address')
                                            ->email()
                                            ->helperText('The "From" email address for outgoing emails. Defaults to no-reply@m3u-editor.dev.'),
                                    ]),
                            ]),
                        Tab::make('API')
                            ->schema([
                                Section::make('API Settings')
                                    ->headerActions([
                                        Action::make('manage_api_keys')
                                            ->label('Manage API Tokens')
                                            ->color('gray')
                                            ->icon('heroicon-s-key')
                                            ->iconPosition('before')
                                            ->size('sm')
                                            ->url('/personal-access-tokens'),
                                        Action::make('view_api_docs')
                                            ->label('API Docs')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('/docs/api')
                                            ->openUrlInNewTab(true),
                                    ])->schema([
                                        Toggle::make('show_api_docs')
                                            ->label('Allow access to API docs')
                                            ->helperText('When enabled you can access the API documentation using the "API Docs" button. When disabled, the docs endpoint will return a 403 (Unauthorized). NOTE: The API will respond regardless of this setting. You do not need to enable it to use the API.'),
                                    ]),
                            ]),
                        Tab::make('Debugging')
                            ->schema([
                                Section::make('Debugging')
                                    ->headerActions([
                                        Action::make('test_websocket')
                                            ->label('Test WebSocket')
                                            ->icon('heroicon-o-signal')
                                            ->iconPosition('after')
                                            ->color('gray')
                                            ->size('sm')
                                            ->modalWidth('md')
                                            ->schema([
                                                TextInput::make('message')
                                                    ->label('Message')
                                                    ->required()
                                                    ->default('Testing WebSocket connection')
                                                    ->helperText('This message will be sent to the WebSocket server, and displayed as a pop-up notification. If you do not see a notification shortly after sending, there is likely an issue with your WebSocket configuration.'),
                                            ])
                                            ->action(function (array $data): void {
                                                Notification::make()
                                                    ->success()
                                                    ->title('WebSocket Connection Test')
                                                    ->body($data['message'])
                                                    ->persistent()
                                                    ->broadcast(auth()->user());
                                            }),
                                        // Action::make('view_logs')
                                        //     ->label('View Logs')
                                        //     ->color('gray')
                                        //     ->icon('heroicon-o-document-text')
                                        //     ->iconPosition('after')
                                        //     ->size('sm')
                                        //     ->url('/logs'),
                                        Action::make('view_queue_manager')
                                            ->label('Queue Manager')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('/horizon')
                                            ->openUrlInNewTab(true),
                                    ])->schema([
                                        // Toggle::make('show_logs')
                                        //     ->label('Make log files viewable')
                                        //     ->hintIcon(
                                        //         'heroicon-m-question-mark-circle',
                                        //         tooltip: 'You may need to refresh the page after applying this setting to view the logs. When disabled you will get a 404.'
                                        //     )
                                        //     ->helperText('When enabled, there will be an additional navigation item (Logs) to view the log file content.'),
                                        Toggle::make('show_queue_manager')
                                            ->label('Allow queue manager access')
                                            ->helperText('When enabled you can access the queue manager using the "Queue Manager" button. When disabled, the queue manager endpoint will return a 403 (Unauthorized).'),
                                    ]),
                            ]),
                    ])->contained(false),
            ]);
    }

    public function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Settings saved')
            ->body('Your preferences have been saved successfully.');
    }
}
