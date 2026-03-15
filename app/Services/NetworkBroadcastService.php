<?php

namespace App\Services;

use App\Enums\TranscodeMode;
use App\Models\MediaServerIntegration;
use App\Models\Network;
use App\Models\NetworkProgramme;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * NetworkBroadcastService - Manages continuous HLS broadcasting for Networks.
 *
 * This service handles:
 * 1. Starting broadcasts via the m3u-proxy service
 * 2. Monitoring running broadcasts
 * 3. Gracefully stopping broadcasts
 * 4. Building broadcast configuration for the proxy
 *
 * The actual FFmpeg processing is handled by the m3u-proxy service.
 * Laravel handles scheduling, programme transitions, and orchestration.
 */
class NetworkBroadcastService
{
    protected M3uProxyService $proxyService;

    public function __construct()
    {
        $this->proxyService = new M3uProxyService;
    }

    protected function getProxyService(): M3uProxyService
    {
        if (! isset($this->proxyService)) {
            $this->proxyService = new M3uProxyService;
        }

        return $this->proxyService;
    }

    /**
     * Start broadcasting for a network via the proxy.
     *
     * @return bool True if broadcast started successfully
     */
    public function start(Network $network): bool
    {
        if (! $network->enabled) {
            Log::warning('Cannot start broadcast: network not enabled', [
                'network_id' => $network->id,
                'network_name' => $network->name,
            ]);

            return false;
        }

        if (! $network->broadcast_enabled) {
            Log::warning('Cannot start broadcast: broadcast not enabled', [
                'network_id' => $network->id,
                'network_name' => $network->name,
            ]);

            return false;
        }

        // Check if scheduled start is enabled and we haven't reached the time yet
        if ($network->broadcast_schedule_enabled && $network->broadcast_scheduled_start) {
            if (now()->lt($network->broadcast_scheduled_start)) {
                Log::info('Cannot start broadcast - waiting for scheduled start time', [
                    'network_id' => $network->id,
                    'scheduled_start' => $network->broadcast_scheduled_start->toIso8601String(),
                    'seconds_remaining' => now()->diffInSeconds($network->broadcast_scheduled_start, false),
                ]);

                return false;
            }
        }

        // Check if already broadcasting via proxy
        if ($this->isProcessRunning($network)) {
            Log::info('Broadcast already running via proxy', [
                'network_id' => $network->id,
                'uuid' => $network->uuid,
            ]);

            return true;
        }

        // Determine programme to broadcast.
        // Priority: current programme > next programme > persisted (only if still valid)
        $programme = $network->getCurrentProgramme();

        if (! $programme) {
            // No current programme - try to get the next upcoming one
            $programme = $network->getNextProgramme();

            if ($programme) {
                Log::info('No current programme, using next upcoming programme', [
                    'network_id' => $network->id,
                    'next_programme_id' => $programme->id,
                    'next_programme_title' => $programme->title,
                    'next_programme_start' => $programme->start_time->toIso8601String(),
                ]);
            }
        }

        // Only fall back to persisted programme if it's STILL AIRING (not ended)
        if (! $programme && $network->broadcast_programme_id) {
            $persistedProgramme = NetworkProgramme::find($network->broadcast_programme_id);
            if ($persistedProgramme && $persistedProgramme->end_time->gt(now())) {
                // Persisted programme is still valid (hasn't ended yet)
                $programme = $persistedProgramme;
                Log::info('Using persisted programme that is still airing', [
                    'network_id' => $network->id,
                    'programme_id' => $programme->id,
                    'programme_title' => $programme->title,
                ]);
            } else {
                // Persisted programme has ended - clear the stale reference
                Log::info('Clearing stale persisted programme reference (programme has ended)', [
                    'network_id' => $network->id,
                    'old_programme_id' => $network->broadcast_programme_id,
                ]);
                $network->update([
                    'broadcast_programme_id' => null,
                    'broadcast_initial_offset_seconds' => null,
                ]);
            }
        }

        if (! $programme) {
            // During the boot grace period, treat missing programme as transient —
            // slow storage may not have loaded schedule data yet.
            if ($network->broadcast_boot_recovery_until && now()->lt($network->broadcast_boot_recovery_until)) {
                Log::warning('No programme found during boot grace period — will retry on next tick', [
                    'network_id' => $network->id,
                    'network_name' => $network->name,
                    'grace_period_until' => $network->broadcast_boot_recovery_until->toIso8601String(),
                ]);

                $network->update(['broadcast_error' => 'Waiting for schedule data (boot recovery)...']);

                return false;
            }

            Log::warning('No current programme to broadcast', [
                'network_id' => $network->id,
            ]);

            $network->update([
                'broadcast_requested' => false,
                'broadcast_error' => 'No programme scheduled to broadcast.',
            ]);

            return false;
        }

        // Compute seek position: if there is a persisted broadcast reference use it, otherwise use current programme seek
        $seekPosition = $network->getPersistedBroadcastSeekForNow() ?? $network->getCurrentSeekPosition();
        $remainingDuration = $network->getCurrentRemainingDuration();

        // If we have a session ID from a previous failed attempt (e.g. during boot
        // recovery retries), reuse it so PlexService calls /decision with the SAME
        // session instead of generating a new random one each time. This prevents
        // orphaned phantom sessions from accumulating on Plex, which would block new
        // transcode requests for the same media item.
        $existingSessionId = $network->broadcast_transcode_session_id;

        // Get stream URL with seek position built-in (media server handles seeking)
        $streamUrl = $this->getStreamUrl($network, $programme, $seekPosition, $existingSessionId);
        if (! $streamUrl) {
            // During the boot grace period, treat a missing stream URL as transient —
            // the media server integration or content data may not be available yet
            // on slow storage.
            if ($network->broadcast_boot_recovery_until && now()->lt($network->broadcast_boot_recovery_until)) {
                Log::warning('Failed to get stream URL during boot grace period — will retry on next tick', [
                    'network_id' => $network->id,
                    'network_name' => $network->name,
                    'grace_period_until' => $network->broadcast_boot_recovery_until->toIso8601String(),
                ]);

                $network->update(['broadcast_error' => 'Waiting for stream URL (boot recovery)...']);

                return false;
            }

            Log::error('Failed to get stream URL', [
                'network_id' => $network->id,
            ]);

            $network->update([
                'broadcast_requested' => false,
                'broadcast_error' => 'Failed to get stream URL from media server.',
            ]);

            return false;
        }

        Log::info("📍 BROADCAST SEEK CALCULATION: {$network->name}", [
            'network_id' => $network->id,
            'programme_id' => $programme->id,
            'programme_title' => $programme->title,
            'programme_start' => $programme->start_time->toIso8601String(),
            'programme_end' => $programme->end_time->toIso8601String(),
            'now' => now()->toIso8601String(),
            'seek_position_seconds' => $seekPosition,
            'seek_position_formatted' => gmdate('H:i:s', $seekPosition),
            'remaining_duration_seconds' => $remainingDuration,
            'remaining_duration_formatted' => gmdate('H:i:s', $remainingDuration),
        ]);

        // Start broadcast via proxy
        $result = $this->startViaProxy($network, $streamUrl, $seekPosition, $remainingDuration, $programme);

        if ($result === true) {
            // Success - clear any previous error, reset retry counters, and clear boot grace period
            $network->update([
                'broadcast_error' => null,
                'broadcast_fail_count' => 0,
                'broadcast_last_exit_code' => null,
                'broadcast_boot_recovery_until' => null,
            ]);
        } elseif ($result === null) {
            // Transient failure (proxy not reachable) - keep broadcast_requested = true
            // so the next tick will retry. Don't clear the request flag.
            Log::info('Broadcast start deferred due to transient proxy failure (will retry)', [
                'network_id' => $network->id,
                'network_name' => $network->name,
            ]);
        } else {
            // Permanent failure - clear broadcast_requested so it doesn't stay stuck on "Starting"
            $network->update(['broadcast_requested' => false]);
        }

        return (bool) $result;
    }

