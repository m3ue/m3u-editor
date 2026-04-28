<?php

namespace App\Filament\GuestPanel\Pages;

use App\Facades\PlaylistFacade;
use App\Filament\GuestPanel\Pages\Concerns\HasGuestAuth;
use Filament\Pages\Page;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\Support\Htmlable;

class GuestDashboard extends Page implements HasSchemas
{
    use HasGuestAuth;

    protected string $view = 'filament.guest-panel.pages.guest-dashboard';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-s-tv';

    public static function getNavigationLabel(): string
    {
        return __('Live TV');
    }

    protected static ?string $slug = 'live';

    public static function shouldRegisterNavigation(): bool
    {
        return static::isSessionAuthenticated();
    }

    public function getTitle(): string|Htmlable
    {
        return '';
    }

    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        $tenant = null,
        bool $shouldGuessMissingParameters = false,
        ?string $configuration = null
    ): string {
        $parameters['uuid'] = static::getCurrentUuid();

        return route(static::getRouteName($panel), $parameters, $isAbsolute);
    }

    public static function getNavigationBadge(): ?string
    {
        $uuid = static::getCurrentUuid();
        $prefix = $uuid ? base64_encode($uuid).'_' : '';
        if (! session("{$prefix}guest_auth_username")) {
            return null;
        }

        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if ($playlist) {
            return (string) $playlist->channels()->where([
                ['enabled', true],
                ['is_vod', false],
            ])->count();
        }

        return null;
    }
}
