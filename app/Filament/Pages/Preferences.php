<?php

namespace App\Filament\Pages;

use App\Filament\CopilotTools\EpgChannelMatcherTool;
use App\Filament\CopilotTools\EpgMappingApplyTool;
use App\Filament\CopilotTools\EpgMappingStateTool;
use App\Filament\CopilotTools\SearchDocsTool;
use App\Filament\Resources\Assets\AssetResource;
use App\Jobs\RestartQueue;
use App\Models\StreamFileSetting;
use App\Models\StreamProfile;
use App\Rules\Cron;
use App\Rules\ValidDateFormat;
use App\Services\DateFormatService;
use App\Services\M3uProxyService;
use App\Services\PlaylistService;
use App\Settings\GeneralSettings;
use Cron\CronExpression;
use Dom\Text;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
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
use Ramsey\Uuid\Guid\Fields;

class Preferences extends SettingsPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $settings = GeneralSettings::class;

    public static function getNavigationLabel(): string
    {
        return __('Settings');
    }

    public function getTitle(): string
    {
        return __('Settings');
    }

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
                    ->label(__('Clear Expired Logo Cache'))
                    ->action(fn () => Artisan::call('app:logo-cleanup --force'))
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Expired logo cache cleared'))
                            ->body(__('Expired logo cache files were removed successfully.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->color('warning')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-trash')
                    ->modalIcon('heroicon-o-trash')
                    ->modalDescription(__('Only expired logo cache entries (those older than 30 days). If permanent cache is enabled, nothing will be removed.'))
                    ->modalSubmitActionLabel(__('Clear expired cache')),
                Action::make('Clear Logo Cache')
                    ->label(__('Clear All Logo Cache'))
                    ->action(fn () => Artisan::call('app:logo-cleanup --force --all'))
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Logo cache cleared'))
                            ->body(__('The logo cache has been cleared. Logos will be fetched again on next request wherever logo proxy is enabled.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->color('danger')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-exclamation-triangle')
                    ->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalDescription(__('Clearing the logo cache will remove all cached logo images. If permanent cache is enabled, it will be ignored. This action cannot be undone.'))
                    ->modalSubmitActionLabel(__('I understand, clear now')),
                Action::make('Reset Queue')
                    ->label(__('Reset Queue'))
                    ->action(function () {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new RestartQueue);
                    })
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Queue reset'))
                            ->body(__('The queue workers have been restarted and any pending jobs flushed. You may need to manually sync any Playlists or EPGs that were in progress.'))
                            ->duration(10000)
                            ->send();
                    })
                    ->color('danger')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-exclamation-triangle')
                    ->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalDescription(__('Resetting the queue will restart the queue workers and flush any pending jobs. Any syncs or background processes will be stopped and removed. Only perform this action if you are having sync issues.'))
                    ->modalSubmitActionLabel(__('I understand, reset now')),
            ])->button()->color('gray')->label(__('Actions')),
        ];
    }

    /** Preset date format strings available in the select. */
    private const DATE_FORMAT_PRESETS = [
        'Y-m-d H:i:s',
        'd/m/Y H:i',
        'D, d M Y H:i:s',
        'M j, Y g:i A',
        'g:i A m/d/Y',
    ];

    /**
     * Populate virtual form fields (date_format_preset / date_format_custom)
     * from the stored date_format setting value before the form is filled.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $dateFormat = $data['date_format'] ?? null;

        if ($dateFormat && ! in_array($dateFormat, self::DATE_FORMAT_PRESETS, true)) {
            $data['date_format_preset'] = '__custom__';
            $data['date_format_custom'] = $dateFormat;
        } else {
            $data['date_format_preset'] = $dateFormat ?? 'Y-m-d H:i:s';
            $data['date_format_custom'] = null;
        }

        return $data;
    }

    /**
     * Resolve the actual format string from the virtual fields before saving
     * and strip transient keys that do not exist on GeneralSettings.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $preset = $data['date_format_preset'] ?? 'Y-m-d H:i:s';

        if ($preset === '__custom__') {
            $data['date_format'] = ! empty($data['date_format_custom'])
                ? $data['date_format_custom']
                : 'Y-m-d H:i:s';
        } else {
            $data['date_format'] = in_array($preset, self::DATE_FORMAT_PRESETS, true)
                ? $preset
                : 'Y-m-d H:i:s';
        }

        // Remove transient fields that are not in GeneralSettings
        unset($data['date_format_preset'], $data['date_format_custom']);

        // Re-index repeater array (Filament uses string keys internally)
        if (isset($data['copilot_quick_actions']) && is_array($data['copilot_quick_actions'])) {
            $data['copilot_quick_actions'] = array_values($data['copilot_quick_actions']);
        }

        return $data;
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
                        Tab::make(__('General'))
                            ->schema([
                                Section::make(__('Layout & Display Options'))
                                    ->schema([
                                        Grid::make()
                                            ->columnSpanFull()
                                            ->columns(2)
                                            ->schema([
                                                Toggle::make('show_breadcrumbs')
                                                    ->label(__('Show breadcrumbs'))
                                                    ->helperText(__('Show breadcrumbs under the page titles')),
                                                Toggle::make('output_wan_address')
                                                    ->label(__('Output WAN address in menu'))
                                                    ->helperText(__('When enabled, the application will output the WAN address of the server m3u-editor is currently running on.'))
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
                                                    ->label(__('Navigation position'))
                                                    ->helperText(__('Choose the position of primary navigation'))
                                                    ->options([
                                                        'left' => 'Left',
                                                        'top' => 'Top',
                                                    ]),
                                                Select::make('content_width')
                                                    ->label(__('Max width of the page content'))
                                                    ->options([
                                                        Width::ScreenMedium->value => 'Medium',
                                                        Width::ScreenLarge->value => 'Large',
                                                        Width::ScreenExtraLarge->value => 'XL',
                                                        Width::ScreenTwoExtraLarge->value => '2XL',
                                                        Width::Full->value => 'Full',
                                                    ]),
                                            ]),
                                        Grid::make()
                                            ->columnSpanFull()
                                            ->columns(2)
                                            ->schema([
                                                TextInput::make('app_timezone')
                                                    ->label(__('Application Timezone'))
                                                    ->placeholder(__('UTC'))
                                                    ->helperText(__('Override the application timezone. Leave empty to use the server default (UTC). Takes effect for all date/time output throughout the app.'))
                                                    ->disabled(fn () => ! empty(config('dev.timezone')))
                                                    ->hint(fn () => ! empty(config('dev.timezone')) ? 'Already set by environment variable!' : null)
                                                    ->dehydrated(fn () => empty(config('dev.timezone')))
                                                    ->afterStateHydrated(function (TextInput $component, $state) {
                                                        if (! empty(config('dev.timezone'))) {
                                                            $component->state(config('dev.timezone'));
                                                        }
                                                    })
                                                    ->hintAction(
                                                        Action::make('view_timezones')
                                                            ->label(__('Accepted Values'))
                                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                                            ->iconPosition('after')
                                                            ->size('sm')
                                                            ->url('https://www.php.net/manual/en/timezones.php')
                                                            ->openUrlInNewTab(true)
                                                    ),
                                                Select::make('date_format_preset')
                                                    ->label(__('Date Format'))
                                                    ->options([
                                                        'Y-m-d H:i:s' => 'Default — '.date('Y-m-d H:i:s', mktime(14, 30, 0, 1, 15, 2024)),
                                                        'd/m/Y H:i' => 'Short — '.date('d/m/Y H:i', mktime(14, 30, 0, 1, 15, 2024)),
                                                        'D, d M Y H:i:s' => 'Long — '.date('D, d M Y H:i:s', mktime(14, 30, 0, 1, 15, 2024)),
                                                        'M j, Y g:i A' => 'Human Readable — '.date('M j, Y g:i A', mktime(14, 30, 0, 1, 15, 2024)),
                                                        'g:i A m/d/Y' => '12-Hour AM/PM — '.date('g:i A m/d/Y', mktime(14, 30, 0, 1, 15, 2024)),
                                                        '__custom__' => 'Custom...',
                                                    ])
                                                    ->default('Y-m-d H:i:s')
                                                    ->live()
                                                    ->helperText(__('Format applied to dates throughout the application (e.g. next sync, last synced).')),
                                            ]),
                                        Grid::make()
                                            ->columnSpanFull()
                                            ->columns(2)
                                            ->schema([
                                                TextInput::make('date_format_custom')
                                                    ->label(__('Custom Date Format String'))
                                                    ->placeholder(__('e.g. d/m/Y H:i:s'))
                                                    ->live(debounce: 500)
                                                    ->rules([new ValidDateFormat])
                                                    ->helperText(function (Get $get): string {
                                                        $fmt = $get('date_format_custom');

                                                        if (! $fmt) {
                                                            return 'Enter a PHP date format string. See the link above for accepted characters.';
                                                        }

                                                        try {
                                                            return 'Preview: '.date($fmt, mktime(14, 30, 0, 1, 15, 2024));
                                                        } catch (\Throwable) {
                                                            return 'Invalid format string.';
                                                        }
                                                    })
                                                    ->hintAction(
                                                        Action::make('view_date_formats')
                                                            ->label(__('PHP Date Formats'))
                                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                                            ->iconPosition('after')
                                                            ->size('sm')
                                                            ->url('https://www.php.net/manual/en/datetime.format.php')
                                                            ->openUrlInNewTab(true)
                                                    )
                                                    ->visible(fn (Get $get): bool => $get('date_format_preset') === '__custom__'),
                                            ]),

                                    ]),
                                Section::make(__('Allowed Playlist Domains'))
                                    ->description(__('Restrict playlist URLs to specific domains. Leave empty to allow all domains.'))
                                    ->schema([
                                        TagsInput::make('allowed_urls')
                                            ->label(__('Allowed domains'))
                                            ->columnSpanFull()
                                            ->placeholder(fn () => config('dev.allowed_playlist_domains') ? null : '*.example.com*')
                                            ->helperText(__('List of allowed domains (supports wildcards, e.g. *.example.com*). Press [tab] or [return] to add item. When set, playlist URLs must match one of these patterns.'))
                                            ->disabled(fn () => ! empty(config('dev.allowed_playlist_domains')))
                                            ->hint(fn () => ! empty(config('dev.allowed_playlist_domains')) ? 'Already set by environment variable!' : null)
                                            ->default(fn () => ! empty(config('dev.allowed_playlist_domains'))
                                                ? array_map('trim', explode(',', config('dev.allowed_playlist_domains')))
                                                : [])
                                            ->afterStateHydrated(function (TagsInput $component, $state) {
                                                if (! empty(config('dev.allowed_playlist_domains'))) {
                                                    $component->state(array_map('trim', explode(',', config('dev.allowed_playlist_domains'))));
                                                }
                                            })
                                            ->dehydrated(fn () => empty(config('dev.allowed_playlist_domains'))),
                                    ]),
                                Section::make(__('Xtream API Panel Settings'))
                                    ->schema([
                                        Grid::make()
                                            ->columnSpanFull()
                                            ->columns(2)
                                            ->schema([
                                                TextInput::make('xtream_api_details.http_port')
                                                    ->label(__('HTTP Port'))
                                                    ->numeric()
                                                    ->placeholder(fn () => config('app.port', '80'))
                                                    ->helperText(__('Returned as "server_info.http_port" in "player_api.php" responses. Leave empty to use APP_PORT (default).')),
                                                TextInput::make('xtream_api_details.https_port')
                                                    ->label(__('HTTPS Port'))
                                                    ->numeric()
                                                    ->placeholder(__('443'))
                                                    ->helperText(__('Returned as "server_info.https_port" in "player_api.php" responses. Leave empty to use 443 (default).')),
                                                Textarea::make('xtream_api_message')
                                                    ->label(__('Xtream API panel message'))
                                                    ->helperText(__('Returned as "user_info.message" in "player_api.php" responses.'))
                                                    ->rows(3)
                                                    ->columnSpanFull()
                                                    ->default(''),

                                            ]),
                                    ]),
                            ]),
                        Tab::make(__('Proxy'))
                            ->schema([
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
                                                tooltip: 'If you would like the proxied streams to use a different base URL than the configured app url. Useful for local network access or when using a TLD for access, but prefer LAN address for streaming.'
                                            )
                                            ->disabled(fn () => ! empty(config('proxy.url_override')))
                                            ->hint(fn () => ! empty(config('proxy.url_override')) ? 'Already set by environment variable!' : null)
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
                                                tooltip: 'When enabled, the application will resolve the public-facing proxy URL using the incoming request host/scheme instead of the APP_URL or the configured Override URL (if set).'
                                            )
                                            ->helperText(__('Useful for multi-host access (VPN/Tailscale/etc.)'))
                                            ->default(false),

                                        Toggle::make('proxy_stop_oldest_on_limit')
                                            ->label(__('Stop oldest stream when limit reached'))
                                            ->columnSpanFull()
                                            ->hintIcon(
                                                'heroicon-m-question-mark-circle',
                                                tooltip: 'When a playlist has a connection limit and it\'s reached, enabling this will automatically stop the oldest active stream to make room for the new request. This is useful for single-connection providers where you want instant channel switching. Note: This may cause issues if multiple clients share the same playlist - the newest request always wins.'
                                            )
                                            ->default(false)
                                            ->helperText(__('Enable to allow new stream requests to automatically stop the oldest stream when a playlist reaches its connection limit. Disabled by default.')),

                                        Toggle::make('url_override_include_logos')
                                            ->label(__('Include logos in proxy URL override'))
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
                                                tooltip: 'This should be the LAN address of the editor for the proxy to access. The resolver URL is used for advanced failover logic, webhook registration for pooled providers, and Network Broadcasting features. This URL should point to the m3u-editor instance that the proxy can access.'
                                            )
                                            ->prefixIcon('heroicon-m-link')
                                            ->disabled(fn () => ! empty(config('proxy.m3u_resolver_url')))
                                            ->hint(fn () => ! empty(config('proxy.m3u_resolver_url')) ? 'Already set by environment variable!' : null)
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
                                                tooltip: 'When enabled, the proxy will make a call to the editor to determine which failover to use based on available capacity. When disabled, a list of failover URLs will be sent to the proxy and it will loop through them without any capacity checks when a stream failure occurs.'
                                            )
                                            ->live()
                                            ->disabled(fn () => ! empty(config('proxy.m3u_resolver_url')))
                                            ->hint(fn () => ! empty(config('proxy.m3u_resolver_url')) ? 'Already set by environment variable!' : null)
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
                                                        tooltip: 'When enabled, playlists returning specific HTTP status codes will be temporarily marked as invalid during failover resolution. This enables account-level failover by skipping all channels from a failing playlist/account.'
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
                                                tooltip: 'When enabled, the proxy will monitor live streams for silent audio. If silence is detected for the configured number of consecutive checks, a failover is triggered.'
                                            )
                                            ->helperText(__('Automatically trigger failover when a stream\\\'s audio goes silent. Disabled by default.')),

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
                                                        tooltip: 'Audio level below which audio is considered silent. -50 dB is a good default; raise to -40 dB for stricter detection.'
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
                                            ->helperText(__('The default transcoding profile used for the in-app player for Live content. Leave empty to disable transcoding (some streams may not be playable in the player).')),
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
                                            ->helperText(__('The default transcoding profile used for the in-app player for VOD/Series content. Leave empty to disable transcoding (some streams may not be playable in the player).')),
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
                                Section::make(__('MediaFlow Proxy'))
                                    ->description(__('If you have MediaFlow Proxy installed, you can use it to proxy your m3u editor playlist streams. When enabled, the app will auto-generate URLs for you to use via MediaFlow Proxy.'))
                                    ->columnSpan('full')
                                    ->columns(3)
                                    ->collapsible()
                                    ->collapsed(true)
                                    ->headerActions([
                                        Action::make('mfproxy_git')
                                            ->label(__('GitHub'))
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->color('gray')
                                            ->size('sm')
                                            ->url('https://github.com/mhdzumair/mediaflow-proxy')
                                            ->openUrlInNewTab(true),
                                    ])
                                    ->schema([
                                        TextInput::make('mediaflow_proxy_url')
                                            ->label(__('Proxy URL'))
                                            ->columnSpan(1)
                                            ->placeholder(__('socks5://user:pass@host:port or http://user:pass@host:port')),
                                        TextInput::make('mediaflow_proxy_port')
                                            ->label(__('Proxy Port (Alternative)'))
                                            ->numeric()
                                            ->columnSpan(1)
                                            ->helperText(__('Alternative port if not specified in URL. Not commonly used.')),

                                        TextInput::make('mediaflow_proxy_password')
                                            ->label(__('Proxy Password (Alternative)'))
                                            ->columnSpan(1)
                                            ->password()
                                            ->revealable()
                                            ->helperText(__('Alternative password if not specified in URL. Not commonly used.')),
                                        Toggle::make('mediaflow_proxy_playlist_user_agent')
                                            ->label(__('Use playlist user agent'))
                                            ->inline(false)
                                            ->live()
                                            ->label(__('Use Proxy User Agent for Playlists (M3U8/MPD)'))
                                            ->helperText(__('If enabled, the User Agent will also be used for fetching playlist files. Otherwise, the default FFmpeg User Agent is used for playlists.')),
                                        TextInput::make('mediaflow_proxy_user_agent')
                                            ->label(__('Proxy User Agent for Media Streams'))
                                            ->placeholder(__('VLC/3.0.21 LibVLC/3.0.21'))
                                            ->columnSpan(2),
                                    ]),

                            ]),

                        Tab::make(__('Sync Options'))
                            ->schema([
                                Section::make(__('Provider Rate Limiting & Concurrency'))
                                    ->description(__('Control request concurrency for parallel processing and add delays between requests to avoid provider rate limiting.'))
                                    ->columnSpan('full')
                                    ->columns(4)
                                    ->collapsible(false)
                                    ->schema([
                                        Toggle::make('enable_provider_request_delay')
                                            ->label(__('Enable request delay'))
                                            ->live()
                                            ->inline(false)
                                            ->columnSpan(2)
                                            ->helperText(__('When enabled, a delay will be added between requests to the provider during playlist and EPG syncs and other stream processing tasks.')),
                                        TextInput::make('provider_max_concurrent_requests')
                                            ->label(__('Max concurrent requests'))
                                            ->integer()
                                            ->columnSpan(2)
                                            ->required()
                                            ->hintIcon(
                                                'heroicon-m-question-mark-circle',
                                                tooltip: 'Lower values (1-2) are safer but slower. Set to 1 to process requests sequentially.'
                                            )
                                            ->minValue(1)
                                            ->default(2)
                                            ->helperText(__('Maximum number of simultaneous requests allowed. Also controls the level of parallelism for batch operations such as stream probing and channel scrubbing.')),
                                        TextInput::make('provider_request_delay_ms')
                                            ->label(__('Request delay'))
                                            ->integer()
                                            ->required()
                                            ->hintIcon(
                                                'heroicon-m-question-mark-circle',
                                                tooltip: 'Recommended: 500-2000ms. Higher values reduce load on provider but increase sync time.'
                                            )
                                            ->columnSpan(1)
                                            ->minValue(100)
                                            ->maxValue(10000)
                                            ->step(100)
                                            ->default(500)
                                            ->suffix('ms')
                                            ->hidden(fn ($get) => ! $get('enable_provider_request_delay'))
                                            ->helperText(__('Delay in milliseconds between requests. 0 for no delay.')),
                                    ]),
                                Section::make(__('Sync Invalidation'))
                                    ->description(__('Prevent sync from proceeding if conditions are met.'))
                                    ->columnSpan('full')
                                    ->columns(3)
                                    ->collapsible(false)
                                    ->schema([
                                        Toggle::make('invalidate_import')
                                            ->label(__('Enable sync invalidation'))
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
                                            ->label(__('Sync invalidation threshold'))
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
                                            ->helperText(__('If sync will remove more than this number of channels, the sync will be canceled.')),
                                    ]),
                                Section::make(__('Series stream file settings'))
                                    ->description(__('Select a Stream File Setting for series .strm file generation.'))
                                    ->columnSpan('full')
                                    ->columns(1)
                                    ->collapsible(false)
                                    ->schema([
                                        Select::make('default_series_stream_file_setting_id')
                                            ->label(__('Default Series Stream File Setting'))
                                            ->searchable()
                                            ->hintIcon(
                                                'heroicon-m-question-mark-circle',
                                                tooltip: 'Stream File Settings can be created and managed in Playlist > Stream File Settings. Settings can be overridden at the Category level or per-Series.'
                                            )
                                            ->options(function () {
                                                return StreamFileSetting::where('user_id', auth()->id())
                                                    ->forSeries()
                                                    ->pluck('name', 'id');
                                            })
                                            ->hintAction(
                                                Action::make('manage_series_settings')
                                                    ->label(__('Manage Stream File Settings'))
                                                    ->icon('heroicon-o-arrow-top-right-on-square')
                                                    ->iconPosition('after')
                                                    ->size('sm')
                                                    ->url('/stream-file-settings')
                                                    ->openUrlInNewTab(false)
                                            )
                                            ->helperText(__('Leave empty to disable .strm file generation for series. Priority: Series > Category > Global.')),
                                    ]),
                                Section::make(__('VOD stream file settings'))
                                    ->description(__('Select a Stream File Setting for VOD .strm file generation. '))
                                    ->columnSpan('full')
                                    ->columns(1)
                                    ->collapsible(false)
                                    ->schema([
                                        Select::make('default_vod_stream_file_setting_id')
                                            ->label(__('Default VOD Stream File Setting'))
                                            ->searchable()
                                            ->hintIcon(
                                                'heroicon-m-question-mark-circle',
                                                tooltip: 'Stream File Settings can be created and managed in Playlist > Stream File Settings. Settings can be overridden at the Group level or per-VOD channel.'
                                            )
                                            ->options(function () {
                                                return StreamFileSetting::where('user_id', auth()->id())
                                                    ->forVod()
                                                    ->pluck('name', 'id');
                                            })
                                            ->hintAction(
                                                Action::make('manage_vod_settings')
                                                    ->label(__('Manage Stream File Settings'))
                                                    ->icon('heroicon-o-arrow-top-right-on-square')
                                                    ->iconPosition('after')
                                                    ->size('sm')
                                                    ->url('/stream-file-settings')
                                                    ->openUrlInNewTab(false)
                                            )
                                            ->helperText(__('Leave empty to disable .strm file generation for VOD. Priority: VOD > Group > Global.')),
                                    ]),
                            ]),

                        Tab::make(__('Assets'))
                            ->schema([
                                Section::make(__('Logo Cache'))
                                    ->description(__('Manage logo cache behavior and storage used by logo proxy URLs.'))
                                    ->columns(1)
                                    ->headerActions([
                                        Action::make('manage_assets')
                                            ->label(__('Manage Assets'))
                                            ->color('gray')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url(AssetResource::getUrl('index')),
                                        Action::make('view_repo')
                                            ->label(__('View Logo Repository'))
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('/logo-repository')
                                            ->hidden(fn ($get) => ! $get('logo_repository_enabled'))
                                            ->openUrlInNewTab(true),
                                    ])
                                    ->schema([
                                        Toggle::make('logo_cache_permanent')
                                            ->label(__('Keep cache permanently (disable expiry cleanup)'))
                                            ->helperText(__('When enabled, expired cache cleanup will skip deletion. You can still refresh/clear cache manually.')),
                                        Toggle::make('logo_repository_enabled')
                                            ->label(__('Enable Logo Repository endpoint'))
                                            ->live()
                                            ->helperText(__('When enabled, /logo-repository endpoints are publicly accessible for apps like UHF.')),

                                    ]),
                                Section::make(__('Placeholder Images'))
                                    ->description(__('Override app-wide placeholder images for logos, episode previews, and VOD/Series poster fallbacks.'))
                                    ->columns(3)
                                    ->schema([
                                        FileUpload::make('logo_placeholder_url')
                                            ->label(__('Logo placeholder'))
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
                                            ->label(__('Episode preview placeholder'))
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
                                            ->label(__('VOD/Series poster placeholder'))
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

                        Tab::make(__('Backups'))
                            ->schema([
                                Section::make(__('Automated backups'))
                                    ->schema([
                                        Toggle::make('auto_backup_database')
                                            ->label(__('Enable Automatic Database Backups'))
                                            ->live()
                                            ->helperText(__('When enabled, automatic database backups will be created based on the specified schedule.')),
                                        Group::make()
                                            ->columnSpanFull()
                                            ->columns(2)
                                            ->schema([
                                                TextInput::make('auto_backup_database_schedule')
                                                    ->label(__('Backup Schedule'))
                                                    ->suffix(config('app.timezone'))
                                                    ->rules([new Cron])
                                                    ->live()
                                                    ->hintAction(
                                                        Action::make('view_cron_example')
                                                            ->label(__('CRON Example'))
                                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                                            ->iconPosition('after')
                                                            ->size('sm')
                                                            ->url('https://crontab.guru')
                                                            ->openUrlInNewTab(true)
                                                    )
                                                    ->helperText(fn ($get) => CronExpression::isValidExpression($get('auto_backup_database_schedule'))
                                                        ? 'Next scheduled backup: '.(new CronExpression($get('auto_backup_database_schedule')))->getNextRunDate()->format(app(DateFormatService::class)->getFormat())
                                                        : 'Specify the CRON schedule for automatic backups, e.g. "0 3 * * *".'),
                                                TextInput::make('auto_backup_database_max_backups')
                                                    ->label(__('Max Backups'))
                                                    ->type('number')
                                                    ->minValue(0)
                                                    ->helperText(__('Specify the maximum number of backups to keep. Enter 0 for no limit.')),
                                            ])->hidden(fn ($get) => ! $get('auto_backup_database')),
                                    ]),
                            ]),

                        Tab::make(__('SMTP'))
                            ->columns(2)
                            ->schema([
                                Section::make(__('SMTP Settings'))
                                    ->description(__('Configure SMTP settings to send emails from the application.'))
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->headerActions([
                                        Action::make('send_test_email')
                                            ->label(__('Send Test Email'))
                                            ->icon('heroicon-o-envelope')
                                            ->iconPosition('after')
                                            ->color('gray')
                                            ->size('sm')
                                            ->modalWidth('md')
                                            ->schema([
                                                TextInput::make('to_email')
                                                    ->label(__('To Email Address'))
                                                    ->email()
                                                    ->required()
                                                    ->placeholder(__('Enter To Email Address'))
                                                    ->helperText(__('A test email will be sent to this address using the entered SMTP settings.')),
                                            ])
                                            ->action(function (array $data, $get): void {
                                                try {
                                                    // Get SMTP settings from the form state
                                                    $formState = $this->form->getState();

                                                    // Make sure all required fields are present
                                                    if (empty($formState['smtp_host']) || empty($formState['smtp_port']) || empty($formState['smtp_username']) || empty($formState['smtp_password'])) {
                                                        Notification::make()
                                                            ->danger()
                                                            ->title(__('Missing SMTP Fields'))
                                                            ->body(__('Please fill in all required SMTP fields before sending a test email.'))
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
                                                        ->title(__('Test Email Sent'))
                                                        ->body('Test email sent successfully to '.$data['to_email'])
                                                        ->send();
                                                } catch (Exception $e) {
                                                    Notification::make()
                                                        ->danger()
                                                        ->title(__('Error Sending Test Email'))
                                                        ->body($e->getMessage())
                                                        ->send();
                                                }
                                            }),
                                    ])
                                    ->schema([
                                        TextInput::make('smtp_host')
                                            ->label(__('SMTP Host'))
                                            ->placeholder(__('Enter SMTP Host'))
                                            ->requiredWith('smtp_port')
                                            ->helperText(__('Required to send emails.')),
                                        TextInput::make('smtp_port')
                                            ->label(__('SMTP Port'))
                                            ->placeholder(__('Enter SMTP Port'))
                                            ->requiredWith('smtp_host')
                                            ->numeric()
                                            ->helperText(__('Required to send emails.')),
                                        TextInput::make('smtp_username')
                                            ->label(__('SMTP Username'))
                                            ->placeholder(__('Enter SMTP Username'))
                                            ->requiredWith('smtp_password')
                                            ->helperText(__('Required to send emails, if your provider requires authentication.')),
                                        TextInput::make('smtp_password')
                                            ->label(__('SMTP Password'))
                                            ->revealable()
                                            ->placeholder(__('Enter SMTP Password'))
                                            ->requiredWith('smtp_username')
                                            ->password()
                                            ->helperText(__('Required to send emails, if your provider requires authentication.')),
                                        Select::make('smtp_encryption')
                                            ->label(__('SMTP Encryption'))
                                            ->options([
                                                'tls' => 'TLS',
                                                'ssl' => 'SSL',
                                                null => 'None',
                                            ])
                                            ->placeholder(__('Select encryption type (optional)')),
                                        TextInput::make('smtp_from_address')
                                            ->label(__('SMTP From Address'))
                                            ->placeholder(__('Enter SMTP From Address'))
                                            ->email()
                                            ->helperText(__('The "From" email address for outgoing emails. Defaults to no-reply@m3u-editor.dev.')),
                                    ]),
                            ]),
                        Tab::make(__('TMDB'))
                            ->columns(2)
                            ->schema([
                                Section::make(__('TMDB Integration'))
                                    ->description(__('Configure The Movie Database (TMDB) integration to automatically lookup and populate metadata IDs (TMDB, TVDB, IMDB) for your VOD content and Series.'))
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->headerActions([
                                        Action::make('test_tmdb_connection')
                                            ->label(__('Test Connection'))
                                            ->icon('heroicon-o-signal')
                                            ->iconPosition('after')
                                            ->color('gray')
                                            ->size('sm')
                                            ->action(function ($get): void {
                                                $apiKey = $get('tmdb_api_key');
                                                if (empty($apiKey)) {
                                                    Notification::make()
                                                        ->danger()
                                                        ->title(__('API Key Required'))
                                                        ->body(__('Please enter a TMDB API key to test the connection.'))
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
                                                            ->title(__('Connection Successful'))
                                                            ->body(__('Successfully connected to TMDB API!'))
                                                            ->send();
                                                    } else {
                                                        $error = $response->json('status_message', 'Unknown error');
                                                        Notification::make()
                                                            ->danger()
                                                            ->title(__('Connection Failed'))
                                                            ->body("TMDB API returned an error: {$error}")
                                                            ->send();
                                                    }
                                                } catch (Exception $e) {
                                                    Notification::make()
                                                        ->danger()
                                                        ->title(__('Connection Failed'))
                                                        ->body('Could not connect to TMDB API: '.$e->getMessage())
                                                        ->send();
                                                }
                                            }),
                                        Action::make('get_tmdb_api_key')
                                            ->label(__('Get API Key'))
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('https://www.themoviedb.org/settings/api')
                                            ->openUrlInNewTab(true),
                                    ])
                                    ->schema([
                                        TextInput::make('tmdb_api_key')
                                            ->label(__('TMDB API Key'))
                                            ->placeholder(__('Enter your TMDB API Key (v3 auth)'))
                                            ->password()
                                            ->revealable()
                                            ->helperText(__('Your TMDB API key (v3 auth). You can get one for free at themoviedb.org.')),
                                        Select::make('tmdb_language')
                                            ->label(__('Search Language'))
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
                                            ->helperText(__('Preferred language for TMDB searches.')),
                                        Toggle::make('tmdb_auto_lookup_on_import')
                                            ->label(__('Auto-lookup on metadata fetch'))
                                            ->helperText(__('Automatically lookup TMDB IDs when fetching metadata for VOD and Series. This may slow down imports and metadata fetching for large playlists. Will only be fetched for enabled items.'))
                                            ->default(false),
                                        Toggle::make('tmdb_auto_create_groups')
                                            ->label(__('Auto-create groups/categories from TMDB genres'))
                                            ->helperText(__('When enabled, TMDB metadata fetching will automatically create new groups (for VOD) and categories (for Series) based on TMDB genres. When disabled, only existing groups/categories will be used.'))
                                            ->default(false),
                                        TextInput::make('tmdb_rate_limit')
                                            ->label(__('Rate Limit (requests/second)'))
                                            ->placeholder(__('40'))
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(50)
                                            ->default(40)
                                            ->helperText(__('Maximum TMDB API requests per second. TMDB allows ~40 req/s for free accounts.')),
                                        TextInput::make('tmdb_confidence_threshold')
                                            ->label(__('Match Confidence Threshold (%)'))
                                            ->placeholder(__('80'))
                                            ->numeric()
                                            ->minValue(50)
                                            ->maxValue(100)
                                            ->default(80)
                                            ->helperText(__('Minimum title similarity percentage (50-100) required to accept a match. Higher values = stricter matching.')),
                                    ]),
                            ]),
                        Tab::make(__('API'))
                            ->schema([
                                Section::make(__('API Settings'))
                                    ->headerActions([
                                        Action::make('manage_api_keys')
                                            ->label(__('Manage API Tokens'))
                                            ->color('gray')
                                            ->icon('heroicon-s-key')
                                            ->iconPosition('before')
                                            ->size('sm')
                                            ->url('/personal-access-tokens'),
                                        Action::make('view_api_docs')
                                            ->label(__('API Docs'))
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('/docs/api')
                                            ->openUrlInNewTab(true),
                                    ])->schema([
                                        Toggle::make('show_api_docs')
                                            ->label(__('Allow access to API docs'))
                                            ->helperText(__('When enabled you can access the API documentation using the "API Docs" button. When disabled, the docs endpoint will return a 403 (Unauthorized). NOTE: The API will respond regardless of this setting. You do not need to enable it to use the API.')),
                                    ]),
                            ]),
                        Tab::make(__('AI Copilot'))
                            ->icon('heroicon-o-sparkles')
                            ->schema([
                                Section::make(__('AI Copilot'))
                                    ->description(__('You will need to save and refresh the page after changing settings for them to take effect.'))
                                    ->schema([
                                        Toggle::make('copilot_enabled')
                                            ->label(__('Enable AI Copilot'))
                                            ->helperText(__('When enabled and configured, the AI Copilot assistant will appear in the top navigation bar.'))
                                            ->live(),
                                        Toggle::make('copilot_mgmt_enabled')
                                            ->label(__('Enable AI Copilot Management'))
                                            ->helperText(__('Enables audit log, custom rate limits, conversation history, and other management features for the AI Copilot assistant.'))
                                            ->visible(fn (Get $get): bool => (bool) $get('copilot_enabled')),
                                    ]),
                                Section::make(__('AI Provider'))
                                    ->description(__('Select your AI provider and configure the API credentials.'))
                                    ->visible(fn (Get $get): bool => (bool) $get('copilot_enabled'))
                                    ->schema([
                                        Grid::make()
                                            ->columns(2)
                                            ->schema([
                                                Select::make('copilot_provider')
                                                    ->label(__('Provider'))
                                                    ->searchable()
                                                    ->options([
                                                        'openai' => 'OpenAI',
                                                        'anthropic' => 'Anthropic',
                                                        'gemini' => 'Google Gemini',
                                                        'mistral' => 'Mistral',
                                                        'groq' => 'Groq',
                                                        'deepseek' => 'DeepSeek',
                                                        'xai' => 'xAI (Grok)',
                                                        'openrouter' => 'OpenRouter',
                                                        'ollama' => 'Ollama (Local)',
                                                    ])
                                                    ->live()
                                                    ->required(fn (Get $get): bool => (bool) $get('copilot_enabled'))
                                                    ->helperText(__('The AI provider to use for the Copilot assistant.')),
                                                TextInput::make('copilot_model')
                                                    ->label(__('Model'))
                                                    ->placeholder(fn (Get $get): string => match ($get('copilot_provider')) {
                                                        'anthropic' => 'claude-sonnet-4',
                                                        'gemini' => 'gemini-2.5-flash',
                                                        'mistral' => 'mistral-large-latest',
                                                        'groq' => 'llama-3.3-70b-versatile',
                                                        'deepseek' => 'deepseek-chat',
                                                        'xai' => 'grok-3',
                                                        'openrouter' => 'openai/gpt-4o',
                                                        'ollama' => 'llama3',
                                                        default => 'gpt-4o',
                                                    })
                                                    ->required(fn (Get $get): bool => (bool) $get('copilot_enabled'))
                                                    ->helperText(__('The model to use. Leave blank to use the provider default.')),
                                            ]),
                                        TextInput::make('copilot_api_key')
                                            ->label(__('API Key'))
                                            ->password()
                                            ->revealable()
                                            ->dehydrated(fn ($state): bool => filled($state))
                                            ->visible(fn (Get $get): bool => $get('copilot_provider') !== 'ollama')
                                            ->required(fn (Get $get): bool => (bool) $get('copilot_enabled') && $get('copilot_provider') !== 'ollama')
                                            ->helperText(__('Your API key for the selected provider. Stored in the database.')),
                                        TextInput::make('copilot_url')
                                            ->label(__('Base URL'))
                                            ->url()
                                            ->placeholder(fn (Get $get): string => match ($get('copilot_provider')) {
                                                'ollama' => 'http://localhost:11434',
                                                default => 'https://api.openai.com/v1',
                                            })
                                            ->visible(fn (Get $get): bool => in_array($get('copilot_provider'), ['openai', 'ollama']))
                                            ->helperText(__('Override the default API base URL. Leave blank to use the provider default. Useful for self-hosted models or proxy endpoints.')),
                                    ]),
                                Section::make(__('System Prompt'))
                                    ->description(__('The system prompt sent to the AI on every conversation to configure its behaviour.'))
                                    ->visible(fn (Get $get): bool => (bool) $get('copilot_enabled'))
                                    ->schema([
                                        Textarea::make('copilot_system_prompt')
                                            ->label(__('System Prompt'))
                                            ->placeholder(__('You are a helpful AI assistant integrated into m3u editor. You help users manage playlists, EPG data, streams, channels, and other features. Be concise and accurate.'))
                                            ->rows(4)
                                            ->helperText(__('Leave empty to use the default.')),
                                    ]),
                                Section::make(__('Global Tools'))
                                    ->description(__('Tools available to the Copilot assistant across all pages.'))
                                    ->visible(fn (Get $get): bool => (bool) $get('copilot_enabled'))
                                    ->schema([
                                        CheckboxList::make('copilot_global_tools')
                                            ->label(__('Enabled Tools'))
                                            ->bulkToggleable()
                                            ->options([
                                                SearchDocsTool::class => __('Search Documentation'),
                                                EpgMappingStateTool::class => __('EPG Mapper: Mapping State'),
                                                EpgChannelMatcherTool::class => __('EPG Mapper: Channel Matcher'),
                                                EpgMappingApplyTool::class => __('EPG Mapper: Apply Mappings'),
                                            ])
                                            ->afterStateHydrated(function ($component, $state) {
                                                // Strip built-in tools that were saved by older versions.
                                                // They are always registered by ToolRegistry and must not
                                                // appear in the options list, or Filament validation fails.
                                                $validOptions = array_keys($component->getOptions());
                                                $component->state(
                                                    array_values(array_filter(
                                                        (array) $state,
                                                        fn ($v) => \in_array($v, $validOptions, true)
                                                    ))
                                                );
                                            })
                                            ->columns(2)
                                            ->default([
                                                SearchDocsTool::class,
                                            ])
                                            ->helperText(__('Select which additional tools the AI assistant can use. Core tools (navigation, memory) are always available.')),
                                    ]),
                                Section::make(__('Quick Actions'))
                                    ->description(__('Pre-defined prompts displayed as buttons in the Copilot chat window.'))
                                    ->visible(fn (Get $get): bool => (bool) $get('copilot_enabled'))
                                    ->schema([
                                        Repeater::make('copilot_quick_actions')
                                            ->label(__('Quick Actions'))
                                            ->schema([
                                                TextInput::make('label')
                                                    ->label(__('Label'))
                                                    ->required()
                                                    ->placeholder(__('e.g. Help me find a channel')),
                                                Textarea::make('prompt')
                                                    ->label(__('Prompt'))
                                                    ->required()
                                                    ->rows(2)
                                                    ->placeholder(__('e.g. Help me find a channel by name.')),
                                            ])
                                            ->columns(2)
                                            ->addActionLabel(__('Add Quick Action'))
                                            ->reorderable()
                                            ->collapsible()
                                            ->defaultItems(0),
                                    ]),
                            ]),
                        Tab::make(__('Debugging'))
                            ->schema([
                                Section::make(__('Debugging'))
                                    ->headerActions([
                                        Action::make('test_websocket')
                                            ->label(__('Test WebSocket'))
                                            ->icon('heroicon-o-signal')
                                            ->iconPosition('after')
                                            ->color('gray')
                                            ->size('sm')
                                            ->modalWidth('md')
                                            ->schema([
                                                TextInput::make('message')
                                                    ->label(__('Message'))
                                                    ->required()
                                                    ->default('Testing WebSocket connection')
                                                    ->helperText(__('This message will be sent to the WebSocket server, and displayed as a pop-up notification. If you do not see a notification shortly after sending, there is likely an issue with your WebSocket configuration.')),
                                            ])
                                            ->action(function (array $data): void {
                                                Notification::make()
                                                    ->success()
                                                    ->title(__('WebSocket Connection Test'))
                                                    ->body($data['message'])
                                                    ->persistent()
                                                    ->broadcast(auth()->user());
                                            }),
                                        // Action::make('view_logs')
                                        //     ->label(__('View Logs'))
                                        //     ->color('gray')
                                        //     ->icon('heroicon-o-document-text')
                                        //     ->iconPosition('after')
                                        //     ->size('sm')
                                        //     ->url('/logs'),
                                        Action::make('view_queue_manager')
                                            ->label(__('Queue Manager'))
                                            ->icon('heroicon-o-arrow-top-right-on-square')
                                            ->iconPosition('after')
                                            ->size('sm')
                                            ->url('/horizon')
                                            ->openUrlInNewTab(true),
                                    ])->schema([
                                        // Toggle::make('show_logs')
                                        //     ->label(__('Make log files viewable'))
                                        //     ->hintIcon(
                                        //         'heroicon-m-question-mark-circle',
                                        //         tooltip: 'You may need to refresh the page after applying this setting to view the logs. When disabled you will get a 404.'
                                        //     )
                                        //     ->helperText(__('When enabled, there will be an additional navigation item (Logs) to view the log file content.')),
                                        Toggle::make('show_queue_manager')
                                            ->label(__('Allow queue manager access'))
                                            ->helperText(__('When enabled you can access the queue manager using the "Queue Manager" button. When disabled, the queue manager endpoint will return a 403 (Unauthorized).')),
                                    ]),
                            ]),
                    ])->contained(false),
            ]);
    }

    public function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__('Settings saved'))
            ->body(__('Your preferences have been saved successfully.'));
    }
}
