<?php

namespace App\Filament\GuestPanel\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class GuestDashboard extends Page
{
    protected string $view = 'filament.guest-panel.pages.guest-dashboard';
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-play';
    protected static ?string $navigationLabel = 'Playlist';
    protected static ?string $slug = 'guest';

    public function getTitle(): string|Htmlable
    {
        
        return 'test';
    }

    protected static function getCurrentUuid(): ?string
    {
        return request()->route('uuid') ?? request()->attributes->get('playlist_uuid');
    }

    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        $tenant = null
    ): string {
        $parameters['uuid'] = static::getCurrentUuid();
        return route(static::getRouteName($panel), $parameters, $isAbsolute);
    }
}
