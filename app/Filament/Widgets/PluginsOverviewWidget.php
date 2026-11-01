<?php

namespace App\Filament\Widgets;

use App\Models\Plugin;
use App\Models\PluginRun;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

class PluginsOverviewWidget extends Widget
{
    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.plugins-overview-widget';

    public static function canView(): bool
    {
        return auth()->check() && auth()->user()->canManagePlugins();
    }

    protected function getViewData(): array
    {
        $stats = Cache::remember('plugins_overview_widget', 60, function () {
            return [
                'installed' => Plugin::query()->count(),
                'enabled' => Plugin::query()->where('enabled', true)->count(),
                'trusted' => Plugin::query()->where('trust_state', 'trusted')->count(),
                'pending' => Plugin::query()->where('trust_state', 'pending_review')->count(),
            ];
        });

        $recentRuns = PluginRun::query()
            ->with('plugin:id,name,plugin_id')
            ->latest()
            ->limit(5)
            ->get(['id', 'extension_plugin_id', 'trigger', 'action', 'hook', 'status', 'created_at']);

        return [...$stats, 'recentRuns' => $recentRuns];
    }
}
