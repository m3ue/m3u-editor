<?php

namespace App\Providers\Filament;

use AchyutN\FilamentLogViewer\FilamentLogViewer;
use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Login;
use App\Filament\Pages\Backups;
use App\Filament\Pages\CustomDashboard;
use App\Filament\Widgets\DiscordWidget;
use App\Filament\Widgets\DocumentsWidget;
use App\Filament\Widgets\DonateCrypto;
use App\Filament\Widgets\KoFiWidget;
use App\Filament\Widgets\SharedStreamStatsWidget;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\SystemHealthWidget;
use App\Filament\Widgets\UpdateNoticeWidget;
use App\Http\Middleware\DashboardMiddleware;
// use App\Filament\Widgets\PayPalDonateWidget;
use App\Settings\GeneralSettings;
use Exception;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use ShuvroRoy\FilamentSpatieLaravelBackup\FilamentSpatieLaravelBackupPlugin;
use Saade\FilamentLaravelLog\FilamentLaravelLogPlugin;

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
            ->brandLogo(fn() => view('filament.admin.logo'))
            ->favicon('/favicon.png')
            ->brandLogoHeight('2.5rem')
            ->databaseNotifications()
            // ->databaseNotificationsPolling('10s')
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                CustomDashboard::class,
            ])
            ->navigationGroups([
                NavigationGroup::make('Playlist')
                    ->icon('heroicon-m-play-pause'),
                NavigationGroup::make('Live Channels')
                    ->icon('heroicon-m-tv'),
                NavigationGroup::make('VOD Channels')
                    ->icon('heroicon-m-film'),
                NavigationGroup::make('Series')
                    ->icon('heroicon-m-play'),
                NavigationGroup::make('EPG')
                    ->icon('heroicon-m-calendar-days'),
                NavigationGroup::make('Integrations')
                    ->icon('heroicon-m-server-stack'),
                NavigationGroup::make('Proxy')
                    ->icon('heroicon-m-arrows-right-left'),
                NavigationGroup::make('Tools')
                    ->collapsed()
                    ->icon('heroicon-m-wrench-screwdriver'),
            ])
            ->navigationItems([
                NavigationItem::make('API Docs')
                    ->url('/docs/api', shouldOpenInNewTab: true)
                    ->group('Tools')
                    ->sort(sort: 9)
                    ->icon(null)
                    ->visible(fn(): bool => in_array(auth()->user()->email, config('dev.admin_emails'), true)),
                NavigationItem::make('Queue Manager')
                    ->url('/horizon', shouldOpenInNewTab: true)
                    ->group('Tools')
                    ->sort(10)
                    ->icon(null)
                    ->visible(fn(): bool => in_array(auth()->user()->email, config('dev.admin_emails'), true)),
            ])
            ->breadcrumbs($settings['show_breadcrumbs'])
            ->widgets([
                UpdateNoticeWidget::class,
                AccountWidget::class,
                DocumentsWidget::class,
                DiscordWidget::class,
                // PayPalDonateWidget::class,
                KoFiWidget::class,
                // DonateCrypto::class,
                StatsOverview::class,
                // SharedStreamStatsWidget::class,
                // SystemHealthWidget::class,
            ])
            ->plugins([
                FilamentSpatieLaravelBackupPlugin::make()
                    ->authorize(fn(): bool => in_array(auth()->user()->email, config('dev.admin_emails'), true))
                    ->usingPage(Backups::class),
                FilamentLaravelLogPlugin::make()
                    ->authorize(fn(): bool => in_array(auth()->user()->email, config('dev.admin_emails'), true))
                    ->navigationGroup('Tools')
                    ->navigationLabel('Logs')
                    ->navigationIcon(null)
                    ->activeNavigationIcon(null)
                    ->navigationSort(6)
                    ->title('Application Logs')
                    ->slug('logs'),
            ])
            ->maxContentWidth($settings['content_width'])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                DashboardMiddleware::class, // Needs to be after StartSession
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->viteTheme('resources/css/app.css')
            ->unsavedChangesAlerts()
            ->spa()
            ->spaUrlExceptions(fn(): array => [
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
                fn(): string => view('components.external-ip-display')->render()
            );
        }

        // Register our custom app js
        FilamentView::registerRenderHook('panels::body.end', fn() => Blade::render("@vite('resources/js/app.js')"));

        // Return the configured panel
        return $adminPanel;
    }
}
