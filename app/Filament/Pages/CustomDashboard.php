<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Epgs\EpgResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Pages\Dashboard;

class CustomDashboard extends Dashboard
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rocket-launch';

    public static function getNavigationLabel(): string
    {
        return __('Dashboard');
    }

    public function getHeading(): string
    {
        return 'M3U Editor'; // Return the app name
    }

    /**
     * Two-column magazine grid: full-width widgets span both columns, charts and
     * list widgets pair up side by side.
     */
    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'md' => 2,
        ];
    }

    protected function getHeaderActions(): array
    {
        $user = auth()->user();
        $isAdmin = $user?->isAdmin() ?? false;

        $actions = [
            Action::make('new_playlist')
                ->label(__('New Playlist'))
                ->icon('heroicon-m-plus')
                ->url(PlaylistResource::getUrl('create'))
                ->color('primary'),
            Action::make('playlists')
                ->label(__('Playlists'))
                ->icon('heroicon-m-play-pause')
                ->url(PlaylistResource::getUrl())
                ->color('gray'),
            Action::make('epgs')
                ->label(__('EPGs'))
                ->icon('heroicon-m-calendar-days')
                ->url(EpgResource::getUrl())
                ->color('gray'),
        ];

        $additionalActions = [];
        if ($user?->canUseProxy() && config('proxy.proxy_integration_enabled', true)) {
            $additionalActions[] = Action::make('stream_monitor')
                ->label(__('Stream Monitor'))
                ->icon('heroicon-m-arrows-right-left')
                ->url(M3uProxyStreamMonitor::getUrl())
                ->color('gray');
        }

        if ($isAdmin) {
            $additionalActions[] = Action::make('backups')
                ->label(__('Backups'))
                ->icon('heroicon-m-archive-box')
                ->url(Backups::getUrl())
                ->color('gray');
            $additionalActions[] = Action::make('logs')
                ->label(__('Logs'))
                ->icon('heroicon-m-document-text')
                ->url(LogViewer::getUrl())
                ->color('gray');
            $additionalActions[] = Action::make('settings')
                ->label(__('Settings'))
                ->icon('heroicon-m-cog-6-tooth')
                ->url(Preferences::getUrl())
                ->color('gray');
        }

        if (! empty($additionalActions)) {
            $actions[] = ActionGroup::make($additionalActions)
                ->label(__('More'))
                ->color('gray')
                ->button();
        }

        return $actions;
    }
}
