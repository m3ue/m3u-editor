<?php

namespace App\Http\Controllers;

use App\Facades\PlaylistFacade;
use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use App\Services\M3uProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaylistController extends Controller
{
    /**
     * Sync the selected Playlist.
     *
     * Use the `uuid` parameter to select the playlist to refresh.
     * You can find the playlist UUID by using the `User > Get your Playlists` endpoint.
     *
     *
     * @return JsonResponse
     *
     * @unauthenticated
     *
     * @response array{message: "Playlist is currently being synced..."}
     */
    public function refreshPlaylist(Request $request, string $uuid)
    {
        $request->validate([
            // If true, will force a refresh of the EPG, ignoring any scheduling. Default is true.
            'force' => 'boolean',
        ]);

        // Fetch the playlist
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return response()->json(['Error' => 'Playlist Not Found'], 404);
        }

        // Refresh the playlist
        dispatch(new ProcessM3uImport($playlist, $request->force ?? true));

        return response()->json([
            'message' => "Playlist \"{$playlist->name}\" is currently being synced...",
        ]);
    }

    /**
     * Get playlist statistics.
     *
     * Retrieve comprehensive statistics for a specific playlist including channel counts,
     * group information, sync status, and proxy details.
     *
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "uuid": "abc-123-def",
     *     "name": "My Provider",
     *     "channels": {
     *       "total": 500,
     *       "enabled": 450,
     *       "disabled": 50,
     *       "live": 400,
     *       "live_enabled": 380,
     *       "vod": 100,
     *       "vod_enabled": 70
     *     },
     *     "groups": {
     *       "total": 25,
     *       "live": 20,
     *       "vod": 5
     *     },
     *     "series": {
     *       "total": 50,
     *       "enabled": 45
     *     },
     *     "sync": {
     *       "last_sync": "2026-01-14T10:00:00+00:00",
     *       "sync_time_seconds": 45.5,
     *       "is_processing": false,
     *       "status": "Active"
     *     },
     *     "proxy": {
     *       "enabled": true,
     *       "active_streams": 3,
     *       "max_connections": 5
     *     },
     *     "source": {
     *       "type": "xtream",
     *       "url": "https://provider.com"
     *     }
     *   }
     * }
     * @response 404 {
     *   "success": false,
     *   "message": "Playlist not found"
     * }
     */
    public function stats(Request $request, string $uuid): JsonResponse
    {
        // Fetch the playlist
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return response()->json([
                'success' => false,
                'message' => 'Playlist not found',
            ], 404);
        }

        // Get channel counts
        $totalChannels = $playlist->channels()->count();
        $enabledChannels = $playlist->channels()->where('enabled', true)->count();
        $liveChannels = $playlist->channels()->where('is_vod', false)->count();
        $liveEnabledChannels = $playlist->channels()->where('is_vod', false)->where('enabled', true)->count();
        $vodChannels = $playlist->channels()->where('is_vod', true)->count();
        $vodEnabledChannels = $playlist->channels()->where('is_vod', true)->where('enabled', true)->count();

        // Get group counts
        $totalGroups = $playlist->groups()->count();
        $liveGroups = $playlist->groups()->where('type', 'live')->count();
        $vodGroups = $playlist->groups()->where('type', 'vod')->count();

        // Get series counts if available
        $totalSeries = 0;
        $enabledSeries = 0;
        if (method_exists($playlist, 'series')) {
            $totalSeries = $playlist->series()->count();
            $enabledSeries = $playlist->series()->where('enabled', true)->count();
        }

        // Get active streams if proxy is enabled
        $activeStreams = 0;
        if ($playlist->enable_proxy) {
            $activeStreams = M3uProxyService::getCachedPlaylistActiveStreamsCount($playlist, 5);
        }

        // Build response
        $data = [
            'uuid' => $playlist->uuid,
            'name' => $playlist->name,
            'channels' => [
                'total' => $totalChannels,
                'enabled' => $enabledChannels,
                'disabled' => $totalChannels - $enabledChannels,
                'live' => $liveChannels,
                'live_enabled' => $liveEnabledChannels,
                'vod' => $vodChannels,
                'vod_enabled' => $vodEnabledChannels,
            ],
            'groups' => [
                'total' => $totalGroups,
                'live' => $liveGroups,
                'vod' => $vodGroups,
            ],
            'series' => [
                'total' => $totalSeries,
                'enabled' => $enabledSeries,
            ],
            'sync' => [
                'last_sync' => $playlist->synced?->toIso8601String(),
                'sync_time_seconds' => $playlist->sync_time,
                'is_processing' => $playlist->isProcessing(),
                'status' => $playlist->status?->value ?? 'Unknown',
            ],
            'proxy' => [
                'enabled' => (bool) $playlist->enable_proxy,
                'active_streams' => $activeStreams,
                'max_connections' => $playlist->streams ?? 1,
            ],
            'source' => [
                'type' => $playlist->source_type?->value ?? 'unknown',
                'url' => $playlist->url ? parse_url($playlist->url, PHP_URL_HOST) : null,
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
