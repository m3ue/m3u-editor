<?php

namespace App\Filament\Resources\QueueMonitor\Widgets;

use App\Models\QueueMonitor;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class QueueStatsOverview extends BaseWidget
{
    protected ?string $pollingInterval = '10s';

    protected function getStats(): array
    {
        $driver = DB::connection()->getDriverName();

        $elapsedExpr = match ($driver) {
            'pgsql' => 'EXTRACT(EPOCH FROM (finished_at - started_at))::bigint',
            'sqlite' => 'CAST((julianday(finished_at) - julianday(started_at)) * 86400 AS INTEGER)',
            default => 'TIMESTAMPDIFF(SECOND, started_at, finished_at)',
        };

        $totals = QueueMonitor::query()
            ->whereNotNull('finished_at')
            ->selectRaw("
                COUNT(*) as total,
                COALESCE(SUM(CASE WHEN failed = false THEN 1 ELSE 0 END), 0) as succeeded,
                COALESCE(SUM(CASE WHEN failed = true THEN 1 ELSE 0 END), 0) as failed,
                COALESCE(AVG({$elapsedExpr}), 0) as avg_seconds
            ")
            ->first();

        $running = QueueMonitor::query()
            ->whereNull('finished_at')
            ->where('failed', false)
            ->count();

        $jobsPerDay = $this->countPerDay('created_at', 7);
        $succeededPerDay = $this->countPerDay('finished_at', 7, fn ($q) => $q->where('failed', false));
        $failedPerDay = $this->countPerDay('finished_at', 7, fn ($q) => $q->where('failed', true));

        $avgSeconds = (int) round((float) ($totals->avg_seconds ?? 0));
        $avgLabel = $this->formatDuration($avgSeconds);

        return [
            Stat::make(__('Running'), $running)
                ->description(__('Jobs currently processing'))
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color($running > 0 ? 'primary' : 'gray'),

            Stat::make(__('Succeeded'), number_format((int) ($totals->succeeded ?? 0)))
                ->description(__('Last 7 days'))
                ->descriptionIcon('heroicon-m-check-circle')
                ->chart($succeededPerDay)
                ->color('success'),

            Stat::make(__('Failed'), number_format((int) ($totals->failed ?? 0)))
                ->description(__('Last 7 days'))
                ->descriptionIcon('heroicon-m-x-circle')
                ->chart($failedPerDay)
                ->color((int) ($totals->failed ?? 0) > 0 ? 'danger' : 'gray'),

            Stat::make(__('Avg. Duration'), $avgLabel)
                ->description(__('Per completed job'))
                ->descriptionIcon('heroicon-m-clock')
                ->chart($jobsPerDay)
                ->color('warning'),
        ];
    }

    private function countPerDay(string $dateColumn, int $days, ?callable $scope = null): array
    {
        return collect(range($days - 1, 0))
            ->map(function (int $daysAgo) use ($dateColumn, $scope): int {
                $query = QueueMonitor::query()->whereDate($dateColumn, Carbon::now()->subDays($daysAgo)->toDateString());

                if ($scope) {
                    $scope($query);
                }

                return $query->count();
            })
            ->values()
            ->all();
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds === 0) {
            return '0s';
        }

        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        return $remaining > 0 ? "{$minutes}m {$remaining}s" : "{$minutes}m";
    }
}
