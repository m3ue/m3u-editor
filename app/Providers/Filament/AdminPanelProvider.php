<?php

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Login;
use App\Filament\CopilotTools\EpgMappingStateTool;
use App\Filament\Pages\Backups;
use App\Filament\Pages\CreatePlugin;
use App\Filament\Pages\CustomDashboard;
use App\Filament\Pages\LogViewer;
use App\Filament\Pages\M3uProxyStreamMonitor;
use App\Filament\Pages\PluginsDashboard;
use App\Filament\Pages\Preferences;
use App\Filament\Pages\ReleaseLogs;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\ChannelScrubbers\ChannelScrubberResource;
use App\Filament\Resources\CustomPlaylists\CustomPlaylistResource;
use App\Filament\Resources\EpgChannels\EpgChannelResource;
use App\Filament\Resources\EpgMaps\EpgMapResource;
use App\Filament\Resources\Epgs\EpgResource;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use App\Filament\Resources\MergedEpgs\MergedEpgResource;
use App\Filament\Resources\MergedPlaylists\MergedPlaylistResource;
use App\Filament\Resources\Networks\NetworkResource;
use App\Filament\Resources\PersonalAccessTokens\PersonalAccessTokenResource;
use App\Filament\Resources\PlaylistAliases\PlaylistAliasResource;
use App\Filament\Resources\PlaylistAuths\PlaylistAuthResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\PlaylistViewers\PlaylistViewerResource;
use App\Filament\Resources\PluginInstallReviews\PluginInstallReviewResource;
use App\Filament\Resources\Plugins\PluginResource;
use App\Filament\Resources\PostProcesses\PostProcessResource;
use App\Filament\Resources\Series\SeriesResource;
use App\Filament\Resources\StreamFileSettings\StreamFileSettingResource;
use App\Filament\Resources\StreamProfiles\StreamProfileResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\VodGroups\VodGroupResource;
use App\Filament\Resources\Vods\VodResource;
use App\Filament\Widgets\DiscordWidget;
use App\Filament\Widgets\DocumentsWidget;
use App\Filament\Widgets\DonateCrypto;
use App\Filament\Widgets\KoFiWidget;
use App\Filament\Widgets\PluginsOverviewWidget;
use App\Filament\Widgets\SharedStreamStatsWidget;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\SystemHealthWidget;
use App\Filament\Widgets\UpdateNoticeWidget;
use App\Http\Middleware\DashboardMiddleware;
// use App\Filament\Widgets\PayPalDonateWidget;
use App\Http\Middleware\SeedLocaleFromUser;
use App\Settings\GeneralSettings;
use CraftForge\FilamentLanguageSwitcher\FilamentLanguageSwitcherPlugin;
use EslamRedaDiv\FilamentCopilot\FilamentCopilotPlugin;
use EslamRedaDiv\FilamentCopilot\Tools\GetToolsTool;
use EslamRedaDiv\FilamentCopilot\Tools\ListPagesTool;
use EslamRedaDiv\FilamentCopilot\Tools\ListResourcesTool;
use EslamRedaDiv\FilamentCopilot\Tools\ListWidgetsTool;
use EslamRedaDiv\FilamentCopilot\Tools\RecallTool;
use EslamRedaDiv\FilamentCopilot\Tools\RememberTool;
use EslamRedaDiv\FilamentCopilot\Tools\RunToolTool;
use Exception;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use ShuvroRoy\FilamentSpatieLaravelBackup\FilamentSpatieLaravelBackupPlugin;
use Throwable;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $userPreferences = app(GeneralSettings::class);
        $settings = [
            'navigation_position' => 'left',
            'show_breadcrumbs' => true,
            'content_width' => Width::ScreenLarge,
            'output_wan_address' => false,
            'copilot_enabled' => false,
            'copilot_mgmt_enabled' => false,
            'copilot_api_key' => null,
            'copilot_provider' => null,
            'copilot_model' => null,
            'copilot_system_prompt' => '',
            'copilot_global_tools' => [],
            'copilot_quick_actions' => [],
            'copilot_url' => null,
        ];
        try {
            $envShowWan = config('dev.show_wan_details', false);
            $settings = [
                'navigation_position' => $userPreferences->navigation_position ?? $settings['navigation_position'],
                'show_breadcrumbs' => $userPreferences->show_breadcrumbs ?? $settings['show_breadcrumbs'],
                'content_width' => $userPreferences->content_width ?? $settings['content_width'],
                'output_wan_address' => $envShowWan !== null
                    ? (bool) $envShowWan
                    : (bool) ($userPreferences->output_wan_address ?? $settings['output_wan_address']),
                'copilot_enabled' => $userPreferences->copilot_enabled ?? $settings['copilot_enabled'],
                'copilot_mgmt_enabled' => $userPreferences->copilot_mgmt_enabled ?? $settings['copilot_mgmt_enabled'],
                'copilot_api_key' => $userPreferences->copilot_api_key ?? $settings['copilot_api_key'],
                'copilot_provider' => $userPreferences->copilot_provider ?? $settings['copilot_provider'],
                'copilot_model' => $userPreferences->copilot_model ?? $settings['copilot_model'],
                'copilot_system_prompt' => $userPreferences->copilot_system_prompt ?? $settings['copilot_system_prompt'],
                'copilot_global_tools' => $userPreferences->copilot_global_tools ?? $settings['copilot_global_tools'],
                'copilot_quick_actions' => $userPreferences->copilot_quick_actions ?? $settings['copilot_quick_actions'],
                'copilot_url' => $userPreferences->copilot_url ?? $settings['copilot_url'],
            ];
        } catch (Exception $e) {
            // Ignore
        }
        $adminPanel = $panel
            ->default()
            ->id('admin')
            ->path('')
            // ->topbar(false)
            ->login(Login::class)
            ->loginRouteSlug(trim(config('app.login_path', 'login'), '/') ?? 'login')
            ->profile(EditProfile::class, isSimple: false)
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable(),
            ])
            ->brandName('m3u editor')
            ->brandLogo(fn () => view('filament.admin.logo'))
            ->favicon('/favicon.png')
            ->brandLogoHeight('2.5rem')
            ->databaseNotifications()
            // ->databaseNotificationsPolling('10s')
            ->colors([
                'primary' => Color::Indigo,
                'info' => Color::Sky,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'success' => Color::Emerald,
            ])
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                CustomDashboard::class,
            ])
            // Explicit navigation replaces auto-discovery. When adding a new Resource or Page,
            // register its getNavigationItems() call in the appropriate group below, or it
            // will not appear in the sidebar.
            ->navigation(function (NavigationBuilder $builder): NavigationBuilder {
                return $builder
                    ->items([
                        ...CustomDashboard::getNavigationItems(),
                        ...Preferences::getNavigationItems(),
                        ...UserResource::getNavigationItems(),
                    ])
                    ->groups([
                        NavigationGroup::make(fn () => __('Playlist'))
                            ->icon('heroicon-m-play-pause')
                            ->items([
                                ...PlaylistResource::getNavigationItems(),
                                ...CustomPlaylistResource::getNavigationItems(),
                                ...MergedPlaylistResource::getNavigationItems(),
                                ...PlaylistAliasResource::getNavigationItems(),
                                ...PlaylistViewerResource::getNavigationItems(),
                                ...PlaylistAuthResource::getNavigationItems(),
                                ...StreamFileSettingResource::getNavigationItems(),
                                ...ChannelScrubberResource::getNavigationItems(),
                            ]),
                        NavigationGroup::make(fn () => __('Integrations'))
                            ->icon('heroicon-m-server-stack')
                            ->items([
                                ...MediaServerIntegrationResource::getNavigationItems(),
                                ...NetworkResource::getNavigationItems(),
                            ]),
                        NavigationGroup::make(fn () => __('Live Channels'))
                            ->icon('heroicon-m-tv')
                            ->items([
                                ...GroupResource::getNavigationItems(),
                                ...ChannelResource::getNavigationItems(),
                            ]),
                        NavigationGroup::make(fn () => __('VOD Channels'))
                            ->icon('heroicon-m-film')
                            ->items([
                                ...VodGroupResource::getNavigationItems(),
                                ...VodResource::getNavigationItems(),
                            ]),
                        NavigationGroup::make(fn () => __('Series'))
                            ->icon('heroicon-m-play')
                            ->items([
                                ...CategoryResource::getNavigationItems(),
                                ...SeriesResource::getNavigationItems(),
                            ]),
                        NavigationGroup::make(fn () => __('EPG'))
                            ->icon('heroicon-m-calendar-days')
                            ->items([
                                ...EpgResource::getNavigationItems(),
                                ...MergedEpgResource::getNavigationItems(),
                                ...EpgChannelResource::getNavigationItems(),
                                ...EpgMapResource::getNavigationItems(),
                            ]),
                        NavigationGroup::make(fn () => __('Proxy'))
                            ->icon('heroicon-m-arrows-right-left')
                            ->items([
                                ...StreamProfileResource::getNavigationItems(),
                                ...M3uProxyStreamMonitor::getNavigationItems(),
                            ]),
                        NavigationGroup::make(fn () => __('Plugins'))
                            ->icon('heroicon-m-puzzle-piece')
                            ->items([
                                ...PluginsDashboard::getNavigationItems(),
                                ...PluginResource::getNavigationItems(),
                                ...PluginInstallReviewResource::getNavigationItems(),
                                ...CreatePlugin::getNavigationItems(),
                            ]),
                        NavigationGroup::make(fn () => __('Tools'))
                            ->collapsed()
                            ->icon('heroicon-m-wrench-screwdriver')
                            ->items([
                                ...PersonalAccessTokenResource::getNavigationItems(),
                                ...AssetResource::getNavigationItems(),
                                ...PostProcessResource::getNavigationItems(),
                                ...LogViewer::getNavigationItems(),
                                ...ReleaseLogs::getNavigationItems(),
                                ...Backups::getNavigationItems(),
                                NavigationItem::make('API Docs')
                                    ->label(fn () => __('API Docs').' ↗')
                                    ->url('/docs/api', shouldOpenInNewTab: true)
                                    ->sort(9)
                                    ->icon(null)
                                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false),
                                NavigationItem::make('Queue Manager')
                                    ->label(fn () => __('Queue Manager').' ↗')
                                    ->url('/horizon', shouldOpenInNewTab: true)
                                    ->sort(10)
                                    ->icon(null)
                                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false),
                            ]),
                    ]);
            })
            ->breadcrumbs($settings['show_breadcrumbs'])
            ->widgets([
                UpdateNoticeWidget::class,
                AccountWidget::class,
                DocumentsWidget::class,
                DiscordWidget::class,
                // PayPalDonateWidget::class,
                KoFiWidget::class,
                PluginsOverviewWidget::class,
                // DonateCrypto::class,
                StatsOverview::class,
                // SharedStreamStatsWidget::class,
                // SystemHealthWidget::class,
            ])
            ->plugins(array_filter([
                FilamentSpatieLaravelBackupPlugin::make()
                    ->authorize(fn (): bool => auth()->user()->isAdmin())
                    ->usingPage(Backups::class),
                FilamentLanguageSwitcherPlugin::make()
                    ->locales([
                        ['code' => 'en', 'name' => 'English', 'flag' => 'us'],
                        ['code' => 'fr', 'name' => 'Français', 'flag' => 'fr'],
                        ['code' => 'de', 'name' => 'Deutsch', 'flag' => 'de'],
                        ['code' => 'es', 'name' => 'Español', 'flag' => 'es'],
                        ['code' => 'zh_CN', 'name' => '简体中文', 'flag' => 'cn'],
                    ])
                    ->showFlags(false)
                    ->rememberLocale()
                    ->showOnAuthPages(false),
                $this->buildCopilotPlugin([
                    'copilot_enabled' => $settings['copilot_enabled'],
                    'copilot_mgmt_enabled' => $settings['copilot_mgmt_enabled'],
                    'copilot_api_key' => $settings['copilot_api_key'],
                    'copilot_provider' => $settings['copilot_provider'],
                    'copilot_model' => $settings['copilot_model'],
                    'copilot_system_prompt' => $settings['copilot_system_prompt'],
                    'copilot_global_tools' => $settings['copilot_global_tools'],
                    'copilot_quick_actions' => $settings['copilot_quick_actions'],
                    'copilot_url' => $settings['copilot_url'],
                ]),
            ]))
            ->maxContentWidth($settings['content_width'])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                DashboardMiddleware::class, // Needs to be after StartSession
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SeedLocaleFromUser::class, // Seeds session from DB locale (runs before plugin's SetLocale)
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->viteTheme('resources/css/app.css')
            ->unsavedChangesAlerts()
            ->spa()
            ->spaUrlExceptions(fn (): array => [
                '*/playlist.m3u',
                '*/epg.xml',
                'epgs/*/epg.xml',
                '/logs*',
                // Xtream API endpoints
                'player_api.php*',
                'xmltv.php*',
                'live/*/*/*/*',
                'movie/*/*/*',
                'series/*/*/*/*',
            ]);
        if ($settings['navigation_position'] === 'top') {
            $adminPanel->topNavigation();
        } else {
            $adminPanel->sidebarCollapsibleOnDesktop();
        }

        // Register External IP display in the navigation bar
        if ($settings['output_wan_address']) {
            FilamentView::registerRenderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE, // Place it before the global search
                fn (): string => view('components.external-ip-display')->render()
            );
        }

        // Register OIDC SSO button on the login page
        if (config('services.oidc.enabled')) {
            FilamentView::registerRenderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): string => view('filament.auth.oidc-login-button')->render(),
            );
        }

        // Force password change modal — shown to any authenticated user with must_change_password = true
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_START,
            fn (): string => auth()->check() ? Blade::render("@livewire('force-password-change')") : ''
        );

        // Register our custom app js
        FilamentView::registerRenderHook('panels::body.end', fn () => Blade::render("@vite('resources/js/app.js')"));

        // Return the configured panel
        return $adminPanel;
    }

    /**
     * Build the Copilot plugin from database settings.
     * Returns null when the plugin is disabled or not fully configured.
     */
    /** Default models used when the model field is left blank. */
    private const COPILOT_DEFAULT_MODELS = [
        'openai' => 'gpt-4o',
        'anthropic' => 'claude-sonnet-4',
        'gemini' => 'gemini-2.5-flash',
        'mistral' => 'mistral-large-latest',
        'ollama' => 'llama3',
        'groq' => 'llama-3.3-70b-versatile',
        'deepseek' => 'deepseek-chat',
        'xai' => 'grok-3',
        'openrouter' => 'openai/gpt-4o',
    ];

    /**
     * Build the Copilot plugin from database settings.
     * Returns null when the plugin is disabled or not fully configured.
     */
    private function buildCopilotPlugin(array $s): ?FilamentCopilotPlugin
    {
        // Skip during tests — the settings table is not yet created when panel() runs
        // (RefreshDatabase migrations happen after service provider registration).
        if (app()->environment('testing')) {
            return null;
        }

        try {
            $isConfigured = $s['copilot_enabled']
                && ! empty($s['copilot_provider'])
                && (! empty($s['copilot_api_key']) || $s['copilot_provider'] === 'ollama');

            if (! $isConfigured) {
                return null;
            }

            $model = $s['copilot_model']
                ?: (self::COPILOT_DEFAULT_MODELS[$s['copilot_provider']] ?? 'gpt-4o');

            $provider = $s['copilot_provider'];

            // Write the user-configured API key into the Laravel AI provider config so
            // every gateway (Gemini, Groq, Anthropic, etc.) reads the correct key instead
            // of falling back to the empty env var on a fresh installation.
            if (! empty($s['copilot_api_key'])) {
                config(["ai.providers.{$provider}.key" => $s['copilot_api_key']]);
            }

            // Custom base URL — supported for OpenAI-compatible and Ollama endpoints.
            if (! empty($s['copilot_url']) && in_array($provider, ['openai', 'ollama'], true)) {
                config(["ai.providers.{$provider}.url" => $s['copilot_url']]);
            }

            return FilamentCopilotPlugin::make()
                ->provider($provider)
                ->model($model)
                ->systemPrompt($s['copilot_system_prompt'] ?: 'You are a helpful AI assistant integrated into m3u editor. You help users manage playlists, EPG data, streams, channels, and other media features. Be concise and accurate.')
                ->globalTools($this->filterBuiltInTools($s['copilot_global_tools'] ?? []))
                ->quickActions($this->buildQuickActions($s))
                ->managementEnabled($s['copilot_mgmt_enabled'] ?? false)
                ->managementGuard('admin')
                ->respectAuthorization()
                ->authorizeUsing(fn ($user) => $user->isAdmin());
        } catch (Throwable) {

            return null;
        }
    }

    /**
     * Tools that ToolRegistry always registers by default — never pass these
     * via ->globalTools() or they will be duplicated, causing Gemini 400 errors.
     */
    private const COPILOT_BUILTIN_TOOLS = [
        GetToolsTool::class,
        RunToolTool::class,
        ListResourcesTool::class,
        ListPagesTool::class,
        ListWidgetsTool::class,
        RememberTool::class,
        RecallTool::class,
    ];

    /**
     * Strip built-in tools from the user-configured global tools list.
     * Built-ins are always registered by ToolRegistry and must not be duplicated.
     *
     * @param  list<string>  $tools
     * @return list<string>
     */
    private function filterBuiltInTools(array $tools): array
    {
        return array_values(array_filter(
            $tools,
            fn (string $tool) => ! in_array($tool, self::COPILOT_BUILTIN_TOOLS, true)
        ));
    }

    /**
     * Build the quick actions list, automatically prepending the EPG mapper
     * quick action when that tool is enabled — without exposing it in the
     * user-editable Preferences UI.
     *
     * @param  array<string, mixed>  $s
     * @return list<array{label: string, prompt: string}>
     */
    private function buildQuickActions(array $s): array
    {
        $quickActions = array_values($s['copilot_quick_actions'] ?? []);

        if (in_array(EpgMappingStateTool::class, $s['copilot_global_tools'] ?? [], true)) {
            array_unshift($quickActions, [
                'label' => 'Map EPG Channels',
                'prompt' => 'I want to map EPG guide data to my playlist channels. Call the EPG mapping state tool now without a playlist_id to list all available playlists and their mapped/unmapped counts.',
            ]);
        }

        return $quickActions;
    }
}
