<?php

namespace App\Services;

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
        // Initialize the M3uProxyService
        // We'll use this to communicate with the proxy for broadcast management
        $this->proxyService = new M3uProxyService;
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

        // Get stream URL with seek position built-in (media server handles seeking)
        $streamUrl = $this->getStreamUrl($network, $programme, $seekPosition);
        if (! $streamUrl) {
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

        // Clear error on successful start
        if ($result) {
            $network->update(['broadcast_error' => null]);
        } else {
            // Clear broadcast_requested on failure so it doesn't stay stuck on "Starting"
            $network->update(['broadcast_requested' => false]);
        }

        return $result;
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
    ): bool {
        $startNumber = max(0, $network->broadcast_segment_sequence ?? 0);
        $addDiscontinuity = $startNumber > 0;

        // Get the callback URL
        $callbackUrl = $this->proxyService->getBroadcastCallbackUrl();
        $payload = [
            'stream_url' => $streamUrl,
            'seek_seconds' => $seekPosition,
            'duration_seconds' => $remainingDuration,
            'segment_start_number' => $startNumber,
            'add_discontinuity' => $addDiscontinuity,
            'segment_duration' => $network->segment_duration ?? 6,
            'hls_list_size' => $network->hls_list_size ?? 20,
            'transcode' => ! $network->transcode_on_server, // Invert: if server transcodes, proxy copies
            'video_bitrate' => $network->video_bitrate ? (string) $network->video_bitrate : null,
            'audio_bitrate' => $network->audio_bitrate ?? 192,
            'video_resolution' => $network->video_resolution,
            'callback_url' => $callbackUrl,
        ];

        Log::info('Starting broadcast via proxy', [
            'network_id' => $network->id,
            'network_uuid' => $network->uuid,
            'payload' => array_merge($payload, ['stream_url' => '***']), // Hide URL in logs
        ]);

        try {
            $response = $this->proxyService->proxyRequest(
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
                    'broadcast_requested' => true,
                ]);

                Log::info("🟢 BROADCAST STARTED VIA PROXY: {$network->name}", [
                    'network_id' => $network->id,
                    'uuid' => $network->uuid,
                    'ffmpeg_pid' => $data['ffmpeg_pid'] ?? null,
                    'status' => $data['status'] ?? 'unknown',
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

            return false;
        } catch (\Exception $e) {
            Log::error('Failed to start broadcast via proxy', [
                'network_id' => $network->id,
                'exception' => $e->getMessage(),
            ]);

            $network->update([
                'broadcast_error' => "Failed to connect to proxy: {$e->getMessage()}",
            ]);

            return false;
        }
    }

    /**
     * Stop broadcasting for a network via the proxy.
     */
    public function stop(Network $network): bool
    {
        // First try to stop via proxy
        try {
            $response = $this->proxyService->proxyRequest(
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
        ]);

        // Clean up via proxy (removes files)
        try {
            $this->proxyService->proxyRequest('DELETE', "/broadcast/{$network->uuid}");
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
            $response = $this->proxyService->proxyRequest(
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
    protected function getStreamUrl(Network $network, NetworkProgramme $programme, int $seekSeconds = 0): ?string
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
        $request = request();
        $request->merge(['static' => 'true']); // static stream for HLS

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

        $streamUrl = $service->getDirectStreamUrl($request, $itemId, 'ts');

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
            'hls_url' => $this->proxyService->getProxyHlsUrl($network),
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
}
