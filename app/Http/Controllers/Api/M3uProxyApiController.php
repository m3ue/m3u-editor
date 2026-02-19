<?php

namespace App\Http\Controllers\Api;

use App\Facades\PlaylistFacade;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Network;
use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Models\StreamProfile;
use App\Services\M3uProxyService;
use App\Services\NetworkBroadcastService;
use App\Services\ProfileService;
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

        // Get username from request (query parameter or header as fallback)
        $username = $request->input('username', $request->header('X-Username'));

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
                $profile,
                $username
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

        // Get username from request (query parameter or header as fallback)
        $username = $request->input('username', $request->header('X-Username'));

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
                $profile,
                $username
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
            $statusCode = $request->input('status_code');
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
                    index: $failoverCount,
                    statusCode: $statusCode ? (int) $statusCode : null,
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
     * and provider profile connection tracking
     */
    public function handleWebhook(Request $request)
    {
        $eventType = $request->input('event_type');
        $streamId = $request->input('stream_id');
        $data = $request->input('data', []);
        $metadata = $data['metadata'] ?? [];

        Log::info('Received m3u-proxy webhook', [
            'event_type' => $eventType,
            'stream_id' => $streamId,
            'data' => $data,
        ]);

        // Handle profile connection tracking if provider_profile_id is present
        if (isset($metadata['provider_profile_id'])) {
            $this->handleProfileConnectionTracking($eventType, $streamId, $metadata);
        }

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

    /**
     * Handle provider profile connection tracking based on webhook events
     */
    protected function handleProfileConnectionTracking(string $eventType, string $streamId, array $metadata): void
    {
        $profileId = $metadata['provider_profile_id'] ?? null;

        if (! $profileId) {
            return;
        }

        try {
            $profile = PlaylistProfile::find($profileId);

            if (! $profile) {
                Log::warning('Profile not found for connection tracking', [
                    'profile_id' => $profileId,
                    'stream_id' => $streamId,
                    'event_type' => $eventType,
                ]);

                return;
            }

            // Only decrement on stream_stopped events
            // This ensures we decrement exactly once per stream, avoiding race conditions
            if ($eventType === 'stream_stopped') {
                ProfileService::decrementConnections($profile, $streamId);

                Log::debug('Decremented profile connections via webhook', [
                    'profile_id' => $profileId,
                    'stream_id' => $streamId,
                    'event_type' => $eventType,
                    'new_count' => ProfileService::getConnectionCount($profile),
                ]);
            }
        } catch (Exception $e) {
            Log::error('Error handling profile connection tracking', [
                'profile_id' => $profileId,
                'stream_id' => $streamId,
                'event_type' => $eventType,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle broadcast callbacks from m3u-proxy.
     *
     * Called when a network broadcast's FFmpeg process exits (either
     * because the programme ended or due to an error).
     *
     * Request format:
     * {
     *   "network_id": "uuid-of-network",
     *   "event": "programme_ended" | "broadcast_failed",
     *   "timestamp": "2024-01-15T12:00:00Z",
     *   "data": {
     *     "exit_code": 0,
     *     "final_segment_number": 520,
     *     "duration_streamed": 3600.5,
     *     "error": "optional error message"
     *   }
     * }
     */
    public function handleBroadcastCallback(Request $request)
    {
        $networkId = $request->input('network_id');
        $event = $request->input('event');
        $data = $request->input('data', []);

        Log::info('Received broadcast callback from proxy', [
            'network_id' => $networkId,
            'event' => $event,
            'data' => $data,
        ]);

        if (! $networkId) {
            return response()->json(['error' => 'Missing network_id'], 400);
        }

        // Find network by UUID
        $network = Network::where('uuid', $networkId)->first();

        if (! $network) {
            Log::warning('Broadcast callback for unknown network', ['network_id' => $networkId]);

            return response()->json(['error' => 'Network not found'], 404);
        }

        try {
            $service = app(NetworkBroadcastService::class);

            switch ($event) {
                case 'programme_ended':
                    // Programme completed normally - transition to next
                    $this->handleProgrammeEnded($network, $data, $service);
                    break;

                case 'broadcast_failed':
                    // Broadcast failed - log error and attempt recovery
                    $this->handleBroadcastFailed($network, $data, $service);
                    break;

                default:
                    Log::warning('Unknown broadcast event', [
                        'network_id' => $networkId,
                        'event' => $event,
                    ]);
            }

            return response()->json(['status' => 'ok']);
        } catch (Exception $e) {
            Log::error('Error handling broadcast callback', [
                'network_id' => $networkId,
                'event' => $event,
                'exception' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle programme ended callback - transition to next programme.
     */
    protected function handleProgrammeEnded(Network $network, array $data, NetworkBroadcastService $service): void
    {
        $finalSegment = $data['final_segment_number'] ?? 0;
        $durationStreamed = $data['duration_streamed'] ?? 0;

        Log::info('Programme completed via proxy', [
            'network_id' => $network->id,
            'network_name' => $network->name,
            'final_segment' => $finalSegment,
            'duration_streamed' => $durationStreamed,
        ]);

        // Update segment sequence for next programme
        $network->update([
            'broadcast_segment_sequence' => $finalSegment + 1,
            'broadcast_started_at' => null,
            'broadcast_pid' => null,
            'broadcast_programme_id' => null,
            'broadcast_initial_offset_seconds' => null,
            'broadcast_error' => null,
        ]);

        // Increment discontinuity sequence for transition
        $network->increment('broadcast_discontinuity_sequence');

        // Check if there's a next programme to broadcast
        $network->refresh();
        $nextProgramme = $network->getCurrentProgramme() ?? $network->getNextProgramme();

        if ($nextProgramme && $network->broadcast_requested) {
            Log::info('Starting next programme via proxy', [
                'network_id' => $network->id,
                'programme_id' => $nextProgramme->id,
                'programme_title' => $nextProgramme->title,
            ]);

            // Start next programme
            $service->start($network);
        } else {
            Log::info('No next programme to broadcast', [
                'network_id' => $network->id,
                'broadcast_requested' => $network->broadcast_requested,
            ]);
        }
    }

    /**
     * Handle broadcast failed callback - attempt recovery.
     */
    protected function handleBroadcastFailed(Network $network, array $data, NetworkBroadcastService $service): void
    {
        $error = $data['error'] ?? 'Unknown error';
        $exitCode = $data['exit_code'] ?? -1;
        $finalSegment = $data['final_segment_number'] ?? 0;

        Log::warning('Broadcast failed via proxy', [
            'network_id' => $network->id,
            'network_name' => $network->name,
            'error' => $error,
            'exit_code' => $exitCode,
            'final_segment' => $finalSegment,
        ]);

        // Update network state with error
        $network->update([
            'broadcast_segment_sequence' => max($finalSegment, $network->broadcast_segment_sequence ?? 0),
            'broadcast_started_at' => null,
            'broadcast_pid' => null,
            'broadcast_error' => $error,
            // Keep programme reference for recovery
        ]);

        // Attempt restart if broadcast was requested and programme is still valid
        $network->refresh();
        $currentProgramme = $network->getCurrentProgramme();

        if ($currentProgramme && $network->broadcast_requested) {
            Log::info('Attempting broadcast recovery via proxy', [
                'network_id' => $network->id,
                'programme_id' => $currentProgramme->id,
            ]);

            // Small delay before retry to avoid rapid restart loops
            sleep(2);

            $service->start($network);
        }
    }
}
