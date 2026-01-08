<?php

namespace App\Providers\Filament;

use App\Http\Middleware\GuestPlaylistAuth;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentView;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Support\Facades\Blade;

class GuestPanelPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('playlist')
            ->userMenu(false)
            ->path('playlist/v/{uuid}')
            ->homeUrl(function () {
                $uuid = request()->route('uuid') ?? request()->attributes->get('playlist_uuid');

                return $uuid ? route('filament.playlist.home', ['uuid' => $uuid]) : '/';
            })
            ->brandName('Playlist viewer')
            ->brandLogo(fn () => view('filament.admin.logo'))
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
            ->breadcrumbs(false)
            ->viteTheme('resources/css/app.css')
            ->spa()
            ->topNavigation()
            ->maxContentWidth(Width::Full);
    }

    public function register(): void
    {
        parent::register();
        FilamentView::registerRenderHook('panels::body.end', fn () => Blade::render("@vite('resources/js/app.js')"));
    }
}
