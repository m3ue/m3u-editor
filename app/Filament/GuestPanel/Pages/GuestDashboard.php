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

    protected static ?string $navigationLabel = 'Live TV';

    protected static ?string $slug = 'live';

    public function getTitle(): string|Htmlable
    {
        return '';
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

    public static function getNavigationBadge(): ?string
    {
        $playlist = PlaylistFacade::resolvePlaylistByUuid(static::getCurrentUuid());
        if ($playlist) {
            return (string) $playlist->channels()->where([
                ['enabled', true],
                ['is_vod', false],
            ])->count();
        }

        return '';
    }
}
