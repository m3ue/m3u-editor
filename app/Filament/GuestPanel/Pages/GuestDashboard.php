<?php

namespace App\Filament\GuestPanel\Pages;

use Filament\Pages\Page;
use App\Filament\GuestPanel\Pages\Concerns\GuestAuthPage;
use Illuminate\Contracts\Support\Htmlable;

class GuestDashboard extends GuestAuthPage
{
    protected string $view = 'filament.guest-panel.pages.guest-dashboard';
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-s-tv';
    protected static ?string $navigationLabel = 'Live TV';
    protected static ?string $slug = 'live';

    public function getTitle(): string|Htmlable
    {
        return '';
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
