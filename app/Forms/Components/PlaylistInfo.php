<?php

namespace App\Forms\Components;

use App\Models\Playlist;
use App\Services\XtreamService;
use Carbon\Carbon;
use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class PlaylistInfo extends Field
{
    protected string $view = 'forms.components.playlist-info';

    public function getStats(): array
    {
        $playlist = Playlist::find($this->getRecord()?->id);
        if (!$playlist) {
            return [];
        }

        $stats = ['proxy_enabled' => $playlist->enable_proxy];
        if ($playlist->enable_proxy) {
            $activeStreams = Redis::get("active_streams:{$playlist->id}") ?? 0;
            $availableStreams = $playlist->available_streams ?? 0;
            if ($availableStreams === 0) {
                $availableStreams = "∞";
            }
            $stats['active_streams'] = $activeStreams;
            $stats['available_streams'] = $availableStreams;
            $stats['max_streams_reached'] = $activeStreams > 0 && $activeStreams >= $availableStreams;
            $stats['active_connections'] = "$activeStreams/$availableStreams";
        }
        if ($playlist->xtream) {
            $xtreamStats = $this->getXtreamStats($playlist);
            if (!empty($xtreamStats)) {
                $stats = array_merge($stats, $xtreamStats);
            }
        }

        return $stats;
    }

    private function getXtreamStats(Playlist $playlist): array
    {
        $cacheKey = "xtream_stats:{$playlist->id}";
        $xtreamInfo = Cache::get($cacheKey, null);
        if (!$xtreamInfo) {
            // If no cache, initialize XtreamService
            $xtream = XtreamService::make($playlist);
            if (!$xtream) {
                // Try and fetch from the playlist data directly
                $xtreamInfo = $playlist->xtream_info;
            } else {
                // Prefer live data from XtreamService
                $xtreamInfo = $xtream->userInfo();
            }
            if (!$xtreamInfo) {
                return [];
            }
        }
        Cache::put($cacheKey, $xtreamInfo, now()->addMinutes(10)); // Cache for 10 minutes

        $maxConnections = $xtreamInfo['user_info']['max_connections'] ?? 1;
        $activeConnections = $xtreamInfo['user_info']['active_cons'] ?? 0;
        $expires = $xtreamInfo['user_info']['exp_date'] ?? null;
        $expiresIn24HoursOrLess = false;
        if ($expires) {
            $expires = Carbon::createFromTimestamp($expires);
            $expiresIn24HoursOrLess = $expires->isToday() || $expires->isTomorrow();
        }
        return [
            'xtream_info' => [
                'active_connections' => "$activeConnections/$maxConnections",
                'max_streams_reached' => $activeConnections >= $maxConnections,
                'expires' => $expires->diffForHumans(),
                'expires_description' => $expires->toDateTimeString(),
                'expires_in_24_hours_or_less' => $expiresIn24HoursOrLess,
            ]
        ];
    }
}
