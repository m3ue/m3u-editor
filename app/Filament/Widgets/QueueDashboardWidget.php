<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\QueueMonitor\QueueMonitorResource;
use App\Models\QueueMonitor;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class QueueDashboardWidget extends BaseWidget
{
    protected ?string $pollingInterval = '15s';

    public static function canView(): bool
    {
        return Auth::check() && Auth::user()?->isAdmin();
    }

    protected function getStats(): array
    {
        $driver = DB::connection()->getDriverName();

        $elapsedExpr = match ($driver) {
            'pgsql' => 'EXTRACT(EPOCH FROM (finished_at - started_at))::bigint',
            'sqlite' => 'CAST((julianday(finished_at) - julianday(started_at)) * 86400 AS INTEGER)',
            default => 'TIMESTAMPDIFF(SECOND, started_at, finished_at)',
        };

        $running = QueueMonitor::query()
            ->whereNull('finished_at')
            ->where('failed', false)
            ->count();

        $today = QueueMonitor::query()
            ->whereDate('created_at', today())
            ->selectRaw('
                COALESCE(SUM(CASE WHEN failed = false AND finished_at IS NOT NULL THEN 1 ELSE 0 END), 0) as succeeded,
                COALESCE(SUM(CASE WHEN failed = true THEN 1 ELSE 0 END), 0) as failed
            ')
            ->first();

        $succeededToday = (int) ($today->succeeded ?? 0);
        $failedToday = (int) ($today->failed ?? 0);

        $monitorUrl = QueueMonitorResource::getUrl('index');

        return [
            Stat::make(__('Running Jobs'), $running)
                ->description($running > 0 ? __('Jobs currently processing') : __('Queue is idle'))
                ->descriptionIcon($running > 0 ? 'heroicon-m-arrow-path' : 'heroicon-m-check')
                ->color($running > 0 ? 'primary' : 'gray')
                ->url($monitorUrl),

            Stat::make(__('Succeeded Today'), number_format($succeededToday))
                ->description(__('Completed successfully'))
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->url($monitorUrl),

            Stat::make(__('Failed Today'), number_format($failedToday))
                ->description($failedToday > 0 ? __('Requires attention') : __('No failures'))
                ->descriptionIcon($failedToday > 0 ? 'heroicon-m-x-circle' : 'heroicon-m-check-circle')
                ->color($failedToday > 0 ? 'danger' : 'success')
                ->url($monitorUrl),
        ];
    }
}
