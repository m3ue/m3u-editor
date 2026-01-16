<?php

namespace App\Http\Controllers;

use App\Services\M3uProxyService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Get your Playlists.
     *
     * Returns an array of your Playlists with detailed information including channel counts,
     * sync status, and proxy settings. This is useful for calling the Playlist endpoints as a UUID is required.
     *
     * @return []|\Illuminate\Http\Response
     *
     * @response 200 [
     *   {
     *     "name": "My Provider",
     *     "uuid": "0eff7923-cbd1-4868-9fed-2e3748ac1100",
     *     "total_channels": 500,
     *     "enabled_channels": 450,
     *     "live_channels": 400,
     *     "vod_channels": 100,
     *     "groups_count": 25,
     *     "proxy_enabled": true,
     *     "active_streams": 3,
     *     "last_sync": "2026-01-14T10:00:00+00:00",
     *     "status": "Active",
     *     "source_type": "m3u"
     *   }
     * ]
     */
    public function playlists(Request $request)
    {
        $user = $request->user();
        if ($user) {
            return $user->playlists()
                ->withCount([
                    'channels',
                    'channels as enabled_channels_count' => function ($query) {
                        $query->where('enabled', true);
                    },
                    'channels as live_channels_count' => function ($query) {
                        $query->where('is_vod', false);
                    },
                    'channels as vod_channels_count' => function ($query) {
                        $query->where('is_vod', true);
                    },
                    'groups',
                ])
                ->get()
                ->map(function ($playlist) {
                    // Get active streams count if proxy is enabled
                    $activeStreams = 0;
                    if ($playlist->enable_proxy) {
                        $activeStreams = M3uProxyService::getCachedPlaylistActiveStreamsCount($playlist, 5);
                    }

                    return [
                        'name' => $playlist->name,
                        'uuid' => $playlist->uuid,
                        'total_channels' => $playlist->channels_count,
                        'enabled_channels' => $playlist->enabled_channels_count,
                        'live_channels' => $playlist->live_channels_count,
                        'vod_channels' => $playlist->vod_channels_count,
                        'groups_count' => $playlist->groups_count,
                        'proxy_enabled' => (bool) $playlist->enable_proxy,
                        'active_streams' => $activeStreams,
                        'last_sync' => $playlist->synced?->toIso8601String(),
                        'status' => $playlist->status?->value ?? 'Unknown',
                        'source_type' => $playlist->source_type?->value ?? 'unknown',
                    ];
                })->toArray();
        }

        return abort(401, 'Unauthorized'); // Return 401 if user is not authenticated
    }

    /**
     * Get your EPGs.
     *
     * Returns an array of your EPGs with detailed information including channel counts
     * and sync status. This is useful for calling the EPG endpoints as a UUID is required.
     *
     * @return []|\Illuminate\Http\Response
     *
     * @response 200 [
     *   {
     *     "name": "My EPG Guide",
     *     "uuid": "0eff7923-cbd1-4868-9fed-2e3748ac1100",
     *     "channel_count": 200,
     *     "last_sync": "2026-01-14T08:00:00+00:00",
     *     "status": "Active",
     *     "source_type": "xmltv",
     *     "is_processing": false
     *   }
     * ]
     */
    public function epgs(Request $request)
    {
        $user = $request->user();
        if ($user) {
            return $user->epgs()
                ->withCount('channels')
                ->get()
                ->map(function ($epg) {
                    return [
                        'name' => $epg->name,
                        'uuid' => $epg->uuid,
                        'channel_count' => $epg->channels_count,
                        'last_sync' => $epg->synced?->toIso8601String(),
                        'status' => $epg->status?->value ?? 'Unknown',
                        'source_type' => $epg->source_type?->value ?? 'xmltv',
                        'is_processing' => (bool) $epg->processing,
                    ];
                })->toArray();
        }

        return abort(401, 'Unauthorized'); // Return 401 if user is not authenticated
    }
}
