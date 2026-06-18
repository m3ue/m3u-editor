<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class DownloadQueue extends Page
{
    protected string $view = 'filament.pages.download-queue';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    public static function getNavigationLabel(): string
    {
        return __('Download Queue');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Integrations');
    }

    protected static ?int $navigationSort = 107;

    public function getTitle(): string|Htmlable
    {
        return __('Download Queue');
    }

    public function getHeading(): string|Htmlable
    {
        return __('Download Queue');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Live status of active downloads across all your Sonarr and Radarr servers. Refreshes every 10 seconds.');
    }

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseIntegrations();
    }
}
