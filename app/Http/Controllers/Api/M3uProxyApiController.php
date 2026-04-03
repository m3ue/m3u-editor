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
use Illuminate\Http\JsonResponse;
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
                $username,
                $request
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

        // Use in-app player transcoding profiles (from Preferences > In-App Player Transcoding).
        // Playlist-level stream profiles are for external clients only — they should not
        // apply to the in-app floating/popout player.
        $settings = app(GeneralSettings::class);
        if ($channel->is_vod) {
            $profileId = $settings->default_vod_stream_profile_id ?? null;
        } else {
            $profileId = $settings->default_stream_profile_id ?? null;
        }
        $profile = $profileId ? StreamProfile::find($profileId) : null;

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

        // Use in-app player VOD transcoding profile (from Preferences > In-App Player Transcoding).
        // Playlist-level stream profiles are for external clients only — they should not
        // apply to the in-app floating/popout player.
        $settings = app(GeneralSettings::class);
        $profileId = $settings->default_vod_stream_profile_id ?? null;
        $profile = $profileId ? StreamProfile::find($profileId) : null;

        $url = app(M3uProxyService::class)
            ->getEpisodeUrl(
                $playlist,
                $episode,
                $profile,
                null,
                $request
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
     * @return JsonResponse
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

            // Only clean up on stream_stopped events
            if ($eventType === 'stream_stopped') {
                ProfileService::decrementConnections($profile, $streamId);

                Log::debug('Cleaned up stream tracking via webhook', [
                    'profile_id' => $profileId,
                    'stream_id' => $streamId,
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

        // Clean up Plex transcode session before transitioning to next programme
        $service->cleanupTranscodeSession($network);

        // Update segment sequence for next programme
        $network->update([
            'broadcast_segment_sequence' => $finalSegment + 1,
            'broadcast_started_at' => null,
            'broadcast_pid' => null,
            'broadcast_programme_id' => null,
            'broadcast_initial_offset_seconds' => null,
            'broadcast_error' => null,
            'broadcast_fail_count' => 0,
            'broadcast_last_exit_code' => null,
            'broadcast_transcode_session_id' => null,
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
     * Handle broadcast failed callback - attempt recovery with exit code awareness.
     *
     * Fatal exit codes (no retry):
     *   127 = command not found (ffmpeg binary missing)
     *   126 = permission denied on binary
     *   125 = command itself fails (e.g. bad container setup)
     *
     * Transient exit codes (retry with backoff):
     *   8  = generic FFmpeg error (often stream negotiation failure)
     *   1  = generic error
     *   69 = service unavailable
     *   -1 = unknown / not reported
     *
     * Max retries: 5 with exponential backoff (10s, 20s, 40s, 80s, 120s).
     * For 5XX server errors: longer base (15s, 30s, 60s, 120s, 120s).
     */
    protected function handleBroadcastFailed(Network $network, array $data, NetworkBroadcastService $service): void
    {
        $error = $data['error'] ?? 'Unknown error';
        $exitCode = $data['exit_code'] ?? -1;
        $finalSegment = $data['final_segment_number'] ?? 0;
        $errorType = $data['error_type'] ?? null;

        // The proxy sends TWO callbacks per FFmpeg failure:
        // 1. Primary: contains exit_code and final_segment_number (the real FFmpeg exit)
        // 2. Detail: contains error_type="input_error" (supplementary error context)
        // Only the primary callback should drive retry logic and increment fail_count.
        // The detail callback is logged for observability but otherwise ignored.
        $isDetailCallback = $errorType === 'input_error';

        $isFatal = in_array($exitCode, [125, 126, 127], true);
        $isBootRecovery = $network->broadcast_boot_recovery_until && now()->lt($network->broadcast_boot_recovery_until);

        Log::warning('Broadcast failed via proxy', [
            'network_id' => $network->id,
            'network_name' => $network->name,
            'error' => $error,
            'exit_code' => $exitCode,
            'fatal' => $isFatal,
            'final_segment' => $finalSegment,
            'boot_recovery' => $isBootRecovery,
            'detail_only' => $isDetailCallback,
        ]);

        // Detail callbacks (error_type=input_error) are supplementary context from the
        // proxy — e.g. "Server returned 400 Bad Request". They arrive alongside the
        // primary callback that has the real exit_code. Only update the error message
        // for observability; do not modify fail_count or trigger retry logic.
        if ($isDetailCallback) {
            $network->update([
                'broadcast_error' => $error,
            ]);

            return;
        }

        // During boot recovery, the tick loop is the sole retry mechanism. The callback
        // handler must NOT increment fail_count or modify retry state because the tick
        // loop retries unconditionally every tick during the grace period.
        // We only update the error message for observability, preserving all other state.
        if ($isBootRecovery) {
            $network->update([
                'broadcast_error' => $error,
                'broadcast_last_exit_code' => $exitCode,
            ]);

            Log::info('Boot recovery: skipping callback state changes — tick loop handles retries', [
                'network_id' => $network->id,
                'exit_code' => $exitCode,
                'grace_period_until' => $network->broadcast_boot_recovery_until->toIso8601String(),
            ]);

            return;
        }

        $failCount = ($network->broadcast_fail_count ?? 0) + 1;
        $maxRetries = 5;

        // Clean up Plex transcode session (the old session is dead, free the slot)
        $service->cleanupTranscodeSession($network);

        // Update network state with error and retry tracking
        $network->update([
            'broadcast_segment_sequence' => max($finalSegment, $network->broadcast_segment_sequence ?? 0),
            'broadcast_started_at' => null,
            'broadcast_pid' => null,
            'broadcast_error' => $error,
            'broadcast_fail_count' => $failCount,
            'broadcast_last_failed_at' => now(),
            'broadcast_last_exit_code' => $exitCode,
            'broadcast_transcode_session_id' => null,
        ]);

        // Add explanatory text for common scenarios
        $integration = $network->mediaServerIntegration;
        if ($integration && $integration->isPlex() && $exitCode === 8) {
            // Check if this might be a recent boot recovery issue
            $recentBootRecovery = $network->broadcast_boot_recovery_until &&
                $network->broadcast_boot_recovery_until->diffInMinutes(now()) < 10;

            if ($recentBootRecovery) {
                $additionalInfo = ' — If the container just rebooted, Plex may still be releasing the previous transcode session. This is normal and will be retried automatically.';
            } else {
                $additionalInfo = ' — Plex returned 400 Bad Request, likely due to an orphaned transcode session. Will retry with backoff.';
            }

            // Update error message with additional context
            $network->update([
                'broadcast_error' => $error.$additionalInfo,
            ]);
        }

        // Fatal exit code — stop retrying entirely
        if ($isFatal) {
            Log::error('Broadcast failed with fatal exit code — stopping retries', [
                'network_id' => $network->id,
                'exit_code' => $exitCode,
                'error' => $error,
            ]);

            $network->update([
                'broadcast_requested' => false,
                'broadcast_error' => "Fatal error (exit code {$exitCode}): {$error}. Retries disabled — check your m3u-proxy container.",
            ]);

            return;
        }

        // Max retries exceeded — stop retrying
        if ($failCount >= $maxRetries) {
            Log::error('Broadcast exceeded max retries — stopping', [
                'network_id' => $network->id,
                'fail_count' => $failCount,
                'max_retries' => $maxRetries,
                'last_exit_code' => $exitCode,
            ]);

            // Build error message with context
            $errorMessage = "Failed after {$failCount} retries (last exit code {$exitCode}): {$error}";

            // Add explanatory text for common scenarios
            $integration = $network->mediaServerIntegration;
            if ($integration && $integration->isPlex() && $exitCode === 8) {
                // Check if this might be a recent boot recovery issue
                $recentBootRecovery = $network->broadcast_boot_recovery_until &&
                    $network->broadcast_boot_recovery_until->diffInMinutes(now()) < 10;

                if ($recentBootRecovery) {
                    $errorMessage .= ' — If the container just rebooted, Plex may still be releasing the previous transcode session. This is normal and should resolve in 2-3 minutes.';
                } else {
                    $errorMessage .= ' — Plex returned 400 Bad Request, likely due to an orphaned transcode session. Try stopping and restarting the broadcast.';
                }
            }

            $network->update([
                'broadcast_requested' => false,
                'broadcast_error' => $errorMessage,
            ]);

            return;
        }

        // Transient failure — retry with exponential backoff.
        // The proxy has already burned through its own 3 rapid retries before
        // calling this callback, so we start with a longer base delay to give
        // the media server time to recover (especially for 5XX errors).
        $network->refresh();
        $currentProgramme = $network->getCurrentProgramme();

        if ($currentProgramme && $network->broadcast_requested) {

            // Acquire restart lock to prevent tick loop from also restarting
            $network->update(['broadcast_restart_locked' => true]);

            // Use a higher base for server errors (5XX) since the server is struggling
            $isServerError = str_contains($error, '5XX') || str_contains($error, 'Server Error');
            $baseDelay = $isServerError ? 15 : 10;
            $backoffSeconds = min($baseDelay * (int) pow(2, $failCount - 1), 120);

            Log::info('Attempting broadcast recovery via proxy with backoff', [
                'network_id' => $network->id,
                'programme_id' => $currentProgramme->id,
                'fail_count' => $failCount,
                'backoff_seconds' => $backoffSeconds,
            ]);

            sleep($backoffSeconds);

            try {
                $service->start($network);
            } finally {
                // Always release the lock
                $network->update(['broadcast_restart_locked' => false]);
            }
        }
    }

    /**
     * Stop a proxy stream initiated by the in-app player.
     *
     * Called via sendBeacon from the browser when a floating/popout player is closed
     * or when the user navigates away. This is a best-effort signal; the proxy will
     * also detect the TCP connection drop independently.
     */
    public function stopPlayerStream(Request $request): Response
    {
        $id = $request->input('id');
        $type = $request->input('type');

        if (! $id || ! $type) {
            return response()->noContent(422);
        }

        $field = match ($type) {
            'channel' => 'channel_id',
            'episode' => 'episode_id',
            default => null,
        };

        if (! $field) {
            return response()->noContent(422);
        }

        try {
            M3uProxyService::stopStreamsByMetadata($field, (string) $id);
        } catch (Exception $e) {
            Log::warning("Failed to stop player stream ({$type}:{$id}): ".$e->getMessage());
        }

        return response()->noContent();
    }
}
