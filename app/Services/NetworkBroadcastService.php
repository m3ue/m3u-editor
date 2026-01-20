<?php

namespace App\Services;

use App\Models\MediaServerIntegration;
use App\Models\Network;
use App\Models\NetworkProgramme;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * NetworkBroadcastService - Manages continuous HLS broadcasting for Networks.
 *
 * This service handles:
 * 1. Starting FFmpeg processes to create HLS segments
 * 2. Monitoring running broadcasts
 * 3. Gracefully stopping broadcasts
 * 4. Building FFmpeg commands with correct seek positions
 */
class NetworkBroadcastService
{
    /**
     * Start broadcasting for a network.
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

        // Check if already broadcasting
        if ($this->isProcessRunning($network)) {
            Log::info('Broadcast already running', [
                'network_id' => $network->id,
                'pid' => $network->broadcast_pid,
            ]);

            return true;
        }

        // Create storage directory
        $hlsPath = $network->getHlsStoragePath();
        File::ensureDirectoryExists($hlsPath);

        // Determine programme and seek position. Prefer persisted broadcast reference when available.
        $programme = $network->getCurrentProgramme();
        if (! $programme && $network->broadcast_programme_id) {
            // Try to load the persisted programme if current one not found
            $programme = NetworkProgramme::find($network->broadcast_programme_id);
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

        // Build and start FFmpeg process
        $command = $this->buildFfmpegCommand($network, $programme, $seekPosition);
        if (! $command) {
            Log::error('Failed to build FFmpeg command', [
                'network_id' => $network->id,
            ]);

            $network->update([
                'broadcast_requested' => false,
                'broadcast_error' => 'Failed to build FFmpeg command.',
            ]);

            return false;
        }

        $result = $this->executeCommand($network, $command, $programme, $seekPosition);

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
     * Stop broadcasting for a network.
     */
    public function stop(Network $network): bool
    {
        $pid = $network->broadcast_pid;

        if (! $pid) {
            Log::info('No broadcast running to stop', [
                'network_id' => $network->id,
            ]);

            // Clear any persisted broadcast reference and remove HLS files if they exist.
            $network->update([
                'broadcast_started_at' => null,
                'broadcast_pid' => null,
                'broadcast_programme_id' => null,
                'broadcast_initial_offset_seconds' => null,
                'broadcast_requested' => false,
            ]);

            // Remove lingering playlist and segment files to prevent stale content from being served
            try {
                $hlsPath = $network->getHlsStoragePath();
                if (File::isDirectory($hlsPath)) {
                    foreach (File::glob("{$hlsPath}/*.m3u8") as $file) {
                        File::delete($file);
                    }
                    foreach (File::glob("{$hlsPath}/*.m3u8.tmp") as $file) {
                        File::delete($file);
                    }
                    foreach (File::glob("{$hlsPath}/*.ts") as $file) {
                        File::delete($file);
                    }

                    // Kill promoter loop if it was started (cross-platform)
                    $promotePidFile = "{$hlsPath}/promote_pid";
                    if (File::exists($promotePidFile)) {
                        try {
                            $promotePid = (int) File::get($promotePidFile);
                            if ($promotePid > 0 && @posix_kill($promotePid, 0)) {
                                posix_kill($promotePid, SIGKILL);
                            }
                        } catch (\Throwable $e) {
                            Log::warning('Failed to kill playlist promoter process', ['network_id' => $network->id, 'error' => $e->getMessage()]);
                        }

                        try {
                            File::delete($promotePidFile);
                        } catch (\Throwable $e) {
                            // ignore
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to remove HLS files while stopping broadcast', [
                    'network_id' => $network->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return true;
        }

        // Try graceful termination first (SIGTERM)
        if ($this->signalProcess($pid, SIGTERM)) {
            // Wait a moment for graceful shutdown
            usleep(500000); // 500ms

            // Check if process is still running
            if (! $this->isProcessRunning($network)) {
                Log::info("🔴 BROADCAST STOPPED: {$network->name} (Network ID: {$network->id}, PID: {$pid})");

                $network->update([
                    'broadcast_started_at' => null,
                    'broadcast_pid' => null,
                    'broadcast_programme_id' => null,
                    'broadcast_initial_offset_seconds' => null,
                    'broadcast_requested' => false,
                ]);

                // Remove any HLS files and promoter loop
                $this->cleanupHlsForNetwork($network);

                // Emit a structured metric/log for broadcast stop
                Log::info('HLS_METRIC: broadcast_stopped', [
                    'network_id' => $network->id,
                    'uuid' => $network->uuid,
                    'old_pid' => $pid,
                ]);

                return true;
            }

            // Force kill if still running
            $this->signalProcess($pid, SIGKILL);
        }

        Log::info("🔴 BROADCAST FORCE-STOPPED: {$network->name} (Network ID: {$network->id}, PID: {$pid})");

        $network->update([
            'broadcast_started_at' => null,
            'broadcast_pid' => null,
            'broadcast_requested' => false,
        ]);

        // Ensure HLS files and promoter loop are removed on force-stop as well
        $this->cleanupHlsForNetwork($network);

        return true;
    }

    /**
     * Check if a network's broadcast process is running.
     * Uses posix_kill with signal 0 for cross-platform compatibility (macOS/Linux).
     */
    public function isProcessRunning(Network $network): bool
    {
        $pid = $network->broadcast_pid;

        if (! $pid) {
            return false;
        }

        // Use posix_kill with signal 0 to check if process exists (cross-platform)
        // Returns true if process exists, false if it doesn't
        // Signal 0 doesn't actually send a signal, just checks existence
        return @posix_kill($pid, 0);
    }

    /**
     * Build the FFmpeg command for a programme.
     *
     * @return array|null Command array or null on failure
     */
    public function buildFfmpegCommand(Network $network, NetworkProgramme $programme, int $seekPosition = 0): ?array
    {
        $hlsPath = $network->getHlsStoragePath();
        // Use provided seek position (may come from persisted reference) or fall back to current seek
        $remainingDuration = $network->getCurrentRemainingDuration();

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

        // Get stream URL with seek position built-in (media server handles seeking)
        $streamUrl = $this->getStreamUrl($network, $programme, $seekPosition);
        if (! $streamUrl) {
            return null;
        }

        // Base FFmpeg command
        $command = ['ffmpeg', '-y'];

        // SEEK FIRST: Use FFmpeg's -ss for input-level seeking BEFORE -i
        // This is the most reliable way to start at the correct position.
        // Placing -ss before -i makes FFmpeg seek at the demuxer level (fast, accurate for most formats)
        if ($seekPosition > 0) {
            $command[] = '-ss';
            $command[] = (string) $seekPosition;

            Log::info("📍 FFmpeg INPUT SEEK: Starting at {$seekPosition} seconds", [
                'network_id' => $network->id,
                'seek_seconds' => $seekPosition,
                'seek_formatted' => gmdate('H:i:s', $seekPosition),
            ]);
        }

        // Real-time pacing: -re flag makes FFmpeg read input at native framerate
        // This prevents FFmpeg from processing content at 70x+ speed when stream-copying
        // Critical for live broadcasting to maintain real-time playback
        $command[] = '-re';

        // Input URL with reconnect options for reliability
        $command = array_merge($command, [
            '-reconnect', '1',
            '-reconnect_streamed', '1',
            '-reconnect_delay_max', '10',
            '-i', $streamUrl,
        ]);

        // Duration limit (time remaining in current programme)
        // This ensures we stop at the scheduled end time and transition to next programme
        if ($remainingDuration > 0) {
            $command[] = '-t';
            $command[] = (string) $remainingDuration;
        }

        // Map only video and audio streams (exclude subtitles which can cause HLS issues)
        $command = array_merge($command, [
            '-map', '0:v:0',  // First video stream
            '-map', '0:a:0',  // First audio stream
        ]);

        // Output options - check if we should transcode or copy
        if ($network->transcode_on_server) {
            // Media server is transcoding - we just copy the streams
            $command[] = '-c:v';
            $command[] = 'copy';
            $command[] = '-c:a';
            $command[] = 'copy';
        } else {
            // We need to transcode
            $command = array_merge($command, $this->getTranscodeOptions($network));
        }

        // HLS output options
        // hls_list_size: Number of segments to keep in playlist (20 = ~2 min buffer at 6s segments)
        // delete_segments: Remove old segments to save disk space
        // append_list: Continue segment numbering across restarts
        // program_date_time: Add timestamps for DVR-like seeking
        $command = array_merge($command, [
            '-f', 'hls',
            '-hls_time', (string) ($network->segment_duration ?? 6),
            '-hls_list_size', (string) ($network->hls_list_size ?? 20),
            '-hls_flags', 'delete_segments+append_list+program_date_time',
            '-hls_segment_filename', "{$hlsPath}/live%06d.ts",
            "{$hlsPath}/live.m3u8",
        ]);

        return $command;
    }

    /**
     * Get transcode options for FFmpeg.
     *
     * @return array FFmpeg transcode arguments
     */
    protected function getTranscodeOptions(Network $network): array
    {
        $options = [];

        // Video encoding
        $options[] = '-c:v';
        $options[] = 'libx264';
        $options[] = '-preset';
        $options[] = 'veryfast';

        // Video bitrate
        if ($network->video_bitrate) {
            $options[] = '-b:v';
            $options[] = "{$network->video_bitrate}k";
        }

        // Video resolution
        if ($network->video_resolution) {
            $options[] = '-s';
            $options[] = $network->video_resolution;
        }

        // Audio encoding
        $options[] = '-c:a';
        $options[] = 'aac';
        $options[] = '-b:a';
        $options[] = ($network->audio_bitrate ?? 192).'k';

        return $options;
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

        // Build the stream URL with media server seeking
        $url = "{$integration->base_url}/Videos/{$itemId}/stream.ts";
        $params = [
            'static' => 'true',
            'api_key' => $integration->api_key,
        ];

        // Use media server's native seeking if we need to seek
        if ($seekSeconds > 0) {
            // Jellyfin/Emby use ticks (100-nanosecond intervals)
            $params['StartTimeTicks'] = $seekSeconds * 10_000_000;

            Log::debug('📍 Media server seek applied', [
                'network_id' => $network->id,
                'item_id' => $itemId,
                'seek_seconds' => $seekSeconds,
                'seek_ticks' => $params['StartTimeTicks'],
            ]);
        }

        return $url.'?'.http_build_query($params);
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
     * Execute the FFmpeg command as a background process.
     */
    protected function executeCommand(Network $network, array $command, ?NetworkProgramme $programme = null, int $initialOffsetSeconds = 0): bool
    {
        $commandString = implode(' ', array_map('escapeshellarg', $command));

        Log::info('Starting broadcast FFmpeg process', [
            'network_id' => $network->id,
            'command' => $commandString,
        ]);

        // Create output log file
        $logFile = $network->getHlsStoragePath().'/ffmpeg.log';

        // Set PATH explicitly to include common FFmpeg installation locations
        // This ensures ffmpeg is found in both local (Homebrew) and Docker environments
        $pathPrefix = 'PATH=/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin:/opt/homebrew/sbin:$PATH';
        $fullCommand = "{$pathPrefix} {$commandString} >> ".escapeshellarg($logFile).' 2>&1';

        // Use SymfonyProcess to start FFmpeg in background (like Channel model uses for ffprobe)
        $process = SymfonyProcess::fromShellCommandline($fullCommand);
        $process->setTimeout(null); // No timeout for long-running broadcast
        $process->start();

        // Get the PID
        $pid = $process->getPid();

        if (! $pid) {
            Log::error('Failed to start FFmpeg process - no PID', [
                'network_id' => $network->id,
            ]);

            return false;
        }

        // Wait a moment and verify process is still running
        usleep(500000); // 500ms

        if (! $process->isRunning()) {
            $logContent = @file_get_contents($logFile);

            Log::error('FFmpeg process exited immediately', [
                'network_id' => $network->id,
                'pid' => $pid,
                'log' => $logContent,
            ]);

            // Store error message for user feedback
            $network->update([
                'broadcast_error' => $this->parseFfmpegError($logContent),
            ]);

            return false;
        }

        // Update network with broadcast info and persist broadcast reference
        $update = [
            'broadcast_started_at' => Carbon::now(),
            'broadcast_pid' => $pid,
            'broadcast_programme_id' => $programme?->id,
            'broadcast_initial_offset_seconds' => $initialOffsetSeconds,
            'broadcast_requested' => true,
        ];

        $network->update($update);

        Log::info("🟢 BROADCAST STARTED: {$network->name} (Network ID: {$network->id}, PID: {$pid})", $update);

        // Emit a structured metric/log for broadcasting start
        Log::info('HLS_METRIC: broadcast_started', [
            'network_id' => $network->id,
            'uuid' => $network->uuid,
            'pid' => $pid,
        ]);

        // Start a small promoter loop to atomically promote temporary playlists
        try {
            $hlsPath = $network->getHlsStoragePath();
            $artisanPath = base_path('artisan');
            $phpBinary = PHP_BINARY; // Use the same PHP binary running this code

            $promotePidCmd = "nohup sh -c 'while sleep 1; do {$phpBinary} ".escapeshellarg($artisanPath)." network:promote-tmp-playlist {$network->uuid} >/dev/null 2>&1; done' >/dev/null 2>&1 & echo $!";
            $output = [];
            $rc = 0;
            exec($promotePidCmd, $output, $rc);
            if ($rc === 0 && ! empty($output[0])) {
                $promotePid = (int) $output[0];
                // persist promote PID in a file inside HLS path so we can kill it on stop
                File::ensureDirectoryExists($hlsPath);
                File::put("{$hlsPath}/promote_pid", (string) $promotePid);
                Log::info('Started playlist promoter loop', ['network_id' => $network->id, 'promote_pid' => $promotePid]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to start playlist promoter loop', ['network_id' => $network->id, 'error' => $e->getMessage()]);
        }

        return true;
    }

    /**
     * Send a signal to a process.
     * Cross-platform compatible (macOS/Linux).
     */
    protected function signalProcess(int $pid, int $signal): bool
    {
        // posix_kill returns false if process doesn't exist, no need for separate check
        return @posix_kill($pid, $signal);
    }

    /**
     * Remove HLS files and kill promoter loop for a network.
     * Called when stopping broadcast or cleaning up after crashes.
     */
    protected function cleanupHlsForNetwork(Network $network): void
    {
        $hlsPath = $network->getHlsStoragePath();

        if (! File::isDirectory($hlsPath)) {
            return;
        }

        $deletedPlaylists = 0;
        $deletedSegments = 0;

        // Clean up playlists
        foreach (File::glob("{$hlsPath}/*.m3u8") as $file) {
            try {
                File::delete($file);
                $deletedPlaylists++;
            } catch (\Throwable $e) {
                Log::warning('Failed to delete m3u8', ['file' => $file, 'error' => $e->getMessage()]);
            }
        }

        foreach (File::glob("{$hlsPath}/*.m3u8.tmp") as $file) {
            try {
                File::delete($file);
                $deletedPlaylists++;
            } catch (\Throwable $e) {
                Log::warning('Failed to delete tmp m3u8', ['file' => $file, 'error' => $e->getMessage()]);
            }
        }

        // Clean up orphaned segments (from crashes where FFmpeg couldn't delete them)
        foreach (File::glob("{$hlsPath}/*.ts") as $file) {
            try {
                File::delete($file);
                $deletedSegments++;
            } catch (\Throwable $e) {
                Log::warning('Failed to delete segment', ['file' => $file, 'error' => $e->getMessage()]);
            }
        }

        if ($deletedPlaylists > 0 || $deletedSegments > 0) {
            Log::info('Cleaned up HLS files', [
                'network_id' => $network->id,
                'playlists' => $deletedPlaylists,
                'segments' => $deletedSegments,
            ]);
        }

        // Kill promoter loop process (cross-platform with posix_kill)
        $promotePidFile = "{$hlsPath}/promote_pid";
        if (File::exists($promotePidFile)) {
            try {
                $promotePid = (int) File::get($promotePidFile);
                if ($promotePid > 0 && @posix_kill($promotePid, 0)) {
                    posix_kill($promotePid, SIGKILL);
                    Log::debug('Killed promoter loop', ['network_id' => $network->id, 'pid' => $promotePid]);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to kill promoter on cleanup', ['network_id' => $network->id, 'error' => $e->getMessage()]);
            }

            try {
                File::delete($promotePidFile);
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * Promote a temporary playlist to the live playlist if it appears stable.
     * Returns true if promotion occurred.
     */
    public function promoteTmpPlaylistIfStable(Network $network, int $stableSeconds = 1): bool
    {
        $hlsPath = $network->getHlsStoragePath();
        $tmp = "{$hlsPath}/live.m3u8.tmp";
        $target = "{$hlsPath}/live.m3u8";

        if (! File::exists($tmp)) {
            return false;
        }

        try {
            $mtime = File::lastModified($tmp);
            $stableCutoff = time() - $stableSeconds;

            if ($mtime > $stableCutoff) {
                // Not yet stable
                return false;
            }

            // Copy atomically
            File::copy($tmp, $target);
            // Ensure target has same permissions
            @chmod($target, 0644);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Failed to promote tmp playlist', ['network_id' => $network->id, 'error' => $e->getMessage()]);

            return false;
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
            'hls_url' => $network->hls_url,
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

            // Check for HLS files
            $hlsPath = $network->getHlsStoragePath();
            if (File::exists("{$hlsPath}/live.m3u8")) {
                $status['playlist_exists'] = true;
                $status['segment_count'] = count(File::glob("{$hlsPath}/*.ts"));
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
        if (! $this->isProcessRunning($network) && $network->broadcast_pid !== null) {
            return $network->getCurrentProgramme() !== null;
        }

        // Process never started - needs start if there's content
        if ($network->broadcast_pid === null) {
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
        // Stop any existing process
        if ($network->broadcast_pid !== null) {
            $this->stop($network);
            $network->refresh();
        }

        // Start fresh
        return $this->start($network);
    }

    /**
     * Run a single tick of the broadcast worker for a network.
     * This should be called periodically by the worker command.
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
            if ($network->broadcast_pid !== null) {
                $this->stop($network);
                $result['action'] = 'stopped';
            }

            return $result;
        }

        // Check if scheduled start time is enabled and we haven't reached it yet
        if ($network->broadcast_schedule_enabled && $network->broadcast_scheduled_start) {
            if (now()->lt($network->broadcast_scheduled_start)) {
                // Not time yet - ensure not running
                if ($network->broadcast_pid !== null) {
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

        // Check if process is still running
        $isRunning = $this->isProcessRunning($network);

        // Process died but should be running
        if (! $isRunning && $network->broadcast_pid !== null) {
            $crashedProgramme = $network->broadcast_programme_id
                ? NetworkProgramme::find($network->broadcast_programme_id)
                : null;

            Log::warning('🔴 BROADCAST CRASHED: Process died unexpectedly', [
                'network_id' => $network->id,
                'network_name' => $network->name,
                'old_pid' => $network->broadcast_pid,
                'crashed_programme_id' => $crashedProgramme?->id,
                'crashed_programme_title' => $crashedProgramme?->title,
                'uptime_seconds' => $network->broadcast_started_at
                    ? now()->diffInSeconds($network->broadcast_started_at)
                    : null,
            ]);

            $network->update([
                'broadcast_started_at' => null,
                'broadcast_pid' => null,
                'broadcast_error' => 'Broadcast crashed unexpectedly. Will auto-restart if programme is still active.',
            ]);

            // Clean up any orphaned HLS files from the crash
            // When FFmpeg crashes, it can't clean up its own segments
            try {
                $this->cleanupHlsForNetwork($network);
            } catch (\Throwable $e) {
                Log::warning('Failed to cleanup orphaned HLS files after crash', [
                    'network_id' => $network->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Emit a metric/log entry for the crash
            Log::warning('HLS_METRIC: broadcast_crashed', [
                'network_id' => $network->id,
                'uuid' => $network->uuid,
                'old_pid' => $network->broadcast_pid,
                'uptime_seconds' => $network->broadcast_started_at
                    ? now()->diffInSeconds($network->broadcast_started_at)
                    : null,
            ]);
        }

        // Get current programme
        $programme = $network->getCurrentProgramme();

        // No current programme - stop if running
        if (! $programme) {
            if ($network->broadcast_pid !== null) {
                $this->stop($network);
                $result['action'] = 'stopped_no_content';
            }

            return $result;
        }

        // Should be running but isn't - start it (only if user requested it)
        if ($network->broadcast_pid === null && $network->broadcast_requested) {
            // Calculate where we should resume from
            $seekPosition = $network->getPersistedBroadcastSeekForNow() ?? $network->getCurrentSeekPosition();

            Log::info('🔄 BROADCAST RECOVERY: Restarting broadcast', [
                'network_id' => $network->id,
                'network_name' => $network->name,
                'programme_id' => $programme->id,
                'programme_title' => $programme->title,
                'resume_position_seconds' => $seekPosition,
                'resume_position_formatted' => gmdate('H:i:s', $seekPosition),
                'is_crash_recovery' => isset($result['action']) && $result['action'] !== 'none',
            ]);

            $success = $this->start($network);
            $result['action'] = 'started';
            $result['success'] = $success;
            $result['programme'] = $programme->title;
            $result['resume_position_seconds'] = $seekPosition;

            return $result;
        }

        // broadcast_requested is false and not running - just report idle
        if ($network->broadcast_pid === null) {
            $result['action'] = 'idle';

            return $result;
        }

        // Running normally - check remaining time
        $remaining = $network->getCurrentRemainingDuration();
        $result['action'] = 'monitoring';
        $result['remaining_seconds'] = $remaining;

        // Note: Segment cleanup is handled automatically by FFmpeg's delete_segments flag
        // No manual cleanup needed during normal operation

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
     * Parse FFmpeg error log to extract user-friendly error message.
     */
    protected function parseFfmpegError(?string $logContent): string
    {
        if (empty($logContent)) {
            return 'FFmpeg process failed to start. Check server logs for details.';
        }

        // Check for common errors
        if (str_contains($logContent, 'No such file or directory')) {
            if (str_contains($logContent, 'ffmpeg')) {
                return 'FFmpeg is not installed or not in PATH. Please install FFmpeg on the server.';
            }

            return 'File not found: '.$logContent;
        }

        if (str_contains($logContent, 'Permission denied')) {
            return 'Permission denied. Check file/directory permissions.';
        }

        if (str_contains($logContent, 'Connection refused') || str_contains($logContent, 'Unable to open')) {
            return 'Cannot connect to media server. Check media server URL and API key.';
        }

        if (str_contains($logContent, 'Invalid data found')) {
            return 'Invalid stream format. Media server may not be transcoding correctly.';
        }

        if (str_contains($logContent, 'HTTP error')) {
            return 'HTTP error accessing media server stream.';
        }

        // Return first line of error if nothing specific matched
        $lines = explode("\n", trim($logContent));

        return $lines[0] ?? 'Unknown error occurred while starting broadcast.';
    }
}
