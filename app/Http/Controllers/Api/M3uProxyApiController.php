<?php

namespace App\Http\Controllers\Api;

use App\Facades\PlaylistFacade;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\StreamProfile;
use App\Services\M3uProxyService;
use App\Settings\GeneralSettings;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class M3uProxyApiController extends Controller
{
    /**
     * Get the proxied URL for a channel and redirect
     *
     * @param  int  $id
     * @param  string|null  $uuid  Optional playlist UUID for context
     * @return Response|RedirectResponse
     */
    public function channel(Request $request, $id, $uuid = null)
    {
        $channel = Channel::query()->with([
            'playlist',
            'customPlaylist',
        ])->findOrFail($id);

        // See if username is passed in request
        $username = $request->input('username', null);

        // If UUID provided, resolve that specific playlist (e.g., merged playlist)
        // Otherwise fall back to the channel's effective playlist
        if ($uuid) {
            $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
            if (! $playlist) {
                return response()->json(['error' => 'Playlist not found'], 404);
            }
        } else {
            $playlist = $channel->getEffectivePlaylist();
        }

        // Load the stream profile relationships explicitly after getting the effective playlist
        // This ensures the relationship constraints are properly applied
        if ($playlist) {
            $playlist->load('streamProfile', 'vodStreamProfile');
        }

        // Get stream profile from playlist if set
        $profile = null;
        if ($channel->is_vod) {
            // For VOD channels, use the VOD stream profile if set
            $profile = $playlist->vodStreamProfile;
        } else {
            // Get stream profile from playlist if set
            $profile = $playlist->streamProfile;
        }

        $url = app(M3uProxyService::class)
            ->getChannelUrl(
                $playlist,
                $channel,
                $request,
                $profile
            );

        return redirect($url);
    }

    /**
     * Get the proxied URL for an episode and redirect
     *
     * @param  int  $id
     * @param  string|null  $uuid  Optional playlist UUID for context
     * @return Response|RedirectResponse
     */
    public function episode(Request $request, $id, $uuid = null)
    {
        $episode = Episode::query()->with([
            'playlist',
        ])->findOrFail($id);

        // See if username is passed in request
        $username = $request->input('username', null);

        // If UUID provided, resolve that specific playlist (e.g., merged playlist)
        // Otherwise fall back to the episode's playlist
        if ($uuid) {
            $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
            if (! $playlist) {
                return response()->json(['error' => 'Playlist not found'], 404);
            }
        } else {
            $playlist = $episode->playlist;
        }

        // Load the stream profile relationships explicitly after getting the playlist
        if ($playlist) {
            $playlist->load('streamProfile', 'vodStreamProfile');
        }

        // For Series, use the VOD stream profile if set
        $profile = $playlist->vodStreamProfile;

        $url = app(M3uProxyService::class)
            ->getEpisodeUrl(
                $playlist,
                $episode,
                $profile
            );

        return redirect($url);
    }

    /**
     * Example player endpoint for channel using m3u-proxy
     *
     * @param  int  $id
     * @param  string|null  $uuid
     * @return RedirectResponse
     */
    public function channelPlayer(Request $request, $id, $uuid = null)
    {
        $channel = Channel::query()->with([
            'playlist',
            'customPlaylist',
        ])->findOrFail($id);

        if ($uuid) {
            $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        } else {
            $playlist = $channel->getEffectivePlaylist();
        }

        // Load the stream profile relationships explicitly after getting the effective playlist
        if ($playlist) {
            $playlist->load('streamProfile', 'vodStreamProfile');
        }

        // Get stream profile from playlist if set
        $profile = null;
        if ($channel->is_vod) {
            // For VOD channels, use the VOD stream profile if set
            $profile = $playlist->vodStreamProfile;
        } else {
            // Get stream profile from playlist if set
            $profile = $playlist->streamProfile;
        }

        // If no profile set, use default profile for the player
        // Preview player should always try to transcode for better compatibility
        if (! $profile) {
            // Use default profile set for the player
            $settings = app(GeneralSettings::class);
            if ($channel->is_vod) {
                $profileId = $settings->default_vod_stream_profile_id ?? null;
            } else {
                $profileId = $settings->default_stream_profile_id ?? null;
            }
            $profile = $profileId ? StreamProfile::find($profileId) : null;
        }

        $url = app(M3uProxyService::class)
            ->getChannelUrl(
                $playlist,
                $channel,
                $request,
                $profile
            );

        return redirect($url);
    }

    /**
     * Example player endpoint for episode using m3u-proxy
     *
     * @param  int  $id
     * @param  string|null  $uuid
     * @return RedirectResponse
     */
    public function episodePlayer(Request $request, $id, $uuid = null)
    {
        $episode = Episode::query()->with([
            'playlist',
        ])->findOrFail($id);

        if ($uuid) {
            $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        } else {
            $playlist = $episode->playlist;
        }

        // Load the stream profile relationships explicitly after getting the playlist
        if ($playlist) {
            $playlist->load('streamProfile', 'vodStreamProfile');
        }

        // Get stream profile from playlist if set
        $profile = $playlist->vodStreamProfile;
        if (! $profile) {
            // Use default profile set for the player
            $settings = app(GeneralSettings::class);
            $profileId = $settings->default_vod_stream_profile_id ?? null;
            $profile = $profileId ? StreamProfile::find($profileId) : null;
        }

        $url = app(M3uProxyService::class)
            ->getEpisodeUrl(
                $playlist,
                $episode,
                $profile
            );

        return redirect($url);
    }

    /**
     * Validate failover URLs for smart failover handling.
     * This endpoint is called by m3u-proxy during failover to get a viable failover URL
     * based on playlist capacity.
     *
     * Request format:
     * {
     *   "current_url": "http://example.com/stream",
     *   "metadata": {
     *      "id": 123,
     *      "playlist_uuid": "abc-def-ghi",
     *   }
     * }
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resolveFailoverUrl(Request $request)
    {
        try {
            $currentUrl = $request->input('current_url');
            $metadata = $request->input('metadata', []);
            $failoverCount = $request->input('current_failover_index', 0);
            $channelId = $metadata['id'] ?? null;
            $playlistUuid = $metadata['playlist_uuid'] ?? null;

            if (! ($channelId && $currentUrl)) {
                return response()->json([
                    'next_url' => null,
                    'error' => 'Missing channel_id or current_url',
                ], 400);
            }

            // Use the M3uProxyService to validate the failover URLs
            $result = app(M3uProxyService::class)
                ->resolveFailoverUrl(
                    $channelId,
                    $playlistUuid,
                    $currentUrl,
                    index: $failoverCount
                );

            return response()->json($result);
        } catch (Exception $e) {
            Log::error('Error resolving failover: '.$e->getMessage(), $request->all());

            return response()->json([
                'next_url' => null,
                'error' => 'Validation failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle webhooks from m3u-proxy for real-time cache invalidation
     */
    public function handleWebhook(Request $request)
    {
        $eventType = $request->input('event_type');
        $streamId = $request->input('stream_id');
        $data = $request->input('data', []);

        Log::info('Received m3u-proxy webhook', [
            'event_type' => $eventType,
            'stream_id' => $streamId,
            'data' => $data,
        ]);

        // Invalidate caches based on event type
        switch ($eventType) {
            case 'client_connected':
            case 'client_disconnected':
            case 'stream_started':
            case 'stream_stopped':
                $this->invalidateStreamCaches($data);
                break;
        }

        return response()->json(['status' => 'ok']);
    }

    protected function invalidateStreamCaches(array $data): void
    {
        // Invalidate playlist-specific cache if we have metadata
        if (isset($data['playlist_uuid'])) {
            M3uProxyService::invalidateMetadataCache('playlist_uuid', $data['playlist_uuid']);
        }

        // Invalidate channel-specific cache if we have channel metadata
        if (isset($data['type'], $data['id'])) {
            M3uProxyService::invalidateMetadataCache('type', $data['type']);
            // We might also want to invalidate specific channel caches?
        }

        Log::info('Cache invalidated for m3u-proxy event', $data);
    }
}
