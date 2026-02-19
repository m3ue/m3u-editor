<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Jobs\RestartQueue;
use App\Models\StreamProfile;
use App\Rules\Cron;
use App\Services\M3uProxyService;
use App\Services\PlaylistService;
use App\Settings\GeneralSettings;
use Cron\CronExpression;
use Dom\Text;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

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
            ActionGroup::make([
                Action::make('Clear Expired Logo Cache')
                    ->label('Clear Expired Logo Cache')
                    ->action(fn () => Artisan::call('app:logo-cleanup --force'))
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Expired logo cache cleared')
                            ->body('Expired logo cache files were removed successfully.')
                            ->duration(10000)
                            ->send();
                    })
                    ->color('warning')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-trash')
                    ->modalIcon('heroicon-o-trash')
                    ->modalDescription('Only expired logo cache entries (those older than 30 days). If permanent cache is enabled, nothing will be removed.')
                    ->modalSubmitActionLabel('Clear expired cache'),
                Action::make('Clear Logo Cache')
                    ->label('Clear All Logo Cache')
                    ->action(fn () => Artisan::call('app:logo-cleanup --force --all'))
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Logo cache cleared')
                            ->body('The logo cache has been cleared. Logos will be fetched again on next request wherever logo proxy is enabled.')
                            ->duration(10000)
                            ->send();
                    })
                    ->color('danger')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-exclamation-triangle')
                    ->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalDescription('Clearing the logo cache will remove all cached logo images. If permanent cache is enabled, it will be ignored. This action cannot be undone.')
                    ->modalSubmitActionLabel('I understand, clear now'),
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
                    ->color('danger')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-exclamation-triangle')
                    ->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalDescription('Resetting the queue will restart the queue workers and flush any pending jobs. Any syncs or background processes will be stopped and removed. Only perform this action if you are having sync issues.')
                    ->modalSubmitActionLabel('I understand, reset now'),
            ])->button()->color('gray')->label('Actions'),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $m3uPublicUrl = rtrim(config('proxy.m3u_proxy_public_url'), '/');
        $m3uToken = config('proxy.m3u_proxy_token', null);
        if (empty($m3uPublicUrl)) {
            $m3uPublicUrl = url('/m3u-proxy');
        }
        $m3uProxyDocs = $m3uPublicUrl.'/docs';

        // Setup the service
        $service = new M3uProxyService;
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
                        Tab::make('General')
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
                                                    })->disabled(fn () => config('dev.show_wan_details') !== null)
                                                    ->hint(fn () => config('dev.show_wan_details') !== null ? 'Already set by environment variable!' : null)
                                                    ->dehydrated(fn () => config('dev.show_wan_details') === null),
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
                                Section::make('Xtream API Panel Settings')
                                    ->schema([
                                        Grid::make()
                                            ->columnSpanFull()
                                            ->columns(2)
                                            ->schema([
                                                TextInput::make('xtream_api_details.http_port')
                                                    ->label('HTTP Port')
                                                    ->numeric()
                                                    ->placeholder(fn () => config('app.port', '80'))
                                                    ->helperText('Returned as "server_info.http_port" in "player_api.php" responses. Leave empty to use APP_PORT (default).'),
                                                TextInput::make('xtream_api_details.https_port')
                                                    ->label('HTTPS Port')
                                                    ->numeric()
                                                    ->placeholder('443')
                                                    ->helperText('Returned as "server_info.https_port" in "player_api.php" responses. Leave empty to use 443 (default).'),
                                                Textarea::make('xtream_api_message')
                                                    ->label('Xtream API panel message')
                                                    ->helperText('Returned as "user_info.message" in "player_api.php" responses.')
                                                    ->rows(3)
                                                    ->columnSpanFull()
                                                    ->default(''),

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

                                                TextInput::make('url_override')
                                                    ->label('Override URL')
                                                    ->columnSpanFull()
                                                    ->url()
                                                    ->live()
                                                    ->hintIcon(
                                                        'heroicon-m-question-mark-circle',
                                                        tooltip: 'If you would like the proxied streams to use a different base URL than the configured app url. Useful for local network access or when using a different domain for streaming.'
                                                    )
                                                    ->disabled(fn () => ! empty(config('proxy.url_override')))
                                                    ->hint(fn () => ! empty(config('proxy.url_override')) ? 'Already set by environment variable!' : null)
                                                    ->prefixIcon('heroicon-m-link')
                                                    ->disabled(fn () => ! empty(config('proxy.url_override')))
                                                    ->hint(fn () => ! empty(config('proxy.url_override')) ? 'Already set by environment variable!' : null)
                                                    ->default(fn () => ! empty(config('proxy.url_override')) ? config('proxy.url_override') : '')
                                                    ->afterStateHydrated(function (TextInput $component, $state) {
                                                        if (! empty(config('proxy.url_override'))) {
                                                            $component->state((string) config('proxy.url_override'));
                                                        }
                                                    })
                                                    ->dehydrated(fn () => empty(config('proxy.url_override')))
                                                    ->placeholder('http://192.168.0.123:36400')
                                                    ->helperText(fn () => 'Leave empty to use the configured app url (default).'),

                                                Toggle::make('url_override_include_logos')
                                                    ->label('Include logos in proxy URL override')
                                                    ->columnSpanFull()
                                                    ->hintIcon(
                                                        'heroicon-m-question-mark-circle',
                                                        tooltip: 'This is useful for Plex which need HTTPS for logo images. When using a domain with HTTPS for the frontend, but proxy URL override points to a local HTTP address, Plex may not load the logos due to HTTPS requirements. By enabling this option you can keep the stream proxy override for local access while logos still use the HTTPS domain URL that Plex requires.'
                                                    )
                                                    ->disabled(fn () => config('proxy.url_override_include_logos') !== null)
                                                    ->hint(fn () => config('proxy.url_override_include_logos') !== null ? 'Already set by environment variable!' : null)
                                                    ->default(fn () => config('proxy.url_override_include_logos') !== null)
                                                    ->afterStateHydrated(function (Toggle $component, $state) {
                                                        if (config('proxy.url_override_include_logos') !== null) {
                                                            $component->state((bool) config('proxy.url_override_include_logos'));
                                                        }
                                                    })
                                                    ->hidden(fn ($get) => empty(config('proxy.url_override')) && empty($get('url_override')))
                                                    ->dehydrated(fn () => empty(config('proxy.url_override_include_logos')))
                                                    ->helperText('Whether or not to use the URL override for logos and images too (default is enabled).'),
                                            ]),

                                        Fieldset::make('Resolver settings')
                                            ->schema([
                                                Toggle::make('enable_failover_resolver')
                                                    ->label('Enable advanced failover logic')
                                                    ->columnSpanFull()
                                                    ->hintIcon(
                                                        'heroicon-m-question-mark-circle',
                                                        tooltip: 'When enabled, the proxy will make a call to the editor to determine which failover to use based on available capacity. When disabled, a list of failover URLs will be sent to the proxy and it will loop through them without any capacity checks when a stream failure occurs.'
                                                    )
                                                    ->live()
                                                    ->disabled(fn () => ! empty(config('proxy.resolver_url')))
                                                    ->hint(fn () => ! empty(config('proxy.resolver_url')) ? 'Already set by environment variable!' : null)
                                                    ->default(false)
                                                    ->afterStateHydrated(function (Toggle $component, $state) {
                                                        if (! empty(config('proxy.resolver_url'))) {
                                                            $component->state((bool) config('proxy.resolver_url'));
                                                        }
                                                    })
                                                    ->dehydrated(fn () => empty(config('proxy.resolver_url')))
                                                    ->helperText('Use to enable advanced failover checking and resolution.'),

                                                TextInput::make('failover_resolver_url')
                                                    ->label('Resolver URL')
                                                    ->columnSpanFull()
                                                    ->url()
                                                    ->live()
                                                    ->hintIcon(
                                                        'heroicon-m-question-mark-circle',
                                                        tooltip: 'The resolver URL is used for advanced failover logic, and Network Broadcasting features. This URL should point to the m3u-editor instance that the proxy can access.'
                                                    )
                                                    ->prefixIcon('heroicon-m-link')
                                                    ->disabled(fn () => ! empty(config('proxy.resolver_url')))
                                                    ->hint(fn () => ! empty(config('proxy.resolver_url')) ? 'Already set by environment variable!' : null)
                                                    ->default(fn () => ! empty(config('proxy.resolver_url')) ? config('proxy.resolver_url') : '')
                                                    ->afterStateHydrated(function (TextInput $component, $state) {
                                                        if (! empty(config('proxy.resolver_url'))) {
                                                            $component->state((string) config('proxy.resolver_url'));
                                                        }
                                                    })
                                                    ->required(fn ($get) => (bool) $get('enable_failover_resolver'))
                                                    ->dehydrated(fn () => empty(config('proxy.resolver_url')))
                                                    ->placeholder(fn () => $embedded ? 'http://127.0.0.1:'.config('app.port') : 'http://m3u-editor:36400')
                                                    ->helperText(fn () => $embedded
                                                        ? 'For embedded mode, you should use localhost, e.g.: "http://127.0.0.1:36400" or "http://localhost:36400".'
                                                        : 'Domain the proxy can use to access the editor for faillover resolution, e.g.: "http://m3u-editor:36400", "http://192.168.0.101:36400", "http://your-domain.dev", etc.'),

                                                Action::make('test_failover_connection')
                                                    ->label('Test resolver connection')
                                                    ->icon('heroicon-m-signal')
                                                    ->disabled(fn ($get) => empty($get('failover_resolver_url')))
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
                                                                    "**Proxy can reach the editor!**\n\n".
                                                                        "URL tested: `{$result['url_tested']}`\n\n"
                                                                ))
                                                                ->duration(8000)
                                                                ->send();
                                                        } else {
                                                            Notification::make()
                                                                ->danger()
                                                                ->title('Connection Failed')
                                                                ->body(Str::markdown(
                                                                    "**The proxy cannot reach the editor**\n\n".
                                                                        $result['message']."\n\n".
                                                                        'Please verify the Failover Resolver URL is correct and accessible from the proxy container/service.'
                                                                ))
                                                                ->duration(10000)
                                                                ->send();
                                                        }
                                                    }),
                                            ]),

                                        Fieldset::make('Failover conditions')
                                            ->hidden(fn ($get) => ! (bool) $get('enable_failover_resolver'))
                                            ->schema([
                                                Toggle::make('failover_fail_conditions_enabled')
                                                    ->label('Enable playlist fail conditions')
                                                    ->columnSpanFull()
                                                    ->live()
                                                    ->hintIcon(
                                                        'heroicon-m-question-mark-circle',
                                                        tooltip: 'When enabled, playlists returning specific HTTP status codes will be temporarily marked as invalid during failover resolution. This enables account-level failover by skipping all channels from a failing playlist/account.'
                                                    )
                                                    ->helperText('Mark playlists as temporarily unavailable when specific HTTP errors are encountered during failover.'),

                                                TagsInput::make('failover_fail_conditions')
                                                    ->label('HTTP status codes')
                                                    ->columnSpanFull()
                                                    ->hidden(fn ($get) => ! (bool) $get('failover_fail_conditions_enabled'))
                                                    ->placeholder('e.g. 403, 404, 502, 503')
                                                    ->helperText('HTTP response codes that should mark a playlist as temporarily unavailable. All channels from the affected playlist will be skipped during failover resolution.'),

                                                TextInput::make('failover_fail_conditions_timeout')
                                                    ->label('Invalid timeout (minutes)')
                                                    ->columnSpanFull()
                                                    ->hidden(fn ($get) => ! (bool) $get('failover_fail_conditions_enabled'))
                                                    ->numeric()
                                                    ->minValue(1)
                                                    ->default(5)
                                                    ->suffixIcon('heroicon-m-clock')
                                                    ->helperText('How long (in minutes) a playlist remains marked as invalid before being retried.'),

                                                Action::make('clear_failed_playlists')
                                                    ->label('Clear failed playlists')
                                                    ->icon('heroicon-o-arrow-path')
                                                    ->color('warning')
                                                    ->hidden(fn ($get) => ! (bool) $get('failover_fail_conditions_enabled'))
                                                    ->requiresConfirmation()
                                                    ->modalIcon('heroicon-o-arrow-path')
                                                    ->modalDescription('This will clear all playlists currently marked as invalid, allowing them to be used for failover again immediately.')
                                                    ->modalSubmitActionLabel('Clear all')
                                                    ->action(function () {
                                                        $prefix = config('cache.prefix');
                                                        $pattern = $prefix.'playlist_invalid:*';
                                                        $connection = config('cache.stores.redis.connection', 'cache');
                                                        $redis = Redis::connection($connection);
                                                        $cleared = 0;
                                                        $cursor = '0';
                                                        do {
                                                            [$cursor, $keys] = $redis->scan($cursor, ['match' => $pattern, 'count' => 100]);
                                                            if (! empty($keys)) {
                                                                $redis->del(...$keys);
                                                                $cleared += count($keys);
                                                            }
                                                        } while ($cursor !== '0');

                                                        Notification::make()
                                                            ->success()
                                                            ->title('Failed playlists cleared')
                                                            ->body($cleared > 0
                                                                ? "Cleared {$cleared} invalid playlist(s). They are now eligible for failover again."
                                                                : 'No invalid playlists found in cache.')
                                                            ->duration(5000)
                                                            ->send();
                                                    }),
                                            ]),

                                        Fieldset::make('Stream limit settings')
                                            ->schema([
                                                Toggle::make('proxy_stop_oldest_on_limit')
                                                    ->label('Stop oldest stream when limit reached')
                                                    ->columnSpanFull()
                                                    ->hintIcon(
                                                        'heroicon-m-question-mark-circle',
                                                        tooltip: 'When a playlist has a connection limit and it\'s reached, enabling this will automatically stop the oldest active stream to make room for the new request. This is useful for single-connection providers where you want instant channel switching. Note: This may cause issues if multiple clients share the same playlist - the newest request always wins.'
                                                    )
                                                    ->default(false)
                                                    ->helperText('Enable to allow new stream requests to automatically stop the oldest stream when a playlist reaches its connection limit. Disabled by default.'),
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
                                                        ->body('Could not connect to the m3u proxy instance. '.$e->getMessage())
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
                                    ->columns(3)
                                    ->collapsible(false)
                                    ->schema([
                                        Toggle::make('enable_provider_request_delay')
                                            ->label('Enable request delay')
                                            ->live()
                                            ->helperText('When enabled, a delay will be added between requests to the provider during playlist and EPG syncs.'),
                                        TextInput::make('provider_request_delay_ms')
                                            ->label('Request delay (milliseconds)')
                                            ->integer()
                                            ->required()
                                            ->hintIcon(
                                                'heroicon-m-question-mark-circle',
                                                tooltip: 'Recommended: 500-2000ms. Higher values reduce load on provider but increase sync time.'
                                            )
                                            ->minValue(100)
                                            ->maxValue(10000)
                                            ->step(100)
                                            ->default(500)
                                            ->suffix('ms')
                                            ->hidden(fn ($get) => ! $get('enable_provider_request_delay'))
                                            ->helperText('Delay in milliseconds between requests.'),
                                        TextInput::make('provider_max_concurrent_requests')
                                            ->label('Max concurrent requests')
                                            ->integer()
                                            ->required()
                                            ->hintIcon(
                                                'heroicon-m-question-mark-circle',
                                                tooltip: 'Lower values (1-2) are safer but slower. Set to 1 to process requests sequentially.'
                                            )
                                            ->minValue(1)
                                            ->maxValue(10)
                                            ->default(2)
                                            ->hidden(fn ($get) => ! $get('enable_provider_request_delay'))
                                            ->helperText('Maximum number of simultaneous requests to the provider.'),
                                    ]),
                                Section::make('Sync Invalidation')
                                    ->description('Prevent sync from proceeding if conditions are met.')
                                    ->columnSpan('full')
                                    ->columns(3)
                                    ->collapsible(false)
                                    ->schema([
                                        Toggle::make('invalidate_import')
                                            ->label('Enable sync invalidation')
                                            ->disabled(fn () => ! empty(config('dev.invalidate_import')))
                                            ->live()
                                            ->hint(fn () => ! empty(config('dev.invalidate_import')) ? 'Already set by environment variable!' : null)
                                            ->default(function () {
                                                return ! empty(config('dev.invalidate_import')) ? (bool) config('dev.invalidate_import') : false;
                                            })
                                            ->afterStateHydrated(function (Toggle $component, $state) {
                                                if (! empty(config('dev.invalidate_import'))) {
                                                    $component->state((bool) config('dev.invalidate_import'));
                                                }
                                            })
                                            ->dehydrated(fn () => empty(config('dev.invalidate_import'))),
                                        TextInput::make('invalidate_import_threshold')
                                            ->label('Sync invalidation threshold')
                                            ->columnSpan(2)
                                            ->hintIcon(
                                                'heroicon-m-question-mark-circle',
                                                tooltip: 'Some providers frequently remove and re-add groups/categories, which can lead to channels be removed during sync. This setting helps prevent large-scale removals by canceling the sync if the defined number of channels would be removed.'
                                            )
                                            ->suffixIcon(fn () => ! empty(config('dev.invalidate_import_threshold')) ? 'heroicon-m-lock-closed' : null)
                                            ->disabled(fn () => ! empty(config('dev.invalidate_import_threshold')))
                                            ->hint(fn () => ! empty(config('dev.invalidate_import_threshold')) ? 'Already set by environment variable!' : null)
                                            ->dehydrated(fn () => empty(config('dev.invalidate_import_threshold')))
                                            ->placeholder(fn () => empty(config('dev.invalidate_import_threshold')) ? 100 : config('dev.invalidate_import_threshold'))
                                            ->hidden(fn ($get) => ! empty(config('dev.invalidate_import')) || ! $get('invalidate_import'))
                                            ->numeric()
                                            ->helperText('If sync will remove more than this number of channels, the sync will be canceled.'),
                                    ]),
                                Section::make('Series stream file settings')
                                    ->description('Select a Stream File Setting for series .strm file generation.')
                                    ->columnSpan('full')
                                    ->columns(1)
                                    ->collapsible(false)
                                    ->schema([
                                        Select::make('default_series_stream_file_setting_id')
                                            ->label('Default Series Stream File Setting')
                                            ->searchable()
                                            ->hintIcon(
                                                'heroicon-m-question-mark-circle',
                                                tooltip: 'Stream File Settings can be created and managed in Playlist > Stream File Settings. Settings can be overridden at the Category level or per-Series.'
                                            )
                                            ->options(function () {
                                                return \App\Models\StreamFileSetting::where('user_id', auth()->id())
                                                    ->forSeries()
                                                    ->pluck('name', 'id');
                                            })
                                            ->hintAction(
                                                Action::make('manage_series_settings')
                                                    ->label('Manage Stream File Settings')
                                                    ->icon('heroicon-o-arrow-top-right-on-square')
                                                    ->iconPosition('after')
                                                    ->size('sm')
                                                    ->url('/stream-file-settings')
                                                    ->openUrlInNewTab(false)
                                            )
                                            ->helperText('Leave empty to disable .strm file generation for series. Priority: Series > Category > Global.'),
                                    ]),
                                Section::make('VOD stream file settings')
                                    ->description('Select a Stream File Setting for VOD .strm file generation. ')
                                    ->columnSpan('full')
                                    ->columns(1)
                                    ->collapsible(false)
                                    ->schema([
                                        Select::make('default_vod_stream_file_setting_id')
                                            ->label('Default VOD Stream File Setting')
                                            ->searchable()
                                            ->hintIcon(
                                                'heroicon-m-question-mark-circle',
                                                tooltip: 'Stream File Settings can be created and managed in Playlist > Stream File Settings. Settings can be overridden at the Group level or per-VOD channel.'
                                            )
                                            ->options(function () {
                                                return \App\Models\StreamFileSetting::where('user_id', auth()->id())
                                                    ->forVod()
                                                    ->pluck('name', 'id');
                                            })
                                            ->hintAction(
                                                Action::make('manage_vod_settings')
                                                    ->label('Manage Stream File Settings')
                                                    ->icon('heroicon-o-arrow-top-right-on-square')
                                                    ->iconPosition('after')
                                                    ->size('sm')
                                                    ->url('/stream-file-settings')
                                                    ->openUrlInNewTab(false)
                                            )
                                            ->helperText('Leave empty to disable .strm file generation for VOD. Priority: VOD > Group > Global.'),
                                    ]),
                            ]),

                        Tab::make('Assets')
                            ->schema([
                                Section::make('Logo Cache')
                                    ->description('Manage logo cache behavior and storage used by logo proxy URLs.')
                                    ->columns(1)
                                    ->headerActions([
                                        Action::make('manage_assets')
                                            ->label('Manage Assets')
                                            ->color('gray')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url(AssetResource::getUrl('index')),
                                        Action::make('view_repo')
                                            ->label('View Logo Repository')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('/logo-repository')
                                            ->hidden(fn ($get) => ! $get('logo_repository_enabled'))
                                            ->openUrlInNewTab(true),
                                    ])
                                    ->schema([
                                        Toggle::make('logo_cache_permanent')
                                            ->label('Keep cache permanently (disable expiry cleanup)')
                                            ->helperText('When enabled, expired cache cleanup will skip deletion. You can still refresh/clear cache manually.'),
                                        Toggle::make('logo_repository_enabled')
                                            ->label('Enable Logo Repository endpoint')
                                            ->live()
                                            ->helperText('When enabled, /logo-repository endpoints are publicly accessible for apps like UHF.'),

                                    ]),
                                Section::make('Placeholder Images')
                                    ->description('Override app-wide placeholder images for logos, episode previews, and VOD/Series poster fallbacks.')
                                    ->columns(3)
                                    ->schema([
                                        FileUpload::make('logo_placeholder_url')
                                            ->label('Logo placeholder')
                                            ->image()
                                            ->disk('public')
                                            ->directory('assets/placeholders')
                                            ->visibility('public')
                                            ->openable()
                                            ->downloadable()
                                            ->hintIcon(
                                                'heroicon-m-question-mark-circle',
                                                tooltip: 'Used when a channel logo is missing. Clear to use the default placeholder.'
                                            )
                                            ->helperText(new HtmlString('<strong>Recommended size:</strong> 300x300px for best results.<br/>Default image: <img src="'.url('/placeholder.png').'" alt="Default Logo Placeholder" style="width:80px; height:80px; margin-top:5px;">')),
                                        FileUpload::make('episode_placeholder_url')
                                            ->label('Episode preview placeholder')
                                            ->image()
                                            ->disk('public')
                                            ->directory('assets/placeholders')
                                            ->visibility('public')
                                            ->openable()
                                            ->downloadable()
                                            ->hintIcon(
                                                'heroicon-m-question-mark-circle',
                                                tooltip: 'Used when an episode preview image is missing. Clear to use the default placeholder.'
                                            )
                                            ->helperText(new HtmlString('<strong>Recommended size:</strong> 600x400px for best results.<br/>Default image: <img src="'.url('/episode-placeholder.png').'" alt="Default Episode Placeholder" style="width:120px; height:80px; margin-top:5px;">')),
                                        FileUpload::make('vod_series_poster_placeholder_url')
                                            ->label('VOD/Series poster placeholder')
                                            ->image()
                                            ->disk('public')
                                            ->directory('assets/placeholders')
                                            ->visibility('public')
                                            ->openable()
                                            ->downloadable()
                                            ->hintIcon(
                                                'heroicon-m-question-mark-circle',
                                                tooltip: 'Used when VOD/Series poster or cover images are missing. Clear to use the default placeholder.'
                                            )
                                            ->helperText(new HtmlString('<strong>Recommended size:</strong> 600x900px for best results.<br/>Default image: <img src="'.url('/vod-series-poster-placeholder.png').'" alt="Default VOD/Series Poster Placeholder" style="width:80px; height:120px; margin-top:5px;">')),
                                    ]),
                            ]),

                        Tab::make('Backups')
                            ->schema([
                                Section::make('Automated backups')
                                    ->schema([
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
                                                    ->hintAction(
                                                        Action::make('view_cron_example')
                                                            ->label('CRON Example')
                                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                                            ->iconPosition('after')
                                                            ->size('sm')
                                                            ->url('https://crontab.guru')
                                                            ->openUrlInNewTab(true)
                                                    )
                                                    ->helperText(fn ($get) => CronExpression::isValidExpression($get('auto_backup_database_schedule'))
                                                        ? 'Next scheduled backup: '.(new CronExpression($get('auto_backup_database_schedule')))->getNextRunDate()->format('Y-m-d H:i:s')
                                                        : 'Specify the CRON schedule for automatic backups, e.g. "0 3 * * *".'),
                                                TextInput::make('auto_backup_database_max_backups')
                                                    ->label('Max Backups')
                                                    ->type('number')
                                                    ->minValue(0)
                                                    ->helperText('Specify the maximum number of backups to keep. Enter 0 for no limit.'),
                                            ])->hidden(fn ($get) => ! $get('auto_backup_database')),
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
                                                        ->body('Test email sent successfully to '.$data['to_email'])
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
                        Tab::make('TMDB')
                            ->columns(2)
                            ->schema([
                                Section::make('TMDB Integration')
                                    ->description('Configure The Movie Database (TMDB) integration to automatically lookup and populate metadata IDs (TMDB, TVDB, IMDB) for your VOD content and Series.')
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->headerActions([
                                        Action::make('test_tmdb_connection')
                                            ->label('Test Connection')
                                            ->icon('heroicon-o-signal')
                                            ->iconPosition('after')
                                            ->color('gray')
                                            ->size('sm')
                                            ->action(function ($get): void {
                                                $apiKey = $get('tmdb_api_key');
                                                if (empty($apiKey)) {
                                                    Notification::make()
                                                        ->danger()
                                                        ->title('API Key Required')
                                                        ->body('Please enter a TMDB API key to test the connection.')
                                                        ->send();

                                                    return;
                                                }

                                                try {
                                                    $response = Http::timeout(10)->get('https://api.themoviedb.org/3/configuration', [
                                                        'api_key' => $apiKey,
                                                    ]);

                                                    if ($response->successful()) {
                                                        Notification::make()
                                                            ->success()
                                                            ->title('Connection Successful')
                                                            ->body('Successfully connected to TMDB API!')
                                                            ->send();
                                                    } else {
                                                        $error = $response->json('status_message', 'Unknown error');
                                                        Notification::make()
                                                            ->danger()
                                                            ->title('Connection Failed')
                                                            ->body("TMDB API returned an error: {$error}")
                                                            ->send();
                                                    }
                                                } catch (\Exception $e) {
                                                    Notification::make()
                                                        ->danger()
                                                        ->title('Connection Failed')
                                                        ->body('Could not connect to TMDB API: '.$e->getMessage())
                                                        ->send();
                                                }
                                            }),
                                        Action::make('get_tmdb_api_key')
                                            ->label('Get API Key')
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('https://www.themoviedb.org/settings/api')
                                            ->openUrlInNewTab(true),
                                    ])
                                    ->schema([
                                        TextInput::make('tmdb_api_key')
                                            ->label('TMDB API Key')
                                            ->placeholder('Enter your TMDB API Key (v3 auth)')
                                            ->password()
                                            ->revealable()
                                            ->columnSpanFull()
                                            ->helperText('Your TMDB API key (v3 auth). You can get one for free at themoviedb.org.'),
                                        Toggle::make('tmdb_auto_lookup_on_import')
                                            ->label('Auto-lookup on import')
                                            ->helperText('Automatically lookup TMDB IDs when importing VOD content and Series. This may slow down imports for large playlists.')
                                            ->default(false),
                                        TextInput::make('tmdb_rate_limit')
                                            ->label('Rate Limit (requests/second)')
                                            ->placeholder('40')
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(50)
                                            ->default(40)
                                            ->helperText('Maximum TMDB API requests per second. TMDB allows ~40 req/s for free accounts.'),
                                        Select::make('tmdb_language')
                                            ->label('Search Language')
                                            ->searchable()
                                            ->options([
                                                'en-US' => 'English (US)',
                                                'en-GB' => 'English (UK)',
                                                'de-DE' => 'German',
                                                'fr-FR' => 'French',
                                                'es-ES' => 'Spanish (Spain)',
                                                'es-MX' => 'Spanish (Mexico)',
                                                'it-IT' => 'Italian',
                                                'pt-PT' => 'Portuguese',
                                                'pt-BR' => 'Portuguese (Brazil)',
                                                'nl-NL' => 'Dutch',
                                                'pl-PL' => 'Polish',
                                                'ru-RU' => 'Russian',
                                                'ja-JP' => 'Japanese',
                                                'ko-KR' => 'Korean',
                                                'zh-CN' => 'Chinese (Simplified)',
                                                'zh-TW' => 'Chinese (Traditional)',
                                                'ar-SA' => 'Arabic',
                                                'tr-TR' => 'Turkish',
                                                'sv-SE' => 'Swedish',
                                                'da-DK' => 'Danish',
                                                'fi-FI' => 'Finnish',
                                                'no-NO' => 'Norwegian',
                                            ])
                                            ->default('en-US')
                                            ->helperText('Preferred language for TMDB searches.'),
                                        TextInput::make('tmdb_confidence_threshold')
                                            ->label('Match Confidence Threshold (%)')
                                            ->placeholder('80')
                                            ->numeric()
                                            ->minValue(50)
                                            ->maxValue(100)
                                            ->default(80)
                                            ->helperText('Minimum title similarity percentage (50-100) required to accept a match. Higher values = stricter matching.'),
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
