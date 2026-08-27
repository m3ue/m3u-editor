<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PlaylistViewers\PlaylistViewerResource;
use App\Models\ViewerWatchProgress;
use Filament\Widgets\Widget;

class RecentViewerActivityWidget extends Widget
{
    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.recent-viewer-activity-widget';

    public static function canView(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    protected function getViewData(): array
    {
        $rows = ViewerWatchProgress::query()
            ->with([
                'viewer:id,name',
                'channel:id,title,name,logo',
                'episode:id,title,series_id,season,episode_num,cover',
                'episode.series:id,name,cover',
            ])
            ->whereNotNull('last_watched_at')
            ->orderByDesc('last_watched_at')
            ->limit(8)
            ->get()
            ->map(fn (ViewerWatchProgress $progress) => [
                'viewer' => $progress->viewer?->name ?? __('Unknown'),
                'title' => $progress->content_title,
                'type' => $progress->content_type,
                'completed' => (bool) $progress->completed,
                'percent' => $progress->duration_seconds > 0
                    ? min(100, (int) round($progress->position_seconds / $progress->duration_seconds * 100))
                    : null,
                'when' => $progress->last_watched_at?->diffForHumans(),
            ]);

        return [
            'rows' => $rows,
            'viewersUrl' => PlaylistViewerResource::getUrl(),
        ];
    }
}
