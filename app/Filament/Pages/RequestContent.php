<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\MediaServers\MediaServersCluster;
use App\Models\ArrIntegration;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class RequestContent extends Page
{
    protected string $view = 'filament.pages.request-content';

    protected static ?string $cluster = MediaServersCluster::class;

    public static function getNavigationLabel(): string
    {
        return __('Request Content');
    }

    /**
     * Listed under Sonarr & Radarr only once one of the user's integrations
     * is enabled, since there's nothing to search before that.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return ArrIntegration::query()
            ->where('user_id', auth()->id())
            ->enabled()
            ->exists();
    }

    public function getTitle(): string|Htmlable
    {
        return __('Request Content');
    }

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseIntegrations();
    }

    public function getHeading(): string|Htmlable
    {
        return __('Search & Request Movies / TV Shows');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Search your Sonarr (TV) and Radarr (Movies) servers and submit download requests for content not already in your library.');
    }
}
