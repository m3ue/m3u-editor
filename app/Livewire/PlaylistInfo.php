<?php

namespace App\Livewire;

use App\Facades\PlaylistFacade;
use App\Models\Playlist;
use App\Services\M3uProxyService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class PlaylistInfo extends Component
{
    public Model $record;

    public bool $isVisible = true;

    public function render()
    {
        return view('livewire.playlist-info');
    }

    public function toggleVisibility()
    {
        $this->isVisible = ! $this->isVisible;
    }

    public function getStats(): array
    {
        $playlist = PlaylistFacade::resolvePlaylistByUuid($this->record->uuid);
        if (! $playlist) {
            return [];
        }

        $stats = [
            'proxy_enabled' => $playlist->enable_proxy,

            'channel_count' => $playlist->live_channels()->count(),
            'vod_count' => $playlist->vod_channels()->count(),
            'series_count' => $playlist->series()->count(),
            'group_count' => $playlist->groups()->count(),

            'enabled_channel_count' => $playlist->enabled_live_channels()->count(),
            'enabled_vod_count' => $playlist->enabled_vod_channels()->count(),
            'enabled_series_count' => $playlist->enabled_series()->count(),
            // 'last_synced' => $playlist->synced ? Carbon::parse($playlist->synced)->diffForHumans() : 'Never',
        ];
        if ($playlist->enable_proxy) {
            $activeStreams = M3uProxyService::getPlaylistActiveStreamsCount($playlist);
            $availableStreams = $playlist->available_streams ?? 0;
            if ($availableStreams === 0) {
                $availableStreams = '∞';
            }
            $stats['active_streams'] = $activeStreams;
            $stats['available_streams'] = $availableStreams;
            $stats['max_streams_reached'] = $activeStreams > 0 && $activeStreams >= $availableStreams;
            $stats['active_connections'] = "$activeStreams/$availableStreams";
        }
        if ($playlist->xtream) {
            $xtreamStats = $this->getXtreamStats($playlist);
            if (! empty($xtreamStats)) {
                $stats = array_merge($stats, $xtreamStats);
            }
        }

        return $stats;
    }

    private function getXtreamStats(Playlist $playlist): array
    {
        $xtreamInfo = $playlist->xtream_status;

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
                'expires' => $expires ? $expires->diffForHumans() : 'N/A',
                'expires_description' => $expires ? $expires->toDateTimeString() : 'N/A',
                'expires_in_24_hours_or_less' => $expiresIn24HoursOrLess,
            ],
        ];
    }
}