    /**
     * Start broadcast via the proxy service.
     */
    protected function startViaProxy(
        Network $network,
        string $streamUrl,
        int $seekPosition,
        int $remainingDuration,
        NetworkProgramme $programme
    ): ?bool {
        $startNumber = max(0, $network->broadcast_segment_sequence ?? 0);
        $addDiscontinuity = $startNumber > 0;

        // Get the callback URL
        $callbackUrl = $this->getProxyService()->getBroadcastCallbackUrl();

        // Only trust URL-based seeking when using server-side transcoding (TranscodeMode::Server).
        // For direct streams (static=true), media servers like Emby/Jellyfin ignore StartTimeTicks,
        // so FFmpeg must always handle seeking via -ss. Double-seeking is only a risk when the
        // server is actively transcoding and applying the seek itself.
        $isServerTranscode = ($network->transcode_mode ?? null) === TranscodeMode::Server;
        $urlHasSeeking = preg_match('/[?&](offset|StartTimeTicks)=/', $streamUrl);
        $ffmpegSeekSeconds = ($isServerTranscode && $urlHasSeeking) ? 0 : $seekPosition;

        // If using server-side transcoding, ensure we have a valid transcode start URL from the media server
        if ((($network->transcode_mode ?? null) === TranscodeMode::Server) && empty($streamUrl)) {
            Log::error('Failed to construct transcode start URL for server-side transcoding', [
                'network_id' => $network->id,
                'network_uuid' => $network->uuid,
            ]);

            $network->update([
                'broadcast_error' => 'Failed to obtain transcode start URL from media server.',
            ]);

            return false;
        }

        $payload = [
            'stream_url' => $streamUrl,
            'seek_seconds' => $ffmpegSeekSeconds,
            'duration_seconds' => $remainingDuration,
            'segment_start_number' => $startNumber,
            'add_discontinuity' => $addDiscontinuity,
            'segment_duration' => $network->segment_duration ?? 6,
            'hls_list_size' => $network->hls_list_size ?? 20,
            // transcode => true tells the proxy to run FFmpeg for this broadcast.
            // Local mode -> proxy should transcode; Server/Direct -> proxy should passthrough
            'transcode' => ($network->transcode_mode ?? null) === TranscodeMode::Local,
            'video_bitrate' => $network->video_bitrate ? (string) $network->video_bitrate : null,
            'audio_bitrate' => $network->audio_bitrate ?? 192,
            'video_resolution' => $network->video_resolution,
            // Additional transcode settings for local mode
            'video_codec' => $network->video_codec,
            'audio_codec' => $network->audio_codec,
            'preset' => $network->transcode_preset,
            'hwaccel' => $network->hwaccel,
            'callback_url' => $callbackUrl,
        ];

        // Attach provider-specific headers for Plex.
        // IMPORTANT: For direct file downloads (TranscodeMode::Direct / Local), Plex returns
        // 503 if X-Plex-Client-Identifier is present — it tries to manage a playback session
        // for the client but has no active session for a raw file request.
        // Only send full Plex session headers when using server-side transcoding.
        try {
            $integration = $network->mediaServerIntegration;
            if ($integration && $integration->isPlex()) {
                $parsed = parse_url($streamUrl);
                parse_str($parsed['query'] ?? '', $qs);

                $isServerTranscode = ($network->transcode_mode ?? null) === TranscodeMode::Server;

                if ($isServerTranscode) {
                    // Server-side transcoding: full Plex session headers required
                    $headers = [
                        'X-Plex-Product' => 'Plex Web',
                        'X-Plex-Client-Identifier' => 'm3u-proxy',
                        'X-Plex-Platform' => 'Chrome',
                        'X-Plex-Device' => 'OSX',
                    ];

                    if (! empty($qs['X-Plex-Token'])) {
                        $headers['X-Plex-Token'] = $qs['X-Plex-Token'];
                    }

                    // Add playback session headers if present
                    if (! empty($qs['session'])) {
                        $headers['X-Plex-Session-Identifier'] = $qs['session'];
                        $headers['X-Plex-Playback-Session-Id'] = $qs['session'];
                    }

                    // Set Accept header based on stream format
                    if (str_contains($streamUrl, 'start.mpd')) {
                        $headers['Accept'] = 'application/dash+xml';
                    } else {
                        $headers['Accept'] = 'application/vnd.apple.mpegurl';
                    }

                    $payload['headers'] = $headers;
                } else {
                    // Direct / Local mode: only send the token header, no session identifiers
                    $headers = [];

                    if (! empty($qs['X-Plex-Token'])) {
                        $headers['X-Plex-Token'] = $qs['X-Plex-Token'];
                    }

                    if (! empty($headers)) {
                        $payload['headers'] = $headers;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to attach provider-specific headers for broadcast', [
                'network_id' => $network->id,
                'exception' => $e->getMessage(),
            ]);
        }

        Log::info('Starting broadcast via proxy', [
            'network_id' => $network->id,
            'network_uuid' => $network->uuid,
            'payload' => array_merge($payload, ['stream_url' => '***']), // Hide URL in logs
        ]);

        // Debug: log the unmasked stream URL at debug level so we can diagnose start failures
        Log::debug('Broadcast stream URL (debug only)', [
            'network_id' => $network->id,
            'stream_url' => $streamUrl,
        ]);

        // Extract the Plex transcode session ID from the stream URL upfront.
        // This is needed on success (to track it), on proxy error (to persist for reuse
        // or clean up), and on exception (to persist for reuse on next retry).
        $transcodeSessionId = $this->extractSessionIdFromUrl($streamUrl);

        try {
            $response = $this->getProxyService()->proxyRequest(
                'POST',
                "/broadcast/{$network->uuid}/start",
                $payload
            );

            if ($response->successful()) {
                $data = $response->json();

                // Update network with broadcast info
                $network->update([
                    'broadcast_started_at' => Carbon::now(),
                    'broadcast_pid' => $data['ffmpeg_pid'] ?? null,
                    'broadcast_programme_id' => $programme->id,
                    'broadcast_initial_offset_seconds' => $seekPosition,
                    'broadcast_transcode_session_id' => $transcodeSessionId,
                ]);

                $logMessage = "🟢 BROADCAST STARTED VIA PROXY: {$network->name}";
                $logData = [
                    'network_id' => $network->id,
                    'uuid' => $network->uuid,
                    'ffmpeg_pid' => $data['ffmpeg_pid'] ?? null,
                    'status' => $data['status'] ?? 'unknown',
                    'transcode_session_id' => $transcodeSessionId,
                ];

                // Add recovery message if this was a boot recovery restart
                if ($network->broadcast_boot_recovery_until && now()->lt($network->broadcast_boot_recovery_until)) {
                    $logMessage = "🟢 BROADCAST RECOVERED VIA PROXY: {$network->name}";
                    $logData['recovery'] = 'boot_recovery';

                    // Also log a prominent recovery message that will show in console
                    Log::info("🎉 BROADCAST RECOVERY COMPLETE: {$network->name} is back online after container restart");

                    // Direct console output to ensure it shows up in container logs (not during tests)
                    if (app()->isProduction()) {
                        echo "🎉 [RECOVERY] {$network->name} is now broadcasting again after container restart\n";
                    }
                }

                Log::info($logMessage, $logData);

                Log::info("🟢 BROADCAST STARTED VIA PROXY: {$network->name}", [
                    'network_id' => $network->id,
                    'uuid' => $network->uuid,
                    'ffmpeg_pid' => $data['ffmpeg_pid'] ?? null,
                    'status' => $data['status'] ?? 'unknown',
                    'transcode_session_id' => $transcodeSessionId,
                ]);

                return true;
            }

            $errorMessage = $response->json('detail') ?? $response->body();
            Log::error('Proxy returned error when starting broadcast', [
                'network_id' => $network->id,
                'status' => $response->status(),
                'error' => $errorMessage,
            ]);

            $network->update([
                'broadcast_error' => "Proxy error: {$errorMessage}",
            ]);

            // During boot recovery grace period, treat proxy errors as transient
            // so the tick loop retries instead of permanently giving up.
            // This covers the case where Plex needs a few seconds to release
            // the old transcode session after we cleaned it up in boot recovery.
            if ($network->broadcast_boot_recovery_until && now()->lt($network->broadcast_boot_recovery_until)) {
                // Persist the session ID from this attempt so the NEXT retry reuses it
                // via start() → getStreamUrl(). This prevents accumulating orphaned
                // phantom sessions on Plex — each retry will re-prime the same session
                // instead of creating a new one.
                if ($transcodeSessionId) {
                    $network->update([
                        'broadcast_transcode_session_id' => $transcodeSessionId,
                    ]);
                }

                Log::info('Proxy error during boot grace period — treating as transient (will retry with same session)', [
                    'network_id' => $network->id,
                    'status' => $response->status(),
                    'grace_period_until' => $network->broadcast_boot_recovery_until->toIso8601String(),
                    'reuse_session_id' => $transcodeSessionId,
                ]);

                return null;
            }

            // Outside of boot recovery: try to stop the orphaned session since we won't retry.
            // Each call to getStreamUrl() for server-side transcode calls the Plex
            // /decision endpoint which registers a new session. If the broadcast start
            // fails, this orphaned session lingers and can cause Plex to return 400
            // Bad Request for subsequent attempts on the same media item.
            $this->stopOrphanedTranscodeSession($network, $transcodeSessionId);

            return false;
        } catch (\Exception $e) {
            // Transient failure (proxy not reachable yet, e.g. during container boot)
            // Return null so the caller knows to retry on the next tick
            Log::warning('Failed to connect to proxy for broadcast (transient, will retry)', [
                'network_id' => $network->id,
                'exception' => $e->getMessage(),
            ]);

            // Persist the session ID so the next retry reuses it instead of creating
            // a new phantom session on Plex via /decision.
            $updateData = ['broadcast_error' => "Failed to connect to proxy: {$e->getMessage()}"];
            if ($transcodeSessionId) {
                $updateData['broadcast_transcode_session_id'] = $transcodeSessionId;
            }
            $network->update($updateData);

            return null;
        }
    }

    /**
     * Stop broadcasting for a network via the proxy.
     */
    public function stop(Network $network): bool
    {
        // Clean up Plex transcode session before stopping
        $this->cleanupTranscodeSession($network);

        // First try to stop via proxy
        try {
            $response = $this->getProxyService()->proxyRequest(
                'POST',
                "/broadcast/{$network->uuid}/stop"
            );

            if ($response->successful()) {
                $data = $response->json();
                $finalSegment = $data['final_segment_number'] ?? 0;

                Log::info("🔴 BROADCAST STOPPED VIA PROXY: {$network->name}", [
                    'network_id' => $network->id,
                    'uuid' => $network->uuid,
                    'final_segment' => $finalSegment,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to stop broadcast via proxy (may already be stopped)', [
                'network_id' => $network->id,
                'exception' => $e->getMessage(),
            ]);
        }

        // Always update local state
        $network->update([
            'broadcast_started_at' => null,
            'broadcast_pid' => null,
            'broadcast_programme_id' => null,
            'broadcast_initial_offset_seconds' => null,
            'broadcast_requested' => false,
            // Reset sequences on explicit stop - next start will be a fresh broadcast
            'broadcast_segment_sequence' => 0,
            'broadcast_discontinuity_sequence' => 0,
            // Reset retry tracking
            'broadcast_fail_count' => 0,
            'broadcast_last_exit_code' => null,
            'broadcast_restart_locked' => false,
            'broadcast_transcode_session_id' => null,
        ]);

        // Clean up via proxy (removes files)
        try {
            $this->getProxyService()->proxyRequest('DELETE', "/broadcast/{$network->uuid}");
        } catch (\Exception $e) {
            Log::warning('Failed to cleanup broadcast files via proxy', [
                'network_id' => $network->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * Check if a network's broadcast is running via the proxy.
     */
    public function isProcessRunning(Network $network): bool
    {
        try {
            $response = $this->getProxyService()->proxyRequest(
                'GET',
                "/broadcast/{$network->uuid}/status"
            );

            if ($response->successful()) {
                $data = $response->json();

                return in_array($data['status'] ?? '', ['running', 'starting']);
            }

            // 404 means no broadcast running
            if ($response->status() === 404) {
                return false;
            }

            return false;
        } catch (\Exception $e) {
            // Connection error - assume not running
            Log::debug('Could not check broadcast status via proxy', [
                'network_id' => $network->id,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get the stream URL for the current programme content.
     *
     * @param  int  $seekSeconds  Seek position in seconds (0 = start)
     */
    protected function getStreamUrl(Network $network, NetworkProgramme $programme, int $seekSeconds = 0, ?string $sessionId = null): ?string
    {
        $content = $programme->contentable;
        if (! $content) {
            return null;
        }

        // Get media server integration
        $integration = $network->mediaServerIntegration;

        if (! $integration) {
            $integration = $this->getIntegrationFromContent($content);
        }

        if (! $integration) {
            Log::error('No media server integration found', [
                'network_id' => $network->id,
                'programme_id' => $programme->id,
            ]);

            return null;
        }

        // Get item ID
        $itemId = $this->getMediaServerItemId($content);
        if (! $itemId) {
            Log::error('No media server item ID found', [
                'network_id' => $network->id,
                'content_type' => get_class($content),
                'content_id' => $content->id,
            ]);

            return null;
        }

        $service = MediaServerService::make($integration);

        // IMPORTANT: Create a fresh Request object each time. Do NOT use request()
        // (the global singleton) because in the long-running worker process it persists
        // across ticks. Parameters like static=true and StartTimeTicks from a previous
        // broadcast would leak into subsequent calls, causing 400 Bad Request errors
        // when the transcode mode changes.
        $request = new \Illuminate\Http\Request;

        // static=true tells media servers to send the raw file without transcoding.
        // Only set it when NOT using server-side transcoding, otherwise it contradicts
        // the transcode parameters and can cause 400 Bad Request errors.
        if (($network->transcode_mode ?? null) !== TranscodeMode::Server) {
            $request->merge(['static' => 'true']); // static stream for HLS
        }

        // Use media server's native seeking if we need to seek
        if ($seekSeconds > 0) {
            // Jellyfin/Emby use ticks (100-nanosecond intervals)
            $startTimeTicks = $seekSeconds * 10_000_000;
            $request->merge(['StartTimeTicks' => $startTimeTicks]);

            Log::debug('📍 Media server seek applied', [
                'network_id' => $network->id,
                'item_id' => $itemId,
                'seek_seconds' => $seekSeconds,
                'seek_ticks' => $startTimeTicks,
            ]);
        }

        // If using server-side transcoding, attach transcode options to the request
        $transcodeOptions = [];
        if (($network->transcode_mode ?? null) === TranscodeMode::Server) {
            if ($network->video_bitrate) {
                $transcodeOptions['video_bitrate'] = (int) $network->video_bitrate;
            }
            if ($network->audio_bitrate) {
                $transcodeOptions['audio_bitrate'] = (int) $network->audio_bitrate;
            }
            if ($network->video_resolution) {
                $parts = explode('x', $network->video_resolution);
                $w = $parts[0] ?? null;
                $h = $parts[1] ?? null;

                if ($w) {
                    $transcodeOptions['max_width'] = (int) $w;
                }
                if ($h) {
                    $transcodeOptions['max_height'] = (int) $h;
                }
            }

            // Forward codec and preset hints so the media server can honour them
            if (! empty($network->video_codec)) {
                $transcodeOptions['video_codec'] = $network->video_codec;
            }
            if (! empty($network->audio_codec)) {
                $transcodeOptions['audio_codec'] = $network->audio_codec;
            }
            if (! empty($network->transcode_preset)) {
                $transcodeOptions['preset'] = $network->transcode_preset;
            }
        }

        // Forward the session ID so PlexService can reuse it instead of generating
        // a new random one. This prevents orphaned phantom sessions from accumulating
        // on Plex when retrying after a failed start.
        if ($sessionId !== null) {
            $transcodeOptions['session_id'] = $sessionId;
        }

        // For broadcasts, skip Plex's transcode endpoint and use direct file access.
        // This avoids Plex's remuxing overhead which can cause segment 404 errors
        // when Plex can't keep up with real-time demands. FFmpeg will handle all
        // transcoding locally instead (much more reliable for live broadcasting).
        $transcodeOptions['skip_plex_transcode'] = true;

        $streamUrl = $service->getDirectStreamUrl($request, $itemId, 'ts', $transcodeOptions);

        return $streamUrl;
    }

    /**
     * Get the media server item ID from content.
     */
    protected function getMediaServerItemId($content): ?string
    {
        // First priority: Check info array for media server ID
        // This is the most reliable for media server content
        if (isset($content->info['media_server_id'])) {
            return (string) $content->info['media_server_id'];
        }

        // Check for source_episode_id (for Episodes from Xtream providers)
        if (isset($content->source_episode_id) && $content->source_episode_id) {
            return (string) $content->source_episode_id;
        }

        // Check for source_channel_id (for Channels/VOD from Xtream providers)
        if (isset($content->source_channel_id) && $content->source_channel_id) {
            return (string) $content->source_channel_id;
        }

        // For channels that might store it differently (VOD movie data)
        if (isset($content->movie_data['movie_data']['id'])) {
            return (string) $content->movie_data['movie_data']['id'];
        }

        return null;
    }

    /**
     * Get media server integration from content.
     */
    protected function getIntegrationFromContent($content): ?MediaServerIntegration
    {
        // Try to extract from cover URL
        $coverUrl = $content->info['cover_big'] ?? $content->info['movie_image'] ?? null;
        if ($coverUrl && preg_match('#/media-server/(\d+)/#', $coverUrl, $matches)) {
            return MediaServerIntegration::find((int) $matches[1]);
        }

        // Try playlist's integration
        if (isset($content->playlist_id) && $content->playlist) {
            if ($content->playlist->media_server_integration_id) {
                return MediaServerIntegration::find($content->playlist->media_server_integration_id);
            }
        }

        return null;
    }

    /**
     * Clean up an active Plex transcode session for a network.
     *
     * This calls the Plex `/video/:/transcode/universal/stop` endpoint to
     * release the server-side transcode slot. Without this, orphaned sessions
     * linger and cause 400 Bad Request when starting a new session.
     */
    public function cleanupTranscodeSession(Network $network): void
    {
        $sessionId = $network->broadcast_transcode_session_id;
        if (empty($sessionId)) {
            return;
        }

        $integration = $network->mediaServerIntegration;
        if (! $integration || ! $integration->isPlex()) {
            return;
        }

        try {
            $plexService = new PlexService($integration);
            $stopped = $plexService->stopTranscodeSession($sessionId);

            // After stopping, verify the session is actually released before proceeding.
            // Plex can acknowledge the stop but take several seconds to fully release
            // the session internally, causing 400 Bad Request on the next start attempt.
            if ($stopped && ! app()->runningUnitTests()) {
                $plexService->waitForTranscodeSessionRelease($sessionId, maxAttempts: 6, intervalSeconds: 2);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to stop Plex transcode session during cleanup', [
                'network_id' => $network->id,
                'session_id' => $sessionId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract the Plex transcode session ID from a stream URL.
     *
     * For server-side transcoding, the stream URL contains a `session` query
     * parameter that was generated by getDirectStreamUrl() when it called the
     * Plex /decision endpoint. This ID is needed to track and clean up the
     * server-side transcode session.
     */
    protected function extractSessionIdFromUrl(string $streamUrl): ?string
    {
        $parsed = parse_url($streamUrl);
        parse_str($parsed['query'] ?? '', $qs);

        return ! empty($qs['session']) ? $qs['session'] : null;
    }

    /**
     * Stop an orphaned Plex transcode session that was primed but never used.
     *
     * When getStreamUrl() is called for server-side transcoding, it calls the
     * Plex /decision endpoint which registers a new transcode session. If the
     * subsequent broadcast start fails, this session lingers on Plex and can
     * cause 400 Bad Request for future attempts on the same media item.
     * This method fires a stop request to release it immediately.
     */
    protected function stopOrphanedTranscodeSession(Network $network, ?string $sessionId): void
    {
        if (empty($sessionId)) {
            return;
        }

        $integration = $network->mediaServerIntegration;
        if (! $integration || ! $integration->isPlex()) {
            return;
        }

        try {
            $plexService = new PlexService($integration);
            $stopped = $plexService->stopTranscodeSession($sessionId);

            if ($stopped) {
                Log::info('Stopped orphaned Plex transcode session after failed start', [
                    'network_id' => $network->id,
                    'session_id' => $sessionId,
                ]);
            }
        } catch (\Exception $e) {
            Log::debug('Could not stop orphaned Plex transcode session (may not exist)', [
                'network_id' => $network->id,
                'session_id' => $sessionId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get status information for a network's broadcast.
     */
    public function getStatus(Network $network): array
    {
        $isRunning = $this->isProcessRunning($network);

        $status = [
            'enabled' => $network->broadcast_enabled,
            'running' => $isRunning,
            'pid' => $network->broadcast_pid,
            'started_at' => $network->broadcast_started_at?->toIso8601String(),
            'hls_url' => $this->getProxyService()->getProxyBroadcastHlsUrl($network),
        ];

        if ($isRunning) {
            $programme = $network->getCurrentProgramme();
            if ($programme) {
                $status['current_programme'] = [
                    'title' => $programme->title,
                    'start_time' => $programme->start_time->toIso8601String(),
                    'end_time' => $programme->end_time->toIso8601String(),
                    'elapsed_seconds' => $programme->getCurrentOffsetSeconds(),
                    'remaining_seconds' => $network->getCurrentRemainingDuration(),
                ];
            }
        }

        return $status;
    }

    /**
     * Check if a network's broadcast needs to be restarted.
     * This is called by the worker loop to determine if we need to switch content.
     */
    public function needsRestart(Network $network): bool
    {
        // Not enabled - no restart needed
        if (! $network->broadcast_enabled) {
            return false;
        }

        // Process died - needs restart if there's content to play
        if (! $this->isProcessRunning($network) && $network->broadcast_requested) {
            return $network->getCurrentProgramme() !== null;
        }

        return false;
    }

    /**
     * Restart the broadcast (stop if running, then start).
     * Used for content transitions.
     */
    public function restart(Network $network): bool
    {
        // Stop any existing broadcast
        $this->stop($network);
        $network->refresh();

        // Start fresh
        return $this->start($network);
    }

    /**
     * Run a single tick of the broadcast worker for a network.
     * This should be called periodically by the worker command.
     *
     * Note: With proxy mode, programme transitions are handled by callbacks
     * from the proxy when FFmpeg exits. The tick is mainly for monitoring
     * and handling cases where callbacks might be missed.
     *
     * @return array Status info about what happened
     */
    public function tick(Network $network): array
    {
        $result = [
            'network_id' => $network->id,
            'action' => 'none',
            'success' => true,
        ];

        // Refresh network state
        $network->refresh();

        // Not enabled - ensure stopped
        if (! $network->broadcast_enabled) {
            if ($network->broadcast_requested) {
                $this->stop($network);
                $result['action'] = 'stopped';
            }

            return $result;
        }

        // Check if scheduled start time is enabled and we haven't reached it yet
        if ($network->broadcast_schedule_enabled && $network->broadcast_scheduled_start) {
            if (now()->lt($network->broadcast_scheduled_start)) {
                if ($network->broadcast_requested && $this->isProcessRunning($network)) {
                    $this->stop($network);
                    $result['action'] = 'stopped_waiting_for_schedule';
                } else {
                    $result['action'] = 'waiting_for_scheduled_start';
                    $result['scheduled_start'] = $network->broadcast_scheduled_start->toIso8601String();
                    $result['seconds_until_start'] = now()->diffInSeconds($network->broadcast_scheduled_start, false);
                }

                return $result;
            }
        }

        // Optimization: Skip proxy status check for networks that aren't supposed to be broadcasting
        // and don't have any lingering state that suggests they might be running
        if (! $network->broadcast_requested && ! $network->broadcast_pid && ! $network->broadcast_started_at) {
            $result['action'] = 'idle';

            return $result;
        }

        // Check if broadcast is running via proxy
        $isRunning = $this->isProcessRunning($network);

        // Get current or next programme
        $programme = $network->getCurrentProgramme();

        if (! $programme) {
            $programme = $network->getNextProgramme();
        }

        // No current or next programme - stop if running
        if (! $programme) {
            if ($isRunning || $network->broadcast_requested) {
                $this->stop($network);
                $result['action'] = 'stopped_no_content';
            } else {
                $result['action'] = 'no_content';
            }

            return $result;
        }

        // Should be running but isn't - start it (only if user requested it)
        if (! $isRunning && $network->broadcast_requested) {
            // Skip if a callback handler is already restarting this broadcast
            if ($network->broadcast_restart_locked) {
                $result['action'] = 'restart_locked';

                return $result;
            }

            Log::info('🔄 BROADCAST RECOVERY: Restarting broadcast via proxy', [
                'network_id' => $network->id,
                'network_name' => $network->name,
                'programme_id' => $programme->id,
                'programme_title' => $programme->title,
            ]);

            $success = $this->start($network);
            $result['action'] = 'started';
            $result['success'] = $success;
            $result['programme'] = $programme->title;

            // Direct console output for recovery success (not during tests)
            if ($success && app()->isProduction()) {
                echo "🔄 [TICK RECOVERY] {$network->name} broadcast restarted successfully\n";
            }

            return $result;
        }

        // broadcast_requested is false and not running - just report idle
        if (! $isRunning) {
            $result['action'] = 'idle';

            return $result;
        }

        // Running normally - report monitoring status
        $remaining = $network->getCurrentRemainingDuration();
        $result['action'] = 'monitoring';
        $result['remaining_seconds'] = $remaining;

        return $result;
    }

    /**
     * Get all networks that should be broadcasting.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getBroadcastingNetworks()
    {
        return Network::where('broadcast_enabled', true)
            ->where('enabled', true)
            ->get();
    }

    /**
     * Perform boot recovery for broadcast networks.
     *
     * After an unclean container shutdown, broadcast_requested may have been cleared
     * by a transient proxy failure, or stale process state (pid, started_at) may
     * linger. This method resets that state so the tick loop can restart broadcasts.
     *
     * A boot grace period (broadcast_boot_recovery_until) is stamped so that
     * transient failures during slow-storage startup — such as missing programme
     * data or stream URL lookups — are retried rather than permanently giving up.
     *
     * Call this ONCE before the continuous worker loop (not for --once mode).
     *
     * @param  Network|null  $network  If provided, recover only this network. Otherwise recover all broadcasting networks.
     * @return int Number of networks recovered
     */
    public function performBootRecovery(?Network $network = null): int
    {
        if ($network) {
            $networks = collect([$network])->filter(fn (Network $n) => $n->broadcast_enabled && $n->enabled);
        } else {
            $networks = $this->getBroadcastingNetworks();
        }

        $recovered = 0;
        $gracePeriodUntil = now()->addMinutes(4);

        // Wait briefly for the proxy to become available after boot.
        // Cleanup calls below need the proxy, so give it a few seconds to start.
        $this->waitForProxy(maxAttempts: 6, intervalSeconds: 5);

        foreach ($networks as $net) {
            // 1. Clean up orphaned Plex transcode sessions BEFORE clearing the session ID.
            //    Without this, Plex may refuse new /decision requests (400 Bad Request)
            //    because the old transcode session is still active server-side.
            if ($net->broadcast_transcode_session_id) {
                $this->cleanupTranscodeSession($net);
            }

            // 2. Stop any lingering FFmpeg processes in the proxy for this network.
            //    After a hard reboot this is unlikely to find anything, but is defensive.
            try {
                $this->getProxyService()->proxyRequest(
                    'POST',
                    "/broadcast/{$net->uuid}/stop"
                );
            } catch (\Exception $e) {
                Log::debug('BOOT RECOVERY: Could not stop broadcast via proxy (expected if not running)', [
                    'network_id' => $net->id,
                    'exception' => $e->getMessage(),
                ]);
            }

            // 3. Delete stale segment files from disk via the proxy.
            //    Without this, old .ts files and manifests accumulate across reboots.
            try {
                $this->getProxyService()->proxyRequest('DELETE', "/broadcast/{$net->uuid}");
            } catch (\Exception $e) {
                Log::debug('BOOT RECOVERY: Could not cleanup broadcast files via proxy', [
                    'network_id' => $net->id,
                    'exception' => $e->getMessage(),
                ]);
            }

            // 4. Reset DB state so the tick loop will attempt a fresh start.
            $net->update([
                'broadcast_requested' => true,
                'broadcast_pid' => null,
                'broadcast_started_at' => null,
                'broadcast_error' => null,
                'broadcast_fail_count' => 0,
                'broadcast_last_exit_code' => null,
                'broadcast_restart_locked' => false,
                'broadcast_transcode_session_id' => null,
                'broadcast_boot_recovery_until' => $gracePeriodUntil,
                // Reset sequences — stale segments were just deleted, so we start fresh
                'broadcast_segment_sequence' => 0,
                'broadcast_discontinuity_sequence' => 0,
            ]);

            $recovered++;

            Log::info('BOOT RECOVERY: Cleaned up and marked network for broadcast restart', [
                'network_id' => $net->id,
                'network_name' => $net->name,
                'grace_period_until' => $gracePeriodUntil->toIso8601String(),
            ]);
        }

        if ($recovered > 0) {
            Log::info("BOOT RECOVERY: Recovered {$recovered} network(s) for broadcast");
            Log::info('🚀 CONTAINER BOOT RECOVERY COMPLETE: Broadcasting systems are ready');

            // Direct console output to ensure it shows up in container logs (not during tests)
            if (app()->isProduction()) {
                echo "🚀 [BOOT RECOVERY] Container recovery complete - {$recovered} network(s) ready for broadcasting\n";
            }
        }

        return $recovered;
    }

    /**
     * Wait for the m3u-proxy service to become reachable.
     *
     * During container boot, supervisor starts both Laravel and the proxy
     * concurrently. The proxy may not be ready when boot recovery runs,
     * so we poll its health endpoint with a short backoff.
     *
     * @param  int  $maxAttempts  Maximum number of attempts
     * @param  int  $intervalSeconds  Seconds between attempts
     * @return bool True if the proxy responded, false if all attempts failed
     */
    protected function waitForProxy(int $maxAttempts = 6, int $intervalSeconds = 5): bool
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->getProxyService()->proxyRequest('GET', '/health');
                if ($response->successful()) {
                    Log::info('BOOT RECOVERY: Proxy is ready', ['attempt' => $attempt]);

                    return true;
                }
            } catch (\Exception $e) {
                // Proxy not ready yet
            }

            if ($attempt < $maxAttempts) {
                Log::info("BOOT RECOVERY: Waiting for proxy to start (attempt {$attempt}/{$maxAttempts})...");
                sleep($intervalSeconds);
            }
        }

        Log::warning('BOOT RECOVERY: Proxy did not become ready within timeout — cleanup calls may fail');

        return false;
    }
}
