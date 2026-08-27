<?php

namespace App\Filament\Widgets;

use App\Enums\SyncRunStatus;
use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\EpgMaps\EpgMapResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\QueueMonitor\QueueMonitorResource;
use App\Filament\Widgets\Concerns\FormatsDateColumn;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StatsOverview extends BaseWidget
{
    use FormatsDateColumn;

    /**
     * Cache duration in seconds (5 minutes).
     */
    protected int $cacheDuration = 300;

    protected function getStats(): array
    {
        $userId = auth()->id();
        $isAdmin = auth()->user()?->isAdmin() ?? false;

        $stats = Cache::remember("dashboard_kpi_stats_{$userId}", $this->cacheDuration, function () use ($userId) {
            $channels = DB::table('channels')
                ->selectRaw('
                    COUNT(*) as total,
                    COUNT(CASE WHEN enabled = true THEN 1 END) as enabled,
                    COUNT(CASE WHEN enabled = true AND epg_channel_id IS NOT NULL THEN 1 END) as enabled_mapped,
                    COUNT(CASE WHEN is_vod = true THEN 1 END) as vod
                ')
                ->where('user_id', $userId)
                ->first();

            $sync = DB::table('sync_runs')
                ->selectRaw('
                    COUNT(CASE WHEN status = ? THEN 1 END) as completed,
                    COUNT(CASE WHEN status = ? THEN 1 END) as failed
                ', [SyncRunStatus::Completed->value, SyncRunStatus::Failed->value])
                ->where('user_id', $userId)
                ->where('created_at', '>=', now()->subDays(7))
                ->first();

            $syncTrend = DB::table('sync_runs')
                ->selectRaw($this->dateExpr('created_at').' as d, COUNT(*) as c')
                ->where('user_id', $userId)
                ->where('status', SyncRunStatus::Completed->value)
                ->where('created_at', '>=', now()->subDays(14))
                ->groupBy('d')
                ->orderBy('d')
                ->pluck('c')
                ->all();

            return (object) [
                'total_channels' => (int) ($channels->total ?? 0),
                'enabled_channels' => (int) ($channels->enabled ?? 0),
                'enabled_mapped_channels' => (int) ($channels->enabled_mapped ?? 0),
                'vod_channels' => (int) ($channels->vod ?? 0),
                'series' => DB::table('series')->where('user_id', $userId)->count(),
                'series_with_episodes' => DB::table('episodes')
                    ->where('user_id', $userId)
                    ->whereNotNull('series_id')
                    ->distinct()
                    ->count('series_id'),
                'sync_completed_7d' => (int) ($sync->completed ?? 0),
                'sync_failed_7d' => (int) ($sync->failed ?? 0),
                'sync_trend' => $this->padTrend($syncTrend),
                'channel_trend' => $this->dailyCounts('channels', $userId),
                'epg_trend' => $this->dailyCounts('epg_channels', $userId),
            ];
        });

        $enabledPct = $stats->total_channels > 0
            ? (int) round($stats->enabled_channels / $stats->total_channels * 100)
            : 0;

        $mappedPct = $stats->enabled_channels > 0
            ? (int) round($stats->enabled_mapped_channels / $stats->enabled_channels * 100)
            : 0;

        $cards = [
            Stat::make(__('Sync Activity'), number_format($stats->sync_completed_7d))
                ->description($stats->sync_failed_7d > 0
                    ? __(':count failed in the last 7 days', ['count' => number_format($stats->sync_failed_7d)])
                    : __('Completed in the last 7 days, none failed'))
                ->descriptionIcon($stats->sync_failed_7d > 0 ? 'heroicon-m-x-circle' : 'heroicon-m-arrow-path')
                ->chart($stats->sync_trend)
                ->color($stats->sync_failed_7d > 0 ? 'danger' : 'success')
                ->url(PlaylistResource::getUrl()),

            Stat::make(__('Enabled Channels'), number_format($stats->enabled_channels))
                ->description(__(':percent% of :total channels', [
                    'percent' => $enabledPct,
                    'total' => number_format($stats->total_channels),
                ]))
                ->descriptionIcon('heroicon-m-signal')
                ->chart($stats->channel_trend)
                ->color('primary')
                ->url(ChannelResource::getUrl()),

            Stat::make(__('EPG Coverage'), $mappedPct.'%')
                ->description(__(':mapped of :total enabled channels mapped', [
                    'mapped' => number_format($stats->enabled_mapped_channels),
                    'total' => number_format($stats->enabled_channels),
                ]))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->chart($stats->epg_trend)
                ->color(match (true) {
                    $mappedPct < 25 => 'danger',
                    $mappedPct < 75 => 'warning',
                    default => 'success',
                })
                ->url(EpgMapResource::getUrl()),

            Stat::make(__('VOD Channels'), number_format($stats->vod_channels))
                ->description(__(':series series, :fetched with episodes', [
                    'series' => number_format($stats->series),
                    'fetched' => number_format($stats->series_with_episodes),
                ]))
                ->descriptionIcon('heroicon-m-film')
                ->color('info'),
        ];

        if ($isAdmin) {
            $jobs = Cache::remember('dashboard_kpi_jobs', 120, function () {
                $failed = DB::table('queue_monitor')
                    ->where('failed', true)
                    ->where('created_at', '>=', now()->subDay())
                    ->count();

                $failedTrend = DB::table('queue_monitor')
                    ->selectRaw($this->dateExpr('created_at').' as d, COUNT(*) as c')
                    ->where('failed', true)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->groupBy('d')
                    ->orderBy('d')
                    ->pluck('c')
                    ->all();

                $succeeded24h = DB::table('queue_monitor')
                    ->where('failed', false)
                    ->whereNotNull('finished_at')
                    ->where('created_at', '>=', now()->subDay())
                    ->count();

                $succeeded7d = DB::table('queue_monitor')
                    ->where('failed', false)
                    ->whereNotNull('finished_at')
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count();

                $succeededTrend = DB::table('queue_monitor')
                    ->selectRaw($this->dateExpr('created_at').' as d, COUNT(*) as c')
                    ->where('failed', false)
                    ->whereNotNull('finished_at')
                    ->where('created_at', '>=', now()->subDays(14))
                    ->groupBy('d')
                    ->orderBy('d')
                    ->pluck('c')
                    ->all();

                return compact('failed', 'failedTrend', 'succeeded24h', 'succeeded7d', 'succeededTrend');
            });

            $cards[] = Stat::make(__('Failed Jobs (24h)'), number_format($jobs['failed']))
                ->description($jobs['failed'] > 0 ? __('Needs attention') : __('All clear'))
                ->descriptionIcon($jobs['failed'] > 0 ? 'heroicon-m-x-circle' : 'heroicon-m-check-circle')
                ->chart($this->padTrend($jobs['failedTrend']))
                ->color($jobs['failed'] > 0 ? 'danger' : 'success')
                ->url(QueueMonitorResource::getUrl());

            $cards[] = Stat::make(__('Queue Throughput'), number_format($jobs['succeeded24h']))
                ->description(__(':count processed in the last 7 days', ['count' => number_format($jobs['succeeded7d'])]))
                ->descriptionIcon('heroicon-m-bolt')
                ->chart($this->padTrend($jobs['succeededTrend']))
                ->color('primary')
                ->url(QueueMonitorResource::getUrl());
        }

        return $cards;
    }

    /**
     * Return a padded list of per-day row counts for a sparkline.
     *
     * @return list<int>
     */
    protected function dailyCounts(string $table, int $userId, int $days = 14): array
    {
        $rows = DB::table($table)
            ->selectRaw($this->dateExpr('created_at').' as d, COUNT(*) as c')
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('d')
            ->orderBy('d')
            ->pluck('c')
            ->all();

        return $this->padTrend($rows);
    }

    /**
     * Chart::make() needs at least two points to draw a line.
     *
     * @param  list<int>  $rows
     * @return list<int>
     */
    protected function padTrend(array $rows): array
    {
        $rows = array_map('intval', $rows);

        return match (count($rows)) {
            0 => [0, 0],
            1 => [0, $rows[0]],
            default => $rows,
        };
    }
}
