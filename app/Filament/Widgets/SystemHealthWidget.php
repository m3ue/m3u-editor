<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\QueueMonitor\QueueMonitorResource;
use App\Services\SystemHealthService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

class SystemHealthWidget extends Widget
{
    protected int|string|array $columnSpan = 1;

    protected string $view = 'filament.widgets.system-health-widget';

    public static function canView(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    protected function getViewData(): array
    {
        return [
            'checks' => Cache::remember('dashboard_system_health_checks', 30, fn () => app(SystemHealthService::class)->checks()),
            'failedJobsUrl' => QueueMonitorResource::getUrl(),
            'detailsUrl' => SystemHealth::canAccess() ? SystemHealth::getUrl() : null,
        ];
    }
}
