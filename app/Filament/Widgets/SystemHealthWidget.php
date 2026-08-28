<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\QueueMonitor\QueueMonitorResource;
use App\Providers\VersionServiceProvider;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Number;

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
        return Cache::remember('dashboard_system_health', 30, function () {
            $checks = [];

            $updateAvailable = $this->safe(fn () => VersionServiceProvider::updateAvailable(), false);
            $checks[] = [
                'label' => __('Version'),
                'ok' => ! $updateAvailable,
                'detail' => $updateAvailable ? __('Update available') : __('Up to date'),
            ];

            $databaseOk = $this->safe(fn () => DB::connection()->getPdo() !== null);
            $checks[] = [
                'label' => __('Database'),
                'ok' => $databaseOk,
                'detail' => $databaseOk
                    ? $this->safe(fn () => DB::connection()->getDriverName(), __('Connected'))
                    : __('Unavailable'),
            ];

            // Only surface Redis when the app is actually backed by it (a SQLite-only
            // install may run file cache + sync queue and have no Redis at all).
            if ($this->usesRedis()) {
                $redisOk = $this->safe(fn () => (bool) Redis::connection()->ping());
                $checks[] = [
                    'label' => __('Redis'),
                    'ok' => $redisOk,
                    'detail' => $redisOk ? __('Connected') : __('Unavailable'),
                ];
            }

            // Debug mode. A production instance left with APP_DEBUG=true leaks
            // stack traces and config on any error - a real, stable config-health
            // signal that never needs to be live.
            $debug = (bool) config('app.debug');
            $isProduction = app()->environment('production');
            $checks[] = [
                'label' => __('Debug mode'),
                'ok' => ! ($debug && $isProduction),
                'detail' => match (true) {
                    ! $debug => __('Disabled'),
                    $isProduction => __('Enabled'),
                    default => __('Enabled (:env)', ['env' => app()->environment()]),
                },
            ];

            $free = $this->safe(fn () => (int) disk_free_space(base_path()), 0);
            $total = $this->safe(fn () => (int) disk_total_space(base_path()), 0);
            $freePct = $total > 0 ? (int) round($free / $total * 100) : null;
            $checks[] = [
                'label' => __('Disk free'),
                'ok' => $freePct === null || $freePct > 10,
                'detail' => $freePct !== null
                    ? __(':size free (:percent%)', ['size' => Number::fileSize($free, precision: 1), 'percent' => $freePct])
                    : __('Unknown'),
            ];

            return [
                'checks' => $checks,
                'failedJobsUrl' => QueueMonitorResource::getUrl(),
            ];
        });
    }

    /**
     * Whether any core subsystem (cache, queue, session, broadcasting) is
     * actually configured to use Redis.
     */
    protected function usesRedis(): bool
    {
        $drivers = [
            config('cache.stores.'.config('cache.default').'.driver'),
            config('queue.connections.'.config('queue.default').'.driver'),
            config('session.driver'),
            config('broadcasting.connections.'.config('broadcasting.default').'.driver'),
        ];

        return in_array('redis', $drivers, true);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $fallback
     * @return T
     */
    protected function safe(callable $callback, mixed $fallback = false): mixed
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
