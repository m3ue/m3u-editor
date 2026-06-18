<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class RequestContent extends Page
{
    protected string $view = 'filament.pages.request-content';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    public static function getNavigationLabel(): string
    {
        return __('Request Content');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Integrations');
    }

    protected static ?int $navigationSort = 106;

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
