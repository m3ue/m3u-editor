<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Backups;
use App\Filament\Pages\LogViewer;
use App\Filament\Pages\M3uProxyStreamMonitor;
use App\Filament\Pages\Preferences;
use App\Filament\Resources\Epgs\EpgResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use Filament\Widgets\Widget;

class QuickActionsWidget extends Widget
{
    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.quick-actions-widget';

    protected function getViewData(): array
    {
        $user = auth()->user();
        $isAdmin = $user?->isAdmin() ?? false;

        $actions = [
            ['label' => __('New Playlist'), 'icon' => 'heroicon-m-plus', 'url' => PlaylistResource::getUrl('create'), 'color' => 'primary'],
            ['label' => __('Playlists'), 'icon' => 'heroicon-m-play-pause', 'url' => PlaylistResource::getUrl(), 'color' => 'gray'],
            ['label' => __('EPGs'), 'icon' => 'heroicon-m-calendar-days', 'url' => EpgResource::getUrl(), 'color' => 'gray'],
        ];

        if ($user?->canUseProxy() && config('proxy.proxy_integration_enabled', true)) {
            $actions[] = ['label' => __('Stream Monitor'), 'icon' => 'heroicon-m-arrows-right-left', 'url' => M3uProxyStreamMonitor::getUrl(), 'color' => 'gray'];
        }

        if ($isAdmin) {
            $actions[] = ['label' => __('Backups'), 'icon' => 'heroicon-m-archive-box', 'url' => Backups::getUrl(), 'color' => 'gray'];
            $actions[] = ['label' => __('Logs'), 'icon' => 'heroicon-m-document-text', 'url' => LogViewer::getUrl(), 'color' => 'gray'];
            $actions[] = ['label' => __('Settings'), 'icon' => 'heroicon-m-cog-6-tooth', 'url' => Preferences::getUrl(), 'color' => 'gray'];
        }

        return ['actions' => $actions];
    }
}
