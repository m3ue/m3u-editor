<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\Pages\Concerns\BaseSettingsPage;
use App\Models\StreamProfile;
use App\Services\M3uProxyService;
use BackedEnum;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ManageProxySettings extends BaseSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $slug = 'proxy';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('Proxy');
    }

    public function getTitle(): string
    {
        return __('Proxy');
    }

    public static function canAccess(): bool
    {
        return parent::canAccess() && config('proxy.proxy_integration_enabled', true);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('proxy.proxy_integration_enabled', true);
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

        return $schema
            ->components([
                Section::make(__('URL & Connection'))
                    ->description(__('Configure how the proxy is accessed and how stream URLs are resolved.'))
                    ->columnSpanFull()
                    ->headerActions([
                        Action::make('test_connection')
                            ->label(__('Test connection'))
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

                                        // Transcoding is available in all modes
                                        $details .= "**Transcoding:** ✅ Available\n";
                                        $details .= "\n";

                                        // FFmpeg Version
                                        $ffmpegVersion = $info['ffmpeg_version'] ?? 'Unknown';
                                        $details .= "**FFmpeg Version:** \n\n{$ffmpegVersion}\n\n";

                                        // Streamlink
                                        $streamlinkVersion = $info['streamlink_version'] ?? null;
                                        $streamlinkStatus = $streamlinkVersion ? "✅ {$streamlinkVersion}" : '❌ Not installed';
                                        $details .= "**Streamlink:** {$streamlinkStatus}\n\n";

                                        // yt-dlp
                                        $ytdlpVersion = $info['ytdlp_version'] ?? null;
                                        $ytdlpStatus = $ytdlpVersion ? "✅ {$ytdlpVersion}" : '❌ Not installed';
                                        $details .= "**yt-dlp:** {$ytdlpStatus}\n\n";

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
                                            ->title(__('Connection Successful'))
                                            ->body(Str::markdown($details))
                                            ->success()
                                            ->persistent()
                                            ->send();
                                    } else {
                                        Notification::make()
                                            ->title(__('Connection Failed'))
                                            ->body($result['error'] ?? 'Could not connect to the m3u proxy instance. Please check the URL and ensure the service is running.')
                                            ->danger()
                                            ->send();
                                    }
                                } catch (Exception $e) {
                                    Notification::make()
                                        ->title(__('Connection Failed'))
                                        ->body('Could not connect to the m3u proxy instance. '.$e->getMessage())
                                        ->danger()
                                        ->send();
                                }
                            }),
                        Action::make('get_api_key')
                            ->label(__('API key'))
                            ->icon('heroicon-m-key')
                            ->action(function () use ($m3uToken) {
                                Notification::make()
                                    ->title(__('Your m3u proxy API key'))
                                    ->body($m3uToken)
                                    ->info()
                                    ->send();
                            })->hidden(! $m3uToken),
                        Action::make('m3u_proxy_info')
                            ->label(__('API docs'))
                            ->color('gray')
                            ->url($m3uProxyDocs)
                            ->openUrlInNewTab(true)
                            ->icon('heroicon-m-arrow-top-right-on-square'),
                        Action::make('github')
                            ->label(__('GitHub'))
                            ->color('gray')
                            ->url('https://github.com/sparkison/m3u-proxy')
                            ->openUrlInNewTab(true)
                            ->icon('heroicon-m-arrow-top-right-on-square'),
                    ])
                    ->schema([
                        TextInput::make('url_override')
                            ->label(__('Override URL'))
                            ->columnSpanFull()
                            ->url()
                            ->live()
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('If you would like the proxied streams to use a different base URL than the configured app url. Useful for local network access or when using a TLD for access, but prefer LAN address for streaming.')
                            )
                            ->disabled(fn () => ! empty(config('proxy.url_override')))
                            ->hint(fn () => ! empty(config('proxy.url_override')) ? __('Already set by environment variable!') : null)
                            ->prefixIcon('heroicon-m-link')
                            ->default(fn () => ! empty(config('proxy.url_override')) ? config('proxy.url_override') : '')
                            ->afterStateHydrated(function (TextInput $component, $state) {
                                if (! empty(config('proxy.url_override'))) {
                                    $component->state((string) config('proxy.url_override'));
                                }
                            })
                            ->dehydrated(fn () => empty(config('proxy.url_override')))
                            ->placeholder(__('http://192.168.0.123:36400'))
                            ->helperText(fn () => 'Leave empty to use the configured app url (default).'),

                        Toggle::make('m3u_proxy_public_url_auto_resolve')
                            ->label(__('Resolve proxy public URL dynamically at request time'))
                            ->columnSpanFull()
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('When enabled, the application will resolve the public-facing proxy URL using the incoming request host/scheme instead of the APP_URL or the configured Override URL (if set).')
                            )
                            ->helperText(__('Useful for multi-host access (VPN/Tailscale/etc.)'))
                            ->default(false),

                        Toggle::make('proxy_stop_oldest_on_limit')
                            ->label(__('Stop oldest stream when limit reached'))
                            ->columnSpanFull()
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('When a playlist has a connection limit and it\'s reached, enabling this will automatically stop the oldest active stream to make room for the new request. This is useful for single-connection providers where you want instant channel switching. Note: This may cause issues if multiple clients share the same playlist - the newest request always wins.')
                            )
                            ->default(false)
                            ->helperText(__('Enable to allow new stream requests to automatically stop the oldest stream when a playlist reaches its connection limit. Disabled by default.')),

                        Toggle::make('url_override_include_logos')
                            ->label(__('Include logos in proxy URL override'))
                            ->columnSpanFull()
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('This is useful for Plex which need HTTPS for logo images. When using a domain with HTTPS for the frontend, but proxy URL override points to a local HTTP address, Plex may not load the logos due to HTTPS requirements. By enabling this option you can keep the stream proxy override for local access while logos still use the HTTPS domain URL that Plex requires.')
                            )
                            ->disabled(fn () => config('proxy.url_override_include_logos') !== null)
                            ->hint(fn () => config('proxy.url_override_include_logos') !== null ? __('Already set by environment variable!') : null)
                            ->default(fn () => config('proxy.url_override_include_logos') !== null)
                            ->afterStateHydrated(function (Toggle $component, $state) {
                                if (config('proxy.url_override_include_logos') !== null) {
                                    $component->state((bool) config('proxy.url_override_include_logos'));
                                }
                            })
                            ->hidden(fn ($get) => empty(config('proxy.url_override')) && empty($get('url_override')))
                            ->dehydrated(fn () => empty(config('proxy.url_override_include_logos')))
                            ->helperText(__('Whether or not to use the URL override for logos and images too (default is enabled).')),
                    ]),

                Section::make(__('Failover & Recovery'))
                    ->description(__('Configure how the proxy handles stream failures, including advanced resolver logic and fail conditions.'))
                    ->columnSpanFull()
                    ->headerActions([
                        Action::make('test_failover_connection')
                            ->label(__('Test resolver connection'))
                            ->icon('heroicon-m-signal')
                            ->disabled(fn ($get) => empty($get('failover_resolver_url')))
                            ->action(function ($get) use ($service) {
                                $configUrl = config('proxy.m3u_resolver_url');
                                $url = $configUrl ?? $get('failover_resolver_url');
                                $url = rtrim($url, '/');
                                $result = $service->testResolver($url);

                                if ($result['success']) {
                                    Notification::make()
                                        ->success()
                                        ->title(__('Connection Successful'))
                                        ->body(Str::markdown(
                                            "**Proxy can reach the editor!**\n\n".
                                                "URL tested: `{$result['url_tested']}`\n\n"
                                        ))
                                        ->duration(8000)
                                        ->send();
                                } else {
                                    Notification::make()
                                        ->danger()
                                        ->title(__('Connection Failed'))
                                        ->body(Str::markdown(
                                            "**The proxy cannot reach the editor**\n\n".
                                                $result['message']."\n\n".
                                                'Please verify the Failover Resolver URL is correct and accessible from the proxy container/service.'
                                        ))
                                        ->duration(10000)
                                        ->send();
                                }
                            }),
                    ])
                    ->schema([
                        TextInput::make('failover_resolver_url')
                            ->label(__('Resolver URL'))
                            ->columnSpanFull()
                            ->url()
                            ->live()
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('This should be the LAN address of the editor for the proxy to access. The resolver URL is used for advanced failover logic, webhook registration for pooled providers, and Network Broadcasting features. This URL should point to the m3u-editor instance that the proxy can access.')
                            )
                            ->prefixIcon('heroicon-m-link')
                            ->disabled(fn () => ! empty(config('proxy.m3u_resolver_url')))
                            ->hint(fn () => ! empty(config('proxy.m3u_resolver_url')) ? __('Already set by environment variable!') : null)
                            ->default(fn () => ! empty(config('proxy.m3u_resolver_url')) ? config('proxy.m3u_resolver_url') : '')
                            ->afterStateHydrated(function (TextInput $component, $state) {
                                if (! empty(config('proxy.m3u_resolver_url'))) {
                                    $component->state((string) config('proxy.m3u_resolver_url'));
                                }
                            })
                            ->required(fn ($get) => (bool) $get('enable_failover_resolver'))
                            ->dehydrated(fn () => empty(config('proxy.m3u_resolver_url')))
                            ->placeholder(fn () => $embedded ? 'http://127.0.0.1:'.config('app.port') : 'http://m3u-editor:36400')
                            ->helperText(fn () => $embedded
                                ? 'Domain the proxy can use to access the editor for failover resolution and webhook registration, e.g.: "http://127.0.0.1:36400" or "http://localhost:36400".'
                                : 'Domain the proxy can use to access the editor for failover resolution and webhook registration, e.g.: "http://m3u-editor:36400", "http://192.168.0.101:36400", "http://your-domain.dev", etc.'),

                        Toggle::make('enable_failover_resolver')
                            ->label(__('Enable advanced failover logic'))
                            ->columnSpanFull()
                            ->hintAction(
                                Action::make('learn_more_strict_live_ts')
                                    ->label(__('Learn More'))
                                    ->icon('heroicon-o-arrow-top-right-on-square')
                                    ->iconPosition('after')
                                    ->size('sm')
                                    ->url('https://m3ue.sparkison.dev/docs/proxy/failover#advanced-failover-m3u-editor')
                                    ->openUrlInNewTab(true)
                            )
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('When enabled, the proxy will make a call to the editor to determine which failover to use based on available capacity. When disabled, a list of failover URLs will be sent to the proxy and it will loop through them without any capacity checks when a stream failure occurs.')
                            )
                            ->live()
                            ->disabled(fn () => ! empty(config('proxy.m3u_resolver_url')))
                            ->hint(fn () => ! empty(config('proxy.m3u_resolver_url')) ? __('Already set by environment variable!') : null)
                            ->default(false)
                            ->afterStateHydrated(function (Toggle $component, $state) {
                                if (! empty(config('proxy.m3u_resolver_url'))) {
                                    $component->state((bool) config('proxy.m3u_resolver_url'));
                                }
                            })
                            ->dehydrated(fn () => empty(config('proxy.m3u_resolver_url')))
                            ->helperText(__('Use to enable advanced failover checking and resolution (Resolver URL is required).')),

                        Fieldset::make(__('Playlist fail conditions'))
                            ->hidden(fn ($get) => ! (bool) $get('enable_failover_resolver'))
                            ->schema([
                                Toggle::make('failover_fail_conditions_enabled')
                                    ->label(__('Enable playlist fail conditions'))
                                    ->columnSpanFull()
                                    ->live()
                                    ->hintIcon(
                                        'heroicon-m-question-mark-circle',
                                        tooltip: __('When enabled, playlists returning specific HTTP status codes will be temporarily marked as invalid during failover resolution. This enables account-level failover by skipping all channels from a failing playlist/account.')
                                    )
                                    ->helperText(__('Mark playlists as temporarily unavailable when specific HTTP errors are encountered during failover.')),

                                TagsInput::make('failover_fail_conditions')
                                    ->label(__('HTTP status codes'))
                                    ->columnSpanFull()
                                    ->hidden(fn ($get) => ! (bool) $get('failover_fail_conditions_enabled'))
                                    ->placeholder(__('e.g. 403, 404, 502, 503'))
                                    ->helperText(__('HTTP response codes that should mark a playlist as temporarily unavailable. All channels from the affected playlist will be skipped during failover resolution.')),

                                TextInput::make('failover_fail_conditions_timeout')
                                    ->label(__('Invalid timeout (minutes)'))
                                    ->columnSpanFull()
                                    ->hidden(fn ($get) => ! (bool) $get('failover_fail_conditions_enabled'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(5)
                                    ->suffixIcon('heroicon-m-clock')
                                    ->helperText(__('How long (in minutes) a playlist remains marked as invalid before being retried.')),

                                Action::make('clear_failed_playlists')
                                    ->label(__('Clear failed playlists'))
                                    ->icon('heroicon-o-arrow-path')
                                    ->color('warning')
                                    ->hidden(fn ($get) => ! (bool) $get('failover_fail_conditions_enabled'))
                                    ->requiresConfirmation()
                                    ->modalIcon('heroicon-o-arrow-path')
                                    ->modalDescription(__('This will clear all playlists currently marked as invalid, allowing them to be used for failover again immediately.'))
                                    ->modalSubmitActionLabel(__('Clear all'))
                                    ->action(function () {
                                        $count = Redis::hlen('playlist_invalid');
                                        if ($count > 0) {
                                            Redis::del('playlist_invalid');
                                        }

                                        Notification::make()
                                            ->success()
                                            ->title(__('Failed playlists cleared'))
                                            ->body($count > 0
                                                ? "Cleared {$count} invalid playlist(s). They are now eligible for failover again."
                                                : 'No invalid playlists found.')
                                            ->duration(5000)
                                            ->send();
                                    }),
                            ]),

                        Toggle::make('enable_silence_detection')
                            ->label(__('Enable silence detection'))
                            ->columnSpanFull()
                            ->live()
                            ->hintAction(
                                Action::make('learn_more_strict_live_ts')
                                    ->label(__('Learn More'))
                                    ->icon('heroicon-o-arrow-top-right-on-square')
                                    ->iconPosition('after')
                                    ->size('sm')
                                    ->url('https://m3ue.sparkison.dev/docs/proxy/silence-detection')
                                    ->openUrlInNewTab(true)
                            )
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('When enabled, the proxy will monitor live streams for silent audio. If silence is detected for the configured number of consecutive checks, a failover is triggered.')
                            )
                            ->helperText(__('Automatically trigger failover when a stream\'s audio goes silent. Disabled by default.')),

                        Fieldset::make(__('Silence Detection Settings'))
                            ->hidden(fn (Get $get) => ! (bool) $get('enable_silence_detection'))
                            ->schema([
                                TextInput::make('silence_threshold_db')
                                    ->label(__('Silence threshold (dB)'))
                                    ->numeric()
                                    ->default(-50.0)
                                    ->step(0.1)
                                    ->suffix('dB')
                                    ->hintIcon(
                                        'heroicon-m-question-mark-circle',
                                        tooltip: __('Audio level below which audio is considered silent. -50 dB is a good default; raise to -40 dB for stricter detection.')
                                    )
                                    ->helperText(__('Audio level (in dB) below which audio is considered silent. Default: -50 dB.')),

                                TextInput::make('silence_duration')
                                    ->label(__('Silence duration (seconds)'))
                                    ->numeric()
                                    ->default(3.0)
                                    ->step(0.5)
                                    ->suffix('s')
                                    ->helperText(__('Minimum continuous silence within a check window to count as a silent check. Default: 3 seconds.')),

                                TextInput::make('silence_check_interval')
                                    ->label(__('Check interval (seconds)'))
                                    ->numeric()
                                    ->default(10.0)
                                    ->step(1)
                                    ->suffix('s')
                                    ->helperText(__('How often to run silence analysis. Each window buffers stream data and analyses it with ffmpeg. Default: 10 seconds.')),

                                TextInput::make('silence_failover_threshold')
                                    ->label(__('Consecutive silent checks before failover'))
                                    ->numeric()
                                    ->integer()
                                    ->default(3)
                                    ->step(1)
                                    ->minValue(1)
                                    ->helperText(__('Number of consecutive silent checks required before triggering failover. Prevents failover on brief silent moments. Default: 3.')),

                                TextInput::make('silence_monitoring_grace_period')
                                    ->label(__('Monitoring grace period (seconds)'))
                                    ->numeric()
                                    ->default(15.0)
                                    ->step(1)
                                    ->suffix('s')
                                    ->helperText(__('Delay after stream start before silence monitoring begins. Allows for initial buffering and audio decoder startup. Default: 15 seconds.')),
                            ])->hidden(fn (Get $get) => ! (bool) $get('enable_silence_detection')),
                    ]),

                Section::make(__('In-App Player Transcoding'))
                    ->description(__('Select the default transcoding profiles used when playing streams in the in-app player.'))
                    ->columnSpanFull()
                    ->columns(5)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Select::make('default_stream_profile_id')
                            ->label(__('Default Live Transcoding Profile'))
                            ->searchable()
                            ->options(function () {
                                return StreamProfile::where('user_id', auth()->id())->pluck('name', 'id');
                            })
                            ->hintAction(
                                Action::make('manage_profiles')
                                    ->label(__('Manage Profiles'))
                                    ->icon('heroicon-o-arrow-top-right-on-square')
                                    ->iconPosition('after')
                                    ->size('sm')
                                    ->url('/stream-profiles')
                                    ->openUrlInNewTab(false)
                            )
                            ->columnSpan(2)
                            ->helperText(__('The default transcoding profile used by the in-app player for Live content. A per-channel stream profile (if set) takes priority over this. Leave empty to disable transcoding (some streams may not be playable in the player).')),
                        Select::make('default_vod_stream_profile_id')
                            ->label(__('VOD and Series Transcoding Profile'))
                            ->searchable()
                            ->options(function () {
                                return StreamProfile::where('user_id', auth()->id())->pluck('name', 'id');
                            })
                            ->hintAction(
                                Action::make('manage_profiles')
                                    ->label(__('Manage Profiles'))
                                    ->icon('heroicon-o-arrow-top-right-on-square')
                                    ->iconPosition('after')
                                    ->size('sm')
                                    ->url('/stream-profiles')
                                    ->openUrlInNewTab(false)
                            )
                            ->columnSpan(2)
                            ->helperText(__('The default transcoding profile used by the in-app player for VOD/Series content. A per-channel stream profile (if set) takes priority over this. Leave empty to disable transcoding (some streams may not be playable in the player).')),
                        TextInput::make('max_concurrent_floating_players')
                            ->label(__('Max Concurrent Players'))
                            ->hintIcon(
                                'heroicon-m-question-mark-circle',
                                tooltip: __('Set to 0 (or clear value) for unlimited.')
                            )
                            ->numeric()
                            ->placeholder(0)
                            ->minValue(0)
                            ->step(1)
                            ->helperText(__('Maximum number of players that can be open at once.')),
                    ]),
            ]);
    }
}
