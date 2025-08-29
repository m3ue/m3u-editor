<?php

namespace App\Providers\Filament;

use App\Filament\GuestPanel\Pages\PlaylistWebView;
use App\Http\Middleware\GuestPlaylistAuth;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\Support\Enums\Width;

class GuestPanelPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('playlist')
            ->path('playlist/{uuid}')
            ->userMenu(false)
            ->homeUrl(function () {
                $uuid = request()->route('uuid') ?? request()->attributes->get('playlist_uuid');
                return $uuid ? route('filament.playlist.home', ['uuid' => $uuid]) : '/';
            })
            ->brandName('Playlist viewer')
            ->brandLogo(fn() => view('filament.admin.logo'))
            ->favicon('/favicon.png')
            ->brandLogoHeight('2.5rem')
            ->middleware([
                'web',
            ])
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->discoverResources(in: app_path('Filament/GuestPanel/Resources'), for: 'App\Filament\GuestPanel\Resources')
            ->discoverPages(in: app_path('Filament/GuestPanel/Pages'), for: 'App\Filament\GuestPanel\Pages')
            ->pages([
                // ...
            ])
            ->discoverWidgets(in: app_path('Filament/GuestPanel/Widgets'), for: 'App\Filament\GuestPanel\Widgets')
            ->widgets([
                FilamentInfoWidget::class,
            ])
            ->authMiddleware([
                GuestPlaylistAuth::class,
            ])
            ->topNavigation()
            ->maxContentWidth(Width::Full);
    }
}
