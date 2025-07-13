<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\SharedStream;
use App\Models\SharedStreamClient;
use App\Traits\TracksActiveStreams;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

/**
 * Shared Stream Service - Replicates xTeVe's streaming architecture
 * 
 * This service implements xTeVe-like functionality where multiple clients
 * can share the same upstream stream, reducing load on source servers.
 */
class SharedStreamService
{
    use TracksActiveStreams;

    const CACHE_PREFIX = 'shared_stream:';
    const BUFFER_PREFIX = 'stream_buffer:';
    const STREAM_PREFIX = 'shared_stream:'; // For compatibility with existing code
    const SEGMENT_EXPIRY = 300; // 5 minutes

    private int $clientTimeout;
    private array $activeProcesses = []; // Store active FFmpeg processes
    private bool $debugLogging;
    private int $maxRedirects;
    private int $redirectTtl;
    private int $logDataThreshold;
    private ?array $settings;

    public function __construct()
    {
        $this->clientTimeout = (int) config('proxy.shared_streaming.clients.timeout', 120);
        $this->debugLogging = config('app.shared_streaming.debug_logging', false);
        $this->maxRedirects = config('app.shared_streaming.max_redirects', 3);
        $this->redirectTtl = config('app.shared_streaming.redirect_ttl', 30);
        $this->logDataThreshold = config('app.shared_streaming.log_data_threshold', 51200);
        $this->settings = ProxyService::getStreamSettings();
    }

    /**
     * Get Redis connection instance
     */
    private function redis()
    {
        return app('redis');
    }

    /**
     * Get or create a shared stream for the given channel/episode
     * 
     * @param string $type 'channel' or 'episode'
     * @param int $modelId
     * @param string $streamUrl
     * @param string $title
     * @param string $format 'ts' or 'hls'
     * @param string $clientId Unique client identifier
     * @param array $options Additional streaming options
     * @return array Stream info with shared stream details
     */
    public function getOrCreateSharedStream(
        string $type,
        int $modelId,
        string $streamUrl,
        string $title,
        string $format,
        string $clientId,
        array $options = [],
        $model = null // Add model parameter for failover support
    ): array {
        // Try to create stream with primary channel first
        $result = $this->getOrCreateSharedStreamWithFailover(
            $type,
            $modelId,
            $streamUrl,
            $title,
            $format,
            $clientId,
            $options,
            $model
        );

        return $result;
    }

    /**
     * Internal method that handles failover logic
     */
    private function getOrCreateSharedStreamWithFailover(
        string $type,
        int $modelId,
        string $streamUrl,
        string $title,
        string $format,
        string $clientId,
        array $options = [],
        $model = null
    ): array {
        // Get primary channel and failover channels if available
        $primaryChannel = $model;
        $failoverChannels = collect();
        $exhaustedPlaylistIds = []; // Keep track of exhausted playlists for this request

        if ($type === 'channel' && $primaryChannel && method_exists($primaryChannel, 'failoverChannels')) {
            $failoverChannels = $primaryChannel->failoverChannels;
            Log::channel('ffmpeg')->debug("SharedStream: Found " . $failoverChannels->count() . " failover channels for primary channel {$primaryChannel->id}");
        }

        // --- Primary Channel Attempt ---
        $streamKey = $this->getStreamKey($type, $modelId, $streamUrl);
        $streamInfo = $this->getStreamInfo($streamKey);
        $primaryAttempted = false;

        if (!$streamInfo || !$this->isStreamActive($streamKey)) {
            $primaryAttempted = true;
            if ($primaryChannel) {
                $playlist = $primaryChannel->getEffectivePlaylist();
                if ($playlist) {
                    $activeStreams = $this->incrementActiveStreams($playlist->id);
                    if ($this->wouldExceedStreamLimit($playlist->id, $playlist->available_streams, $activeStreams)) {
                        $this->decrementActiveStreams($playlist->id);
                        Log::channel('ffmpeg')->debug("SharedStream: Max streams reached for primary channel's playlist {$playlist->name} ({$playlist->id}). Skipping primary channel {$title} ({$modelId}).");
                        if (!in_array($playlist->id, $exhaustedPlaylistIds)) {
                            $exhaustedPlaylistIds[] = $playlist->id;
                        }
                        // No exception here, just means primary cannot be used due to limit. Will proceed to failovers.
                    } else {
                        // Playlist has capacity, attempt to create stream
                        try {
                            $streamInfo = $this->createSharedStreamInternal(
                                $streamKey,
                                $type,
                                $modelId,
                                $streamUrl,
                                $title,
                                $format,
                                $options
                            );
                            $this->registerClient($streamKey, $clientId, $options);

                            $streamInfo['primary_channel_id'] = $modelId;
                            $streamInfo['active_channel_id'] = $modelId;
                            $streamInfo['failover_attempts'] = 0;
                            Log::channel('ffmpeg')->debug("SharedStream: Successfully created primary stream for channel {$modelId}");
                            return $streamInfo;
                        } catch (\Exception $e) {
                            $this->decrementActiveStreams($playlist->id); // Decrement if createSharedStreamInternal failed before starting process
                            Log::channel('ffmpeg')->error("SharedStream: Primary channel {$modelId} failed to start: " . $e->getMessage());
                            // Add to exhausted if failure was due to something that implies playlist issue (though this is generic here)
                            // For now, we assume other errors mean we can try failovers on other playlists.
                        }
                    }
                } else {
                    Log::channel('ffmpeg')->warning("SharedStream: Primary channel {$modelId} has no effective playlist. Cannot check stream limits.");
                    // Proceed to attempt stream creation without limit check if no playlist
                    try {
                        $streamInfo = $this->createSharedStreamInternal(
                            $streamKey,
                            $type,
                            $modelId,
                            $streamUrl,
                            $title,
                            $format,
                            $options
                        );
                        $this->registerClient($streamKey, $clientId, $options);

                        $streamInfo['primary_channel_id'] = $modelId;
                        $streamInfo['active_channel_id'] = $modelId;
                        $streamInfo['failover_attempts'] = 0;
                        Log::channel('ffmpeg')->debug("SharedStream: Successfully created primary stream for channel {$modelId} (no playlist for limit check).");
                        return $streamInfo;
                    } catch (\Exception $e) {
                        Log::channel('ffmpeg')->error("SharedStream: Primary channel {$modelId} (no playlist) failed to start: " . $e->getMessage());
                    }
                }
            } else {
                // This case ($primaryChannel is null) should ideally not happen if $model is passed.
                // If it does, attempt to create stream without playlist checks.
                try {
                    $streamInfo = $this->createSharedStreamInternal(
                        $streamKey,
                        $type,
                        $modelId,
                        $streamUrl,
                        $title,
                        $format,
                        $options
                    );
                    $this->registerClient($streamKey, $clientId, $options);

                    $streamInfo['primary_channel_id'] = $modelId;
                    $streamInfo['active_channel_id'] = $modelId;
                    $streamInfo['failover_attempts'] = 0;
                    Log::channel('ffmpeg')->debug("SharedStream: Successfully created primary stream for channel {$modelId} (no primary channel model for limit check).");
                    return $streamInfo;
                } catch (\Exception $e) {
                    Log::channel('ffmpeg')->error("SharedStream: Primary channel {$modelId} (no primary model) failed to start: " . $e->getMessage());
                }
            }
        } else { // Stream already exists and is active, or starting
            // Check if existing stream process is actually running
            $pid = $streamInfo['pid'] ?? null;
            $processRunning = $pid ? $this->isProcessRunning($pid) : false;

            if (!$processRunning && $streamInfo['status'] !== 'starting') {
                // Stream exists but process is dead, attempt failover restart
                Log::channel('ffmpeg')->debug("SharedStream: Client {$clientId} found dead stream {$streamKey}, attempting restart with failover");
                // This will internally handle playlist limits for the restart attempt.
                return $this->restartStreamWithFailover(
                    $streamKey,
                    $type,
                    $modelId,
                    $streamUrl,
                    $title,
                    $format,
                    $clientId,
                    $options,
                    $primaryChannel,
                    $failoverChannels,
                    $exhaustedPlaylistIds
                );
            } else {
                // Join existing active stream
                Log::channel('ffmpeg')->debug("SharedStream: Client {$clientId} joining existing active stream {$streamKey}");
                $this->incrementClientCount($streamKey);
                $this->registerClient($streamKey, $clientId, $options);
                return $streamInfo;
            }
        }

        // --- Failover Channel Attempts (if primary did not succeed and was attempted) ---
        if ($primaryAttempted) {
            Log::channel('ffmpeg')->debug("SharedStream: Primary channel {$modelId} did not succeed or was skipped due to limits. Exhausted playlists: [" . implode(',', $exhaustedPlaylistIds) . "]. Proceeding to failovers.");
            foreach ($failoverChannels as $index => $failoverChannel) {
                $failoverPlaylist = $failoverChannel->getEffectivePlaylist();

                if ($failoverPlaylist && in_array($failoverPlaylist->id, $exhaustedPlaylistIds)) {
                    Log::channel('ffmpeg')->debug("SharedStream: Skipping failover channel {$failoverChannel->id} as its playlist {$failoverPlaylist->name} ({$failoverPlaylist->id}) is in exhausted list.");
                    continue;
                }

                if ($failoverPlaylist) {
                    $activeStreams = $this->incrementActiveStreams($failoverPlaylist->id);
                    if ($this->wouldExceedStreamLimit($failoverPlaylist->id, $failoverPlaylist->available_streams, $activeStreams)) {
                        $this->decrementActiveStreams($failoverPlaylist->id);
                        Log::channel('ffmpeg')->debug("SharedStream: Max streams reached for failover channel's playlist {$failoverPlaylist->name} ({$failoverPlaylist->id}). Skipping failover channel {$failoverChannel->id}.");
                        if (!in_array($failoverPlaylist->id, $exhaustedPlaylistIds)) {
                            $exhaustedPlaylistIds[] = $failoverPlaylist->id;
                        }
                        continue;
                    }
                } else {
                    Log::channel('ffmpeg')->warning("SharedStream: Failover channel {$failoverChannel->id} has no effective playlist. Cannot check stream limits.");
                    // If no playlist, we can't check limits, so we proceed with the attempt.
                }

                try {
                    $failoverUrl = $failoverChannel->url_custom ?? $failoverChannel->url;
                    $failoverTitle = $failoverChannel->title_custom ?? $failoverChannel->title;

                    if (!$failoverUrl) {
                        Log::channel('ffmpeg')->debug("SharedStream: Failover channel {$failoverChannel->id} has no URL, skipping");
                        if ($failoverPlaylist) $this->decrementActiveStreams($failoverPlaylist->id); // Decrement if skipped after increment
                        continue;
                    }

                    Log::channel('ffmpeg')->debug("SharedStream: Attempting failover to channel {$failoverChannel->id} ({$failoverTitle}) on playlist " . ($failoverPlaylist ? $failoverPlaylist->name : 'N/A'));
                    $failoverStreamKey = $this->getStreamKey($type, $failoverChannel->id, $failoverUrl);

                    $streamInfo = $this->createSharedStreamInternal(
                        $failoverStreamKey,
                        $type,
                        $failoverChannel->id,
                        $failoverUrl,
                        $failoverTitle,
                        $format,
                        $options
                    );
                    $this->registerClient($failoverStreamKey, $clientId, $options);

                    $streamInfo['primary_channel_id'] = $modelId; // Original modelId
                    $streamInfo['active_channel_id'] = $failoverChannel->id;
                    $streamInfo['failover_attempts'] = $index + 1;
                    $streamInfo['is_failover'] = true;
                    $streamInfo['stream_key'] = $failoverStreamKey;

                    Log::channel('ffmpeg')->debug("SharedStream: Successfully failed over to channel {$failoverChannel->id} after " . ($index + 1) . " attempts.");
                    return $streamInfo;
                } catch (\Exception $failoverError) {
                    if ($failoverPlaylist) $this->decrementActiveStreams($failoverPlaylist->id); // Decrement if create failed
                    Log::channel('ffmpeg')->error("SharedStream: Failover channel {$failoverChannel->id} also failed: " . $failoverError->getMessage());
                    // Potentially add $failoverPlaylist->id to $exhaustedPlaylistIds if error indicates playlist capacity, though this is generic.
                    continue;
                }
            }
            // All failover attempts failed or were skipped
            Log::channel('ffmpeg')->error("SharedStream: All failover attempts failed for primary channel {$modelId}");
            throw new \Exception("Primary channel and all failover channels failed to start or were skipped due to playlist limits.");
        }

        // This point should only be reached if the stream was already active and client is joining.
        // Or if primary was attempted, succeeded, and returned. Or all failovers failed.
        // If $streamInfo is null here and we haven't thrown, it's an issue.
        if (!$streamInfo) {
            Log::channel('ffmpeg')->error("SharedStream: Reached end of getOrCreateSharedStreamWithFailover without valid streamInfo for model {$modelId}. This should not happen.");
            throw new \Exception("Failed to get or create a stream for model {$modelId}.");
        }

        // If primary was not attempted (e.g. stream already running), just return existing streamInfo
        if (!$primaryAttempted) {
            Log::channel('ffmpeg')->debug("SharedStream: Client {$clientId} joining existing active stream {$streamKey} (no new attempt needed).");
            $this->incrementClientCount($streamKey);
            $this->registerClient($streamKey, $clientId, $options);
            return $streamInfo;
        }

        // Should have returned or thrown by now if primary was attempted.
        // This is a fallback, though theoretically unreachable if logic above is correct.
        Log::channel('ffmpeg')->error("SharedStream: Unhandled case in getOrCreateSharedStreamWithFailover for model {$modelId}.");
        throw new \Exception("Unhandled stream creation state for model {$modelId}.");
    }

    /**
     * Restart a stream with failover support
     */
    private function restartStreamWithFailover(
        string $streamKey,
        string $type,
        int $modelId,
        string $streamUrl,
        string $title,
        string $format,
        string|null $clientId,
        array $options,
        $primaryChannel,
        $failoverChannels,
        array &$exhaustedPlaylistIds // Pass by reference to use and update
    ): array {
        $streamInfo = $this->getStreamInfo($streamKey); // Get current stream info for the dead stream

        // --- Attempt to restart the original stream (if its playlist is not exhausted) ---
        if ($primaryChannel) {
            $playlist = $primaryChannel->getEffectivePlaylist();
            if ($playlist && in_array($playlist->id, $exhaustedPlaylistIds)) {
                Log::channel('ffmpeg')->debug("SharedStream: Playlist {$playlist->name} for original stream {$streamKey} is exhausted. Skipping restart attempt for this stream.");
            } elseif ($playlist) {
                $activeStreams = $this->incrementActiveStreams($playlist->id);
                if ($this->wouldExceedStreamLimit($playlist->id, $playlist->available_streams, $activeStreams)) {
                    $this->decrementActiveStreams($playlist->id);
                    Log::channel('ffmpeg')->debug("SharedStream: Max streams reached for playlist {$playlist->name} ({$playlist->id}) during restart attempt. Skipping original stream {$streamKey}.");
                    if (!in_array($playlist->id, $exhaustedPlaylistIds)) {
                        $exhaustedPlaylistIds[] = $playlist->id;
                    }
                } else {
                    try {
                        // Mark as 'starting' before attempting restart
                        if ($streamInfo) { // streamInfo might be null if getStreamInfo failed
                            $streamInfo['status'] = 'starting';
                            $streamInfo['restart_attempt'] = time();
                            unset($streamInfo['error_message']);
                            unset($streamInfo['ffmpeg_stderr']);
                            $this->setStreamInfo($streamKey, $streamInfo);
                        }
                        Log::channel('ffmpeg')->debug("SharedStream: Stream {$streamKey}: Marked as 'starting' before attempting restart.");

                        if ($streamInfo && $streamInfo['format'] === 'hls') {
                            $this->startHLSStream($streamKey, $streamInfo);
                        } elseif ($streamInfo) {
                            $this->startDirectStream($streamKey, $streamInfo);
                        }

                        SharedStream::where('stream_id', $streamKey)->update([
                            'status' => 'starting',
                            'process_id' => $this->getProcessPid($streamKey),
                            'client_count' => 1,
                            'started_at' => now(),
                            'error_message' => null,
                            'last_client_activity' => now()
                        ]);
                        if ($clientId) {
                            Log::channel('ffmpeg')->debug("SharedStream: Successfully restarted original stream {$streamKey} for client {$clientId}");
                            $this->registerClient($streamKey, $clientId, $options);
                        }
                        return $streamInfo ?: $this->getStreamInfo($streamKey); // Return updated streamInfo
                    } catch (\Exception $e) {
                        $this->decrementActiveStreams($playlist->id);
                        Log::channel('ffmpeg')->error("SharedStream: Failed to restart original stream {$streamKey}: " . $e->getMessage());
                        // Original stream failed to restart, will proceed to failovers.
                    }
                }
            } else {
                Log::channel('ffmpeg')->warning("SharedStream: Primary channel for dead stream {$streamKey} has no effective playlist. Cannot check stream limits for restart.");
                // If no playlist, proceed with restart attempt without limit check
                try {
                    if ($streamInfo) {
                        $streamInfo['status'] = 'starting';
                        $streamInfo['restart_attempt'] = time();
                        unset($streamInfo['error_message']);
                        unset($streamInfo['ffmpeg_stderr']);
                        $this->setStreamInfo($streamKey, $streamInfo);
                    }
                    Log::channel('ffmpeg')->debug("SharedStream: Stream {$streamKey} (no playlist): Marked as 'starting' before restart.");
                    if ($streamInfo && $streamInfo['format'] === 'hls') {
                        $this->startHLSStream($streamKey, $streamInfo);
                    } elseif ($streamInfo) {
                        $this->startDirectStream($streamKey, $streamInfo);
                    }
                    SharedStream::where('stream_id', $streamKey)->update(['status' => 'starting', 'process_id' => $this->getProcessPid($streamKey), 'client_count' => 1, 'started_at' => now(), 'error_message' => null, 'last_client_activity' => now()]);
                    Log::channel('ffmpeg')->debug("SharedStream: Successfully restarted original stream {$streamKey} (no playlist) for client {$clientId}");
                    if ($clientId) {
                        $this->registerClient($streamKey, $clientId, $options);
                    }
                    return $streamInfo ?: $this->getStreamInfo($streamKey);
                } catch (\Exception $e) {
                    Log::channel('ffmpeg')->error("SharedStream: Failed to restart original stream {$streamKey} (no playlist): " . $e->getMessage());
                }
            }
        }

        // --- Try Failover Channels if Original Restart Failed or Skipped ---
        Log::channel('ffmpeg')->debug("SharedStream: Original stream {$streamKey} restart failed or skipped. Proceeding to failover channels for restart.");
        foreach ($failoverChannels as $index => $failoverChannel) {
            $failoverPlaylist = $failoverChannel->getEffectivePlaylist();

            if ($failoverPlaylist && in_array($failoverPlaylist->id, $exhaustedPlaylistIds)) {
                Log::channel('ffmpeg')->debug("SharedStream: Skipping failover channel {$failoverChannel->id} for restart as its playlist {$failoverPlaylist->name} is exhausted.");
                continue;
            }

            if ($failoverPlaylist) {
                $activeStreams = $this->incrementActiveStreams($failoverPlaylist->id);
                if ($this->wouldExceedStreamLimit($failoverPlaylist->id, $failoverPlaylist->available_streams, $activeStreams)) {
                    $this->decrementActiveStreams($failoverPlaylist->id);
                    Log::channel('ffmpeg')->debug("SharedStream: Max streams for playlist {$failoverPlaylist->name} on failover restart. Skipping failover channel {$failoverChannel->id}.");
                    if (!in_array($failoverPlaylist->id, $exhaustedPlaylistIds)) {
                        $exhaustedPlaylistIds[] = $failoverPlaylist->id;
                    }
                    continue;
                }
            } else {
                Log::channel('ffmpeg')->warning("SharedStream: Failover channel {$failoverChannel->id} for restart has no effective playlist. Cannot check stream limits.");
            }

            try {
                $failoverUrl = $failoverChannel->url_custom ?? $failoverChannel->url;
                $failoverTitle = $failoverChannel->title_custom ?? $failoverChannel->title;

                if (!$failoverUrl) {
                    Log::channel('ffmpeg')->debug("SharedStream: Failover channel {$failoverChannel->id} for restart has no URL, skipping");
                    if ($failoverPlaylist) $this->decrementActiveStreams($failoverPlaylist->id);
                    continue;
                }

                Log::channel('ffmpeg')->debug("SharedStream: Attempting failover restart to channel {$failoverChannel->id} ({$failoverTitle}) on playlist " . ($failoverPlaylist ? $failoverPlaylist->name : 'N/A'));

                // Important: Clean up the old (dead) stream before creating a new one with failover
                $this->cleanupStream($streamKey, true); // True to remove files as well
                Log::channel('ffmpeg')->debug("SharedStream: Cleaned up dead stream {$streamKey} before failover restart.");

                $failoverStreamKey = $this->getStreamKey($type, $failoverChannel->id, $failoverUrl);
                $newStreamInfo = $this->createSharedStreamInternal(
                    $failoverStreamKey,
                    $type,
                    $failoverChannel->id,
                    $failoverUrl,
                    $failoverTitle,
                    $format,
                    $options
                );

                $newStreamInfo['primary_channel_id'] = $modelId; // original modelId
                $newStreamInfo['active_channel_id'] = $failoverChannel->id;
                $newStreamInfo['failover_attempts'] = $index + 1; // This is the first attempt for this new stream
                $newStreamInfo['is_failover'] = true;
                $newStreamInfo['stream_key'] = $failoverStreamKey;

                if ($clientId) {
                    $this->registerClient($failoverStreamKey, $clientId, $options);
                }
                Log::channel('ffmpeg')->debug("SharedStream: Successfully failed over (restarted) to channel {$failoverChannel->id}. New stream key: {$failoverStreamKey}");
                return $newStreamInfo;
            } catch (\Exception $failoverError) {
                if ($failoverPlaylist) $this->decrementActiveStreams($failoverPlaylist->id);
                Log::channel('ffmpeg')->error("SharedStream: Failover restart to channel {$failoverChannel->id} failed: " . $failoverError->getMessage());
                continue;
            }
        }

        // All attempts (original restart and failovers) failed
        Log::channel('ffmpeg')->error("SharedStream: All restart and failover attempts failed for original stream {$streamKey} (model {$modelId})");
        // Ensure the original dead stream is marked as error if it wasn't cleaned up
        $finalStreamInfo = $this->getStreamInfo($streamKey);
        if ($finalStreamInfo) {
            $finalStreamInfo['status'] = 'error';
            $finalStreamInfo['error_message'] = "Restart failed and all failover channels failed or were skipped.";
            $this->setStreamInfo($streamKey, $finalStreamInfo);
            SharedStream::where('stream_id', $streamKey)->update(['status' => 'error', 'error_message' => $finalStreamInfo['error_message']]);
        }
        throw new \Exception("Failed to restart stream and all failover channels failed or were skipped.");
    }

    /**
     * Create a new shared stream (internal method)
     */
    public function createSharedStreamInternal(
        string $streamKey,
        string $type,
        int $modelId,
        string $streamUrl,
        string $title,
        string $format,
        array $options
    ): array {
        $streamInfo = [
            'stream_key' => $streamKey,
            'type' => $type,
            'model_id' => $modelId,
            'stream_url' => $streamUrl,
            'title' => $title,
            'format' => $format,
            'status' => 'starting',
            'client_count' => 1,
            'created_at' => now()->timestamp,
            'last_client_activity' => now()->timestamp,
            'options' => $options
        ];
        // 1. Create or update database record for persistent tracking
        SharedStream::updateOrCreate(
            ['stream_id' => $streamKey],
            [
                'source_url' => $streamUrl,
                'format' => $format,
                'status' => 'starting',
                'client_count' => 1, // Set initial client count
                'last_client_activity' => now(),
                'stream_info' => $streamInfo, // Store as array, model will cast to JSON
                'started_at' => now(),
                'health_check_at' => null,
                'health_status' => 'unknown',
            ]
        );

        // 2. Store stream info
        $this->setStreamInfo($streamKey, $streamInfo);

        // Start the streaming process directly (temporary fix for queue issues)
        try {
            Log::channel('ffmpeg')->debug("Starting stream process inline for {$streamKey}");
            $this->startStreamingProcess($streamKey, $streamInfo);
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Failed to start stream process for {$streamKey}: " . $e->getMessage());
            throw $e;
        }

        Log::channel('ffmpeg')->debug("Created new shared stream {$streamKey} for {$type} {$title}"); // Changed from debug to info

        return $streamInfo;
    }

    /**
     * Start the actual streaming process (FFmpeg)
     */
    private function startStreamingProcess(string $streamKey, array $streamInfo): void
    {
        $format = $streamInfo['format'];
        $streamUrl = $streamInfo['stream_url'];
        $title = $streamInfo['title'];

        try {
            if ($format === 'hls') {
                $this->startHLSStream($streamKey, $streamInfo);
            } else {
                $this->startDirectStream($streamKey, $streamInfo);
            }

            // Don't set status to 'active' here - let startInitialBuffering do it when data is actually received
            // Just update the process ID in the database
            SharedStream::where('stream_id', $streamKey)->update([
                'process_id' => $this->getProcessPid($streamKey)
            ]);
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Failed to start streaming process for {$streamKey}: " . $e->getMessage());

            // Update stream status to error
            $streamInfo['status'] = 'error';
            $this->setStreamInfo($streamKey, $streamInfo);

            // Update database status
            SharedStream::where('stream_id', $streamKey)->update(['status' => 'error']);

            throw $e;
        }
    }

    /**
     * Start HLS streaming with segment buffering
     */
    private function startHLSStream(string $streamKey, array $streamInfo): void
    {
        $storagePath = $this->getStreamStoragePath($streamKey);
        $absoluteStorageDir = Storage::disk('app')->path($storagePath);
        Storage::disk('app')->makeDirectory($storagePath);

        $settings = ProxyService::getStreamSettings();
        $ffmpegPath = $settings['ffmpeg_path'] ?? 'jellyfin-ffmpeg';
        $userAgent = $streamInfo['options']['user_agent'] ?? $settings['ffmpeg_user_agent'] ?? 'VLC/3.0.21';

        // Build FFmpeg command for HLS output
        $cmd = $this->buildHLSCommand($ffmpegPath, $streamInfo, $absoluteStorageDir, $userAgent);

        // Use proc_open approach like HlsStreamService for consistency
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = proc_open($cmd, $descriptors, $pipes, $absoluteStorageDir);

        if (!is_resource($process)) {
            throw new \Exception("Failed to launch FFmpeg for HLS stream {$streamKey}");
        }

        // Close stdin and stdout (we don't need them for HLS)
        fclose($pipes[0]);
        fclose($pipes[1]);

        // Make stderr non-blocking for error logging
        stream_set_blocking($pipes[2], false);

        // Get the PID and store it
        $status = proc_get_status($process);
        $pid = $status['pid'];
        $this->setStreamProcess($streamKey, $pid);

        // Store process info in stream data
        $streamInfo['pid'] = $pid;
        $this->setStreamInfo($streamKey, $streamInfo);

        // Set up error logging from stderr
        $this->setupErrorLogging($streamKey, $pipes[2], $process);

        Log::channel('ffmpeg')->debug("Started HLS shared stream {$streamKey} with PID {$pid}");
        Log::channel('ffmpeg')->debug("HLS Command: {$cmd}");
    }

    /**
     * Start direct streaming with buffering (similar to MPTS)
     */
    private function startDirectStream(string $streamKey, array $streamInfo): void
    {
        $settings = ProxyService::getStreamSettings();
        $ffmpegPath = $settings['ffmpeg_path'] ?? 'jellyfin-ffmpeg';
        $userAgent = $streamInfo['options']['user_agent'] ?? $settings['ffmpeg_user_agent'] ?? 'VLC/3.0.21';

        // Build FFmpeg command for direct output
        $cmd = $this->buildDirectCommand($ffmpegPath, $streamInfo, $userAgent);

        Log::channel('ffmpeg')->debug("Starting shared stream process for {$streamKey}: {$cmd}");

        // Use proc_open for direct streaming
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            Log::channel('ffmpeg')->error("Failed to start process for {$streamKey}. Command: {$cmd}");
            throw new \Exception("Failed to start direct stream process for {$streamKey}");
        }

        // Close stdin (we don't write to FFmpeg)
        fclose($pipes[0]);

        // Get the PID and store it
        $status = proc_get_status($process);
        if (!$status || !isset($status['pid'])) {
            Log::channel('ffmpeg')->error("Failed to get process status for {$streamKey}");
            proc_close($process);
            throw new \Exception("Failed to get process PID for {$streamKey}");
        }

        $pid = $status['pid'];
        $this->setStreamProcess($streamKey, $pid);

        // Store process handles for direct access during streaming
        $this->activeProcesses[$streamKey] = [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
            'pid' => $pid
        ];

        // Requirement 4.b.a: Brief, non-blocking read from stderr for immediate errors
        stream_set_blocking($pipes[2], false); // Ensure stderr is non-blocking
        $initialError = fread($pipes[2], 4096); // Read up to 4KB
        if ($initialError !== false && !empty(trim($initialError))) {
            Log::channel('ffmpeg')->error("Stream {$streamKey} (PID {$pid}): Immediate FFmpeg stderr after proc_open: " . trim($initialError));
        }
        // Note: $pipes[1] (stdout) and $pipes[2] (stderr) will be set to non-blocking again
        // at the beginning of startContinuousBuffering if they weren't already.

        // Start continuous buffer management for direct streaming
        $this->startContinuousBuffering($streamKey, $pipes[1], $pipes[2], $process);

        // Store process info in stream data
        $streamInfo['pid'] = $pid;
        $streamInfo['status'] = 'active'; // Set status to active
        $this->setStreamInfo($streamKey, $streamInfo);

        Log::channel('ffmpeg')->debug("Started direct shared stream {$streamKey} with PID {$pid}");
        Log::channel('ffmpeg')->debug("Direct Command: {$cmd}");
    }

    /**
     * Build FFmpeg command for direct streaming (simplified for compatibility)
     * Uses proven working approach from StreamController
     */
    private function buildDirectCommand(string $ffmpegPath, array $streamInfo, string $userAgent): string
    {
        $settings = ProxyService::getStreamSettings();
        $streamUrl = $streamInfo['stream_url'];

        // Build command using proven working approach from StreamController
        $cmd = escapeshellcmd($ffmpegPath) . ' ';
        $cmd .= '-hide_banner -loglevel error '; // Added -hide_banner and -loglevel error globally

        // Better error handling and more robust connection options
        $cmd .= '-err_detect ignore_err -ignore_unknown ';
        $cmd .= '-fflags +nobuffer+igndts -flags low_delay ';
        $cmd .= '-analyzeduration 1M -probesize 1M -max_delay 500000 ';

        // HTTP options (simplified to match working approach)
        $cmd .= "-user_agent " . escapeshellarg($userAgent) . " -referer " . escapeshellarg("MyComputer") . " ";
        $cmd .= '-multiple_requests 1 -reconnect_on_network_error 1 ';
        $cmd .= '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ';
        $cmd .= '-reconnect_delay_max 5 ';
        $cmd .= '-noautorotate ';

        // Input
        $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';

        // Output options - use copy by default for better compatibility and performance
        $videoCodec = $settings['ffmpeg_codec_video'] ?? 'copy';
        $audioCodec = $settings['ffmpeg_codec_audio'] ?? 'copy';

        // Ensure codecs are strings, not arrays
        if (is_array($videoCodec)) {
            $videoCodec = 'copy';
        }
        if (is_array($audioCodec)) {
            $audioCodec = 'copy';
        }

        // Use copy by default for shared streaming to reduce CPU load
        if ($videoCodec === 'copy' || empty($videoCodec)) {
            $videoCodec = 'copy';
        }
        if ($audioCodec === 'copy' || empty($audioCodec)) {
            $audioCodec = 'copy';
        }

        // Output format with better streaming options
        $cmd .= "-c:v {$videoCodec} -c:a {$audioCodec} ";
        $cmd .= "-avoid_negative_ts disabled -copyts -start_at_zero ";
        $cmd .= "-f mpegts pipe:1";

        Log::channel('ffmpeg')->debug("SharedStream: Built optimized direct command for reliable streaming");

        return $cmd;
    }

    /**
     * Setup error logging for FFmpeg stderr
     */
    private function setupErrorLogging(string $streamKey, $stderr, $process): void
    {
        // Make stderr non-blocking to prevent hanging
        stream_set_blocking($stderr, false);

        // Simple error monitoring without complex shutdown functions
        // The main buffer manager will handle stderr monitoring
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Error logging setup completed");
    }

    /**
     * Get process PID for a stream
     */
    private function getProcessPid(string $streamKey): ?int
    {
        $sharedStream = SharedStream::where('stream_id', $streamKey)->first();
        return $sharedStream ? $sharedStream->process_id : null;
    }

    /**
     * Set process PID for a stream
     */
    private function setStreamProcess(string $streamKey, int $pid): void
    {
        // Store in database for persistence
        SharedStream::where('stream_id', $streamKey)->update(['process_id' => $pid]);

        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Stored process PID {$pid} in database");
    }

    /**
     * Start continuous buffering process (xTeVe-style optimized)
     */
    private function startContinuousBuffering(string $streamKey, $stdout, $stderr, $process): void
    {
        // Set streams to non-blocking mode immediately
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        // Start immediate buffering to prevent FFmpeg from hanging
        $this->startInitialBuffering($streamKey, $stdout, $stderr, $process);

        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Starting xTeVe-style buffer management");

        // Start buffer manager in a way that doesn't block the HTTP response
        // We'll use a simple approach: buffer a few more segments then return,
        // letting the stream continue via process cleanup
        $this->runShortInitialBuffering($streamKey, $stdout, $stderr, $process);
    }

    /**
     * Start initial buffering to prevent FFmpeg from hanging
     * Optimized for faster startup and better VLC compatibility
     */
    private function startInitialBuffering(string $streamKey, $stdout, $stderr, $process): void
    {
        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $segmentNumber = 0;

        // Optimized settings for faster startup
        $targetSegmentSize = 188 * 1000; // 188KB segments (xTeVe compatible)
        $readChunkSize = 32768; // 32KB reads for better performance
        $maxWait = 10; // Reduced from 15 to 10 seconds for faster startup
        $waitTime = 0;

        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Starting initial buffering. Waiting up to {$maxWait}s for FFmpeg data.");

        // Wait for FFmpeg to start producing data and build initial buffer
        $accumulatedData = '';
        $accumulatedSize = 0;
        $hasFirstData = false;
        $redis = $this->redis();

        while ($waitTime < $maxWait) {
            // Use stream_select to properly handle non-blocking I/O
            $read = [$stdout];
            $write = null;
            $except = null;

            // Wait up to 100ms for data to be available
            $result = stream_select($read, $write, $except, 0, 100000);

            if ($result > 0 && in_array($stdout, $read)) {
                // Data is available, try to read it
                $chunk = fread($stdout, $readChunkSize);
                if ($chunk !== false && strlen($chunk) > 0) {
                    if (!$hasFirstData) {
                        Log::channel('ffmpeg')->debug("Stream {$streamKey}: First data chunk received from FFmpeg (" . strlen($chunk) . " bytes).");
                        $hasFirstData = true;
                    }

                    $accumulatedData .= $chunk;
                    $accumulatedSize += strlen($chunk);
                    $this->updateStreamActivity($streamKey);

                    // Create segments more aggressively for faster startup
                    if (
                        $accumulatedSize >= $targetSegmentSize ||
                        ($accumulatedSize >= 64000 && $segmentNumber < 2)
                    ) { // Smaller initial segments for faster startup

                        // Use direct Redis calls for initial buffering (faster than pipeline for small operations)
                        $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                        $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
                        $redis->lpush("{$bufferKey}:segments", $segmentNumber);
                        $redis->ltrim("{$bufferKey}:segments", 0, 100);

                        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Initial buffer segment {$segmentNumber} buffered ({$accumulatedSize} bytes)");

                        // Requirement 1.a: Update status to 'active' after first segment
                        if (
                            $segmentNumber == 0 // This is the first segment successfully processed
                        ) {
                            $streamInfo = $this->getStreamInfo($streamKey);
                            if ($streamInfo) {
                                $streamInfo['status'] = 'active';
                                $streamInfo['first_data_at'] = time();
                                $this->setStreamInfo($streamKey, $streamInfo);

                                // Update database status to active
                                $updateResult = SharedStream::where('stream_id', $streamKey)->update([
                                    'status' => 'active'
                                ]);

                                Log::channel('ffmpeg')->debug("Stream {$streamKey}: Initial segment " . ($segmentNumber + 1) . " buffered. Marking stream as ACTIVE. DB update result: {$updateResult}");
                            } else {
                                Log::channel('ffmpeg')->warning("Stream {$streamKey}: Could not get stream info to update status to active");
                            }
                        }

                        $segmentNumber++;
                        $accumulatedData = '';
                        $accumulatedSize = 0;

                        // Build up fewer initial segments for faster startup
                        if ($segmentNumber >= 1) { // Reduced from 2 to 1 for even faster startup
                            break;
                        }
                    }
                }
            } else {
                // No immediate data available, check for errors
                $error = fread($stderr, 1024);
                if ($error !== false && strlen($error) > 0) {
                    Log::channel('ffmpeg')->error("Stream {$streamKey} FFmpeg error during initial buffering: {$error}");
                }

                // Increment wait time only when no data is available
                $waitTime += 0.1; // 100ms increment
            }
        }

        // Flush any remaining data from initial buffering
        if ($accumulatedSize > 0) {
            $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
            $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
            $redis->lpush("{$bufferKey}:segments", $segmentNumber);
            $redis->ltrim("{$bufferKey}:segments", 0, 100);

            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Final initial buffer segment {$segmentNumber} buffered ({$accumulatedSize} bytes)");
            $segmentNumber++;
        }

        if ($segmentNumber > 0) {
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Initial buffering completed successfully with {$segmentNumber} segments");

            // Ensure status is active if segments were buffered (it might have been set already by the first segment logic)
            $streamInfo = $this->getStreamInfo($streamKey);
            if ($streamInfo && $streamInfo['status'] !== 'active') {
                $streamInfo['status'] = 'active';
                if (!isset($streamInfo['first_data_at'])) {
                    $streamInfo['first_data_at'] = time();
                }
                $streamInfo['initial_segments'] = $segmentNumber;
                $this->setStreamInfo($streamKey, $streamInfo);

                SharedStream::where('stream_id', $streamKey)->update(['status' => 'active']);
                Log::channel('ffmpeg')->debug("Stream {$streamKey}: Confirmed status as 'active' after initial buffering.");
            }
        } else {
            // Requirement 1.c: No data was buffered ($segmentNumber is still 0)
            Log::channel('ffmpeg')->error("Stream {$streamKey}: FAILED to receive initial data from FFmpeg after {$maxWait}s.");

            $error_output = 'No specific error output from FFmpeg stderr.';
            // Attempt to read from stderr
            $stderr_content = '';
            while (($line = fgets($stderr)) !== false) {
                $stderr_content .= $line;
            }
            if (!empty(trim($stderr_content))) {
                $error_output = trim($stderr_content);
                Log::channel('ffmpeg')->error("Stream {$streamKey}: FFmpeg stderr output: {$error_output}");
            } else {
                Log::channel('ffmpeg')->debug("Stream {$streamKey}: No FFmpeg stderr output detected.");
            }

            $streamInfo = $this->getStreamInfo($streamKey);
            if ($streamInfo) {
                $errorMessage = 'FFmpeg failed to produce initial data after ' . $maxWait . 's.';
                $streamInfo['status'] = 'error';
                $streamInfo['error_message'] = $errorMessage;
                $streamInfo['ffmpeg_stderr'] = $error_output; // Store stderr output
                $this->setStreamInfo($streamKey, $streamInfo);

                SharedStream::where('stream_id', $streamKey)->update([
                    'status' => 'error',
                    'error_message' => $errorMessage,
                    'stream_info->ffmpeg_stderr' => $error_output // Store in DB as well
                ]);
                Log::channel('ffmpeg')->debug("Stream {$streamKey}: Status updated to 'error' due to no initial data. FFmpeg stderr logged.");
            }
        }
    }

    /**
     * Run short initial buffering to build up a small buffer without blocking HTTP response
     */
    private function runShortInitialBuffering(string $streamKey, $stdout, $stderr, $process): void
    {
        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $targetSegments = 5; // Buffer 5 segments then return
        $segmentNumber = 1; // Start from 1 since initial buffering already created segment 0
        $accumulatedData = '';
        $accumulatedSize = 0;
        $targetChunkSize = 188 * 1000; // 188KB chunks
        $redis = $this->redis();
        $maxWaitTime = 3; // Maximum 3 seconds for this initial buffering
        $startTime = time();

        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Starting short initial buffering for {$targetSegments} segments");

        while ($segmentNumber < $targetSegments && (time() - $startTime) < $maxWaitTime) {
            // Quick non-blocking read
            $chunk = fread($stdout, 32768);
            if ($chunk !== false && strlen($chunk) > 0) {
                $accumulatedData .= $chunk;
                $accumulatedSize += strlen($chunk);

                // Flush when we have enough data
                if ($accumulatedSize >= $targetChunkSize) {
                    $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                    $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
                    $redis->lpush("{$bufferKey}:segments", $segmentNumber);
                    $redis->ltrim("{$bufferKey}:segments", 0, 50);

                    // Only log every few segments to reduce noise
                    if ($segmentNumber <= 5 || $segmentNumber % 5 === 0) {
                        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Buffered initial segment {$segmentNumber}");
                    }

                    $segmentNumber++;
                    $accumulatedData = '';
                    $accumulatedSize = 0;

                    $this->updateStreamActivity($streamKey);
                }
            } else {
                // No data available, small sleep
                usleep(10000); // 10ms
            }

            // Check for errors
            $error = fread($stderr, 1024);
            if ($error !== false && strlen($error) > 0) {
                Log::channel('ffmpeg')->error("Stream {$streamKey}: {$error}");
            }
        }

        // Flush any remaining data
        if ($accumulatedSize > 0) {
            $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
            $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
            $redis->lpush("{$bufferKey}:segments", $segmentNumber);
            $segmentNumber++;
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Flushed remaining data as segment {$segmentNumber}");
        }

        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Short initial buffering completed with {$segmentNumber} total segments");

        // Initial buffering is complete. The process handles are stored for on-demand reading.
        // Clients will read additional data via getNextStreamSegments() which can read directly from FFmpeg.
    }

    /**
     * Build HLS command for shared HLS streaming
     */
    private function buildHLSCommand(string $ffmpegPath, array $streamInfo, string $storageDir, string $userAgent): string
    {
        // Get the stream URL
        $streamUrl = $streamInfo['stream_url'];
        $id = rtrim(base64_encode($streamInfo['model_id']), '=');
        $type = $streamInfo['type'];

        // Setup the stream URL
        $m3uPlaylist = "{$storageDir}/playlist.m3u8";
        $segment = "{$storageDir}/segment_%03d.ts";

        // Construct segmentBaseUrl based on proxy_url_override
        $proxyOverrideUrl = config('proxy.url_override');
        if (!empty($proxyOverrideUrl)) {
            $parsedUrl = parse_url($proxyOverrideUrl);
            $scheme = $parsedUrl['scheme'] ?? 'http';
            $host = $parsedUrl['host'];
            $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
            $base = "{$scheme}://{$host}{$port}";
            $path = "/api/shared/hls/{$type}/{$id}/";
            $segmentBaseUrl = $base . $path;
        } else {
            $segmentBaseUrl = url("/api/shared/hls/{$type}/{$id}") . '/';
        }

        $cmd = escapeshellcmd($ffmpegPath) . ' ';
        $cmd .= '-hide_banner -loglevel error ';
        $cmd .= '-user_agent ' . escapeshellarg($userAgent) . ' ';

        // Add robust input options similar to buildDirectCommand
        $cmd .= '-err_detect ignore_err -ignore_unknown ';
        $cmd .= '-fflags +nobuffer+igndts -flags low_delay '; // Consider if +nobuffer is always needed for HLS
        $cmd .= '-multiple_requests 1 -reconnect_on_network_error 1 ';
        $cmd .= '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ';
        $cmd .= '-reconnect_delay_max 5 ';
        $cmd .= '-noautorotate ';

        $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';
        $cmd .= '-c copy -f hls ';
        $cmd .= '-hls_time 4 -hls_list_size 10 ';
        $cmd .= '-hls_flags delete_segments ';
        $cmd .= '-hls_segment_filename ' . escapeshellarg($segment) . ' ';
        $cmd .= '-hls_base_url ' . escapeshellarg($segmentBaseUrl) . ' ';
        $cmd .= escapeshellarg($m3uPlaylist) . ' ';

        return $cmd;
    }

    /**
     * Generate stream key from type, model ID, and URL
     */
    public function getStreamKey(string $type, int $modelId, string $streamUrl): string
    {
        return self::CACHE_PREFIX . $type . ':' . $modelId . ':' . md5($streamUrl);
    }

    /**
     * Get stream info from SharedStream model (database) with Redis fallback
     */
    public function getStreamInfo(string $streamKey): ?array
    {
        try {
            // Get from database (primary and only source)
            $sharedStream = SharedStream::where('stream_id', $streamKey)->first();

            if (!$sharedStream) {
                Log::channel('ffmpeg')->debug("Stream {$streamKey}: No stream info found in database");
                return null;
            }

            if (!$sharedStream->stream_info) {
                Log::channel('ffmpeg')->warning("Stream {$streamKey}: Empty stream info data in database");
                return null;
            }

            return $sharedStream->stream_info;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Stream {$streamKey}: Error retrieving stream info: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Set stream info in SharedStream model (database only)
     */
    private function setStreamInfo(string $streamKey, array $streamInfo): void
    {
        try {
            // Update database record (primary and only storage)
            $sharedStream = SharedStream::where('stream_id', $streamKey)->first();

            if ($sharedStream) {
                // Update existing record
                $updateData = [
                    'stream_info' => $streamInfo,
                    'last_client_activity' => isset($streamInfo['last_client_activity']) ?
                        Carbon::createFromTimestamp($streamInfo['last_client_activity']) :
                        $sharedStream->last_client_activity,
                ];

                if (isset($streamInfo['pid'])) {
                    $updateData['process_id'] = $streamInfo['pid'];
                }

                if (isset($streamInfo['client_count'])) {
                    $updateData['client_count'] = $streamInfo['client_count'];
                }

                $sharedStream->update($updateData);

                // Log::channel('ffmpeg')->debug("Stream {$streamKey}: Successfully updated stream info in database");
            } else {
                Log::channel('ffmpeg')->warning("Stream {$streamKey}: No database record found to update stream info");
            }
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Stream {$streamKey}: Error storing stream info: " . $e->getMessage());
        }
    }

    /**
     * Check if stream is active
     */
    public function isStreamActive(string $streamKey, bool $checkProcess = true): bool
    {
        $streamInfo = $this->getStreamInfo($streamKey);
        if (!$streamInfo || !in_array($streamInfo['status'] ?? '', ['active', 'starting'])) {
            return false;
        }

        // If checkProcess is false, we only check the Redis status
        if (!$checkProcess) {
            return true;
        }

        // Check if the process is actually running (phantom stream detection)
        $pid = $streamInfo['pid'] ?? null;
        if ($pid && !$this->isProcessRunning($pid)) {
            Log::channel('ffmpeg')->warning("Phantom stream detected for {$streamKey} with PID {$pid} - process not running");

            // Mark the stream as errored instead of deleting it immediately
            $streamInfo['status'] = 'error';
            $streamInfo['error_message'] = 'Phantom process detected';
            $this->setStreamInfo($streamKey, $streamInfo);

            // The cleanup job will handle the final removal
            return false;
        }

        return true;
    }

    /**
     * Increment client count for a stream using database count
     */
    private function incrementClientCount(string $streamKey): void
    {
        $lock = Cache::lock('lock:stream_info_client_count:' . $streamKey, 10); // Lock for 10 seconds
        if ($lock->get()) {
            try {
                // Get actual client count from database
                $activeClientCount = SharedStreamClient::where('stream_id', $streamKey)
                    ->where('status', 'connected')
                    ->count();

                $streamInfo = $this->getStreamInfo($streamKey);
                if ($streamInfo) {
                    $streamInfo['client_count'] = $activeClientCount;
                    $streamInfo['last_client_activity'] = now()->timestamp;
                    // If a client is joining, it's definitely not clientless anymore
                    unset($streamInfo['clientless_since']);
                    $this->setStreamInfo($streamKey, $streamInfo);

                    SharedStream::where('stream_id', $streamKey)->update([
                        'status' => 'active', // Ensure status is active on client join
                        'client_count' => $activeClientCount,
                    ]);

                    Log::channel('ffmpeg')->debug("Updated client count for {$streamKey} to {$activeClientCount} from database. Unset clientless_since if present.");
                }
            } finally {
                $lock->release();
            }
        } else {
            // Failed to acquire lock, log warning. Consider impact if this happens frequently.
            Log::channel('ffmpeg')->warning("Failed to acquire lock for incrementing client count on {$streamKey}. Client count may be temporarily inaccurate.");
            // Fallback: attempt non-locked increment or simply skip to avoid blocking indefinitely.
            // For simplicity here, we'll log and potentially accept a rare miscount under extreme contention.
            // A retry mechanism could be added if this proves problematic.
        }
    }

    /**
     * Remove a client from a stream and decrement client count
     */
    public function removeClient(string $streamKey, string $clientId): void
    {
        $lock = Cache::lock('lock:stream_info_client_count:' . $streamKey, 10); // Lock for 10 seconds
        if ($lock->get()) {
            try {
                // Remove client from database (disconnect client)
                $clientRemoved = SharedStreamClient::where('stream_id', $streamKey)
                    ->where('client_id', $clientId)
                    ->update(['status' => 'disconnected']);

                if (!$clientRemoved) {
                    Log::channel('ffmpeg')->warning("Client {$clientId} not found in database for stream {$streamKey}");
                }

                // Get current active client count from database
                $activeClientCount = SharedStreamClient::where('stream_id', $streamKey)
                    ->where('status', 'connected')
                    ->count();

                // Update stream info
                $streamInfo = $this->getStreamInfo($streamKey);
                if ($streamInfo) {
                    $playlistId = $streamInfo['options']['playlist_id'] ?? null;
                    $streamInfo['client_count'] = $activeClientCount;
                    $streamInfo['last_client_activity'] = now()->timestamp;

                    // If no clients left, mark when it became clientless
                    if ($activeClientCount === 0) {
                        $streamInfo['clientless_since'] = now()->timestamp;
                        Log::channel('ffmpeg')->debug("Stream {$streamKey} now has no clients. Starting cleanup process.");

                        // Clean up the stream completely
                        $this->cleanupStream($streamKey, true);

                        // Decrement active stream count for playlist
                        if ($playlistId) {
                            $this->decrementActiveStreams($playlistId);
                        }
                        Log::channel('ffmpeg')->debug("Stream {$streamKey} completely cleaned up due to no clients.");
                        return; // Exit early since stream is cleaned up
                    } else {
                        $this->setStreamInfo($streamKey, $streamInfo);

                        // Update database client count
                        SharedStream::where('stream_id', $streamKey)->update([
                            'client_count' => $activeClientCount,
                        ]);

                        Log::channel('ffmpeg')->debug("Decremented client count for {$streamKey} to {$activeClientCount}. Client {$clientId} removed.");
                    }
                }
            } finally {
                $lock->release();
            }
        } else {
            Log::channel('ffmpeg')->warning("Failed to acquire lock for removing client {$clientId} from {$streamKey}. Client count may be temporarily inaccurate.");
        }
    }

    /**
     * Get all clients for a specific stream from database
     */
    public function getClients(string $streamKey): array
    {
        try {
            $clients = SharedStreamClient::where('stream_id', $streamKey)
                ->where('status', 'connected')
                ->get()
                ->map(function ($client) {
                    return [
                        'client_id' => $client->client_id,
                        'connected_at' => $client->connected_at->timestamp,
                        'last_activity' => $client->last_activity_at->timestamp,
                        'last_client_activity' => $client->last_activity_at->timestamp,
                        'ip_address' => $client->ip_address,
                        'user_agent' => $client->user_agent,
                        'bytes_received' => $client->bytes_received,
                        'status' => $client->status
                    ];
                })
                ->toArray();

            // Log::channel('ffmpeg')->debug("getClients: Retrieved " . count($clients) . " clients for stream '{$streamKey}' from database");
            return $clients;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error retrieving clients for {$streamKey}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Register a client for a stream in database
     */
    private function registerClient(string $streamKey, string $clientId, array $options = []): void
    {
        try {
            // Extract client info from options or request
            $ipAddress = $options['ip_address'] ?? request()->headers->get('X-Forwarded-For', request()->ip() ?? 'unknown');
            $userAgent = $options['user_agent'] ?? request()->userAgent() ?? 'unknown';
            $client = SharedStreamClient::where('stream_id', $streamKey)
                ->where('client_id', $clientId)
                ->first();
            if ($client) {
                // Update existing client info
                $update = [
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'last_activity_at' => now(),
                ];
                if ($client->status !== 'connected') {
                    // Only update connected_at if the status is changing to connected
                    $update['connected_at'] = now();
                    $update['bytes_received'] = 0; // Reset bytes received on reconnect
                    $update['status'] = 'connected'; // Ensure status is set to connected
                }
                $client->update($update);
            } else {
                SharedStreamClient::create([
                    'stream_id' => $streamKey,
                    'client_id' => $clientId,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'connected_at' => now(),
                    'last_activity_at' => now(),
                    'bytes_received' => 0,
                    'status' => 'connected'
                ]);
            }
            Log::channel('ffmpeg')->debug("Registered client {$clientId} for stream {$streamKey} in database");
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error registering client {$clientId} for stream {$streamKey}: " . $e->getMessage());
        }
    }

    private function getClientTimeoutResolved(): int
    {
        return $this->clientTimeout;
    }

    /**
     * Get all active streams with their information and client counts
     * Called by BufferManagement job and other services
     */
    public function getAllActiveStreams(): array
    {
        try {
            $streams = [];

            // Primary source: Get from database (more reliable and persistent)
            $activeStreams = SharedStream::active()->get();

            foreach ($activeStreams as $sharedStream) {
                $streamKey = $sharedStream->stream_id;

                // Get client count from database (authoritative source)
                $clientCount = SharedStreamClient::where('stream_id', $streamKey)
                    ->where('status', 'connected')
                    ->count();

                // Calculate uptime
                $uptime = $sharedStream->started_at ?
                    now()->diffInSeconds($sharedStream->started_at) : 0;

                // Get stream info from model
                $streamInfo = $sharedStream->stream_info ?: [];
                $streamInfo['stream_key'] = $streamKey;
                $streamInfo['status'] = $sharedStream->status;
                $streamInfo['pid'] = $sharedStream->process_id;
                $streamInfo['created_at'] = $sharedStream->started_at ? $sharedStream->started_at->timestamp : time();
                $streamInfo['last_activity'] = $sharedStream->last_client_activity ? $sharedStream->last_client_activity->timestamp : time();

                $streams[$streamKey] = [
                    'stream_info' => $streamInfo,
                    'client_count' => $clientCount,
                    'last_activity' => $sharedStream->last_client_activity ? $sharedStream->last_client_activity->timestamp : time(),
                    'uptime' => $uptime
                ];

                // Update client count in database if it differs
                if ($sharedStream->client_count !== $clientCount) {
                    $sharedStream->update(['client_count' => $clientCount]);
                }
            }

            // Log::channel('ffmpeg')->debug("getAllActiveStreams: Returning " . count($streams) . " active streams from database");
            return $streams;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error getting active streams: " . $e->getMessage());
        }
        return [];
    }

    /**
     * Get all active clients across all streams
     * Called by BufferManagement job and other services
     */
    public function getAllActiveClients(): array
    {
        try {
            $clients = SharedStreamClient::where('status', 'connected')
                ->get()
                ->map(function ($client) {
                    return [
                        'stream_id' => $client->stream_id,
                        'client_id' => $client->client_id,
                        'ip_address' => $client->ip_address,
                        'user_agent' => $client->user_agent,
                        'connected_at' => $client->connected_at->timestamp,
                        'last_activity_at' => $client->last_activity_at->timestamp,
                        'bytes_received' => $client->bytes_received,
                    ];
                })
                ->toArray();

            // Log::channel('ffmpeg')->debug("getAllActiveClients: Retrieved " . count($clients) . " active clients from database");
            return $clients;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error getting active clients: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clean up old buffer segments for a stream
     */
    public function cleanupOldBufferSegments(string $streamKey): int
    {
        try {
            $bufferKey = self::BUFFER_PREFIX . $streamKey;
            $segmentNumbers = $this->redis()->lrange("{$bufferKey}:segments", 0, -1);

            $cleaned = 0;
            $keepCount = 50; // Keep only the most recent 50 segments

            // Ensure we have an array before processing
            if (is_array($segmentNumbers) && count($segmentNumbers) > $keepCount) {
                $toRemove = array_slice($segmentNumbers, $keepCount);
                foreach ($toRemove as $segmentNumber) {
                    $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                    if ($this->redis()->del($segmentKey)) {
                        $cleaned++;
                    }
                }

                // Trim the segments list
                $this->redis()->ltrim("{$bufferKey}:segments", 0, $keepCount - 1);
            }

            return $cleaned;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error cleaning up buffer segments for {$streamKey}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Optimize buffer size based on client count
     */
    public function optimizeBufferSize(string $streamKey, int $clientCount): bool
    {
        try {
            // Adjust buffer size based on client count
            $baseSegments = 30;
            $additionalSegments = min($clientCount * 5, 50); // Max 50 additional segments
            $targetSegments = $baseSegments + $additionalSegments;

            $bufferKey = self::BUFFER_PREFIX . $streamKey;
            $currentSegments = $this->redis()->llen("{$bufferKey}:segments");

            if ($currentSegments > $targetSegments) {
                // Trim to target size
                $this->redis()->ltrim("{$bufferKey}:segments", 0, $targetSegments - 1);
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error optimizing buffer size for {$streamKey}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get disk usage for a stream's buffers (Redis memory usage estimate)
     */
    public function getStreamBufferDiskUsage(string $streamKey): int
    {
        try {
            $bufferKey = self::BUFFER_PREFIX . $streamKey;
            $segmentNumbers = $this->redis()->lrange("{$bufferKey}:segments", 0, -1);

            $totalSize = 0;
            if (is_array($segmentNumbers)) {
                foreach ($segmentNumbers as $segmentNumber) {
                    $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                    $data = $this->redis()->get($segmentKey);
                    if ($data) {
                        $totalSize += strlen($data);
                    }
                }
            }

            return $totalSize;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error getting buffer disk usage for {$streamKey}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Trim buffer to specified size
     */
    public function trimBufferToSize(string $streamKey, int $targetSize): int
    {
        try {
            $bufferKey = self::BUFFER_PREFIX . $streamKey;
            $segmentNumbers = $this->redis()->lrange("{$bufferKey}:segments", 0, -1);

            $currentSize = 0;
            $freedSize = 0;
            $segmentsToKeep = [];

            // Ensure we have an array before processing
            if (is_array($segmentNumbers)) {
                // Calculate current size and determine which segments to keep
                foreach (array_reverse($segmentNumbers) as $segmentNumber) {
                    $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                    $data = $this->redis()->get($segmentKey);
                    if ($data) {
                        $segmentSize = strlen($data);
                        if ($currentSize + $segmentSize <= $targetSize) {
                            $segmentsToKeep[] = $segmentNumber;
                            $currentSize += $segmentSize;
                        } else {
                            // Remove this segment
                            $this->redis()->del($segmentKey);
                            $freedSize += $segmentSize;
                        }
                    }
                }

                // Update the segments list
                if (!empty($segmentsToKeep)) {
                    $this->redis()->del("{$bufferKey}:segments");
                    foreach (array_reverse($segmentsToKeep) as $segmentNumber) {
                        $this->redis()->lpush("{$bufferKey}:segments", $segmentNumber);
                    }
                }
            }

            return $freedSize;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error trimming buffer for {$streamKey}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Find orphaned buffer directories
     */
    public function findOrphanedBufferDirectories(): array
    {
        try {
            $orphanedDirs = [];
            $bufferPath = storage_path('app/shared_streams');

            if (!is_dir($bufferPath)) {
                return $orphanedDirs;
            }

            $activeStreams = $this->getAllActiveStreams();
            $activeStreamKeys = array_keys($activeStreams);

            $directories = glob($bufferPath . '/*', GLOB_ONLYDIR);
            foreach ($directories as $dir) {
                $dirName = basename($dir);

                // Check if this directory corresponds to an active stream
                $isActive = false;
                foreach ($activeStreamKeys as $streamKey) {
                    if (md5($streamKey) === $dirName) {
                        $isActive = true;
                        break;
                    }
                }

                if (!$isActive) {
                    $orphanedDirs[] = $dir;
                }
            }

            return $orphanedDirs;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error finding orphaned buffer directories: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get directory size
     */
    public function getDirectorySize(string $dir): int
    {
        try {
            $size = 0;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                $size += $file->getSize();
            }

            return $size;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error getting directory size for {$dir}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Remove directory
     */
    public function removeDirectory(string $dir): bool
    {
        try {
            if (!is_dir($dir)) {
                return true;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }

            return rmdir($dir);
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error removing directory {$dir}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clean up temporary files
     */
    public function cleanupTempFiles(int $maxAge = 3600): int
    {
        try {
            $tempPath = storage_path('app/temp');
            $freedSize = 0;

            if (!is_dir($tempPath)) {
                return 0;
            }

            $cutoffTime = time() - $maxAge;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tempPath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getMTime() < $cutoffTime) {
                    $size = $file->getSize();
                    if (unlink($file->getRealPath())) {
                        $freedSize += $size;
                    }
                }
            }

            return $freedSize;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error cleaning up temp files: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get total buffer disk usage across all streams
     */
    public function getTotalBufferDiskUsage(): int
    {
        try {
            $totalSize = 0;
            $activeStreams = $this->getAllActiveStreams();

            foreach (array_keys($activeStreams) as $streamKey) {
                $totalSize += $this->getStreamBufferDiskUsage($streamKey);
            }

            return $totalSize;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error getting total buffer disk usage: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Trim oldest buffers to reach target size
     */
    public function trimOldestBuffers(int $targetSize): int
    {
        try {
            $activeStreams = $this->getAllActiveStreams();
            $streamSizes = [];
            $totalSize = 0;

            // Calculate sizes for all streams
            foreach ($activeStreams as $streamKey => $streamData) {
                $size = $this->getStreamBufferDiskUsage($streamKey);
                $streamSizes[$streamKey] = [
                    'size' => $size,
                    'last_activity' => $streamData['last_activity'] ?? 0
                ];
                $totalSize += $size;
            }

            if ($totalSize <= $targetSize) {
                return 0;
            }

            // Sort by last activity (oldest first)
            uasort($streamSizes, function ($a, $b) {
                return $a['last_activity'] <=> $b['last_activity'];
            });

            $freedSize = 0;
            $currentSize = $totalSize;

            foreach ($streamSizes as $streamKey => $data) {
                if ($currentSize <= $targetSize) {
                    break;
                }

                // Trim this stream's buffer by 50%
                $targetStreamSize = $data['size'] * 0.5;
                $freed = $this->trimBufferToSize($streamKey, (int)$targetStreamSize);
                $freedSize += $freed;
                $currentSize -= $freed;
            }

            return $freedSize;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error trimming oldest buffers: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check if a process is currently running
     */
    public function isProcessRunning(?int $pid): bool
    {
        if (!$pid) {
            return false;
        }

        try {
            // Use ps to check process status (works on both Linux and macOS)
            $output = shell_exec("ps -p {$pid} -o stat= 2>/dev/null");

            if (empty(trim($output))) {
                // Process doesn't exist
                return false;
            }

            $stat = trim($output);
            // Check for zombie or dead processes
            // Z = zombie, X = dead on most systems
            if (preg_match('/^[ZX]/', $stat)) {
                Log::channel('ffmpeg')->debug("Process {$pid} exists but is in state '{$stat}' (zombie/dead)");
                return false;
            }

            // Process exists and is not zombie/dead
            return true;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error checking if process {$pid} is running: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the storage path for a stream
     */
    private function getStreamStoragePath(string $streamKey): string
    {
        $bufferPath = config('proxy.shared_streaming.storage.buffer_path', 'shared_streams');
        $storagePath = 'hls/' . $bufferPath . '/' . str_replace(':', '_', $streamKey);
        return $storagePath;
    }

    /**
     * Get the storage directory for a stream
     */
    private function getStreamStorageDir(string $streamKey): string
    {
        $fullPath = Storage::disk('app')->path($this->getStreamStoragePath($streamKey));
        return $fullPath;
    }

    /**
     * Track bandwidth usage for a stream
     */
    private function trackBandwidth(string $streamKey, int $bytesTransferred): void
    {
        try {
            // Get current time
            $now = time();

            // Get or initialize bandwidth tracking data
            $bandwidthKey = "bandwidth:{$streamKey}";
            $bandwidthData = Redis::get($bandwidthKey);

            if ($bandwidthData) {
                $data = json_decode($bandwidthData, true);
            } else {
                $data = [
                    'total_bytes' => 0,
                    'samples' => [],
                    'last_update' => $now
                ];
            }

            // Add bytes to total
            $data['total_bytes'] += $bytesTransferred;

            // Add sample for bandwidth calculation (keep last 10 samples)
            $data['samples'][] = [
                'timestamp' => $now,
                'bytes' => $bytesTransferred
            ];

            // Keep only last 10 samples for bandwidth calculation
            if (count($data['samples']) > 10) {
                $data['samples'] = array_slice($data['samples'], -10);
            }

            // Calculate current bandwidth (kbps) over last 10 seconds
            $recentSamples = array_filter($data['samples'], function ($sample) use ($now) {
                return ($now - $sample['timestamp']) <= 10;
            });

            if (count($recentSamples) > 1) {
                $totalBytes = array_sum(array_column($recentSamples, 'bytes'));
                $timeSpan = max(1, $now - min(array_column($recentSamples, 'timestamp')));
                $bandwidthKbps = ($totalBytes * 8) / ($timeSpan * 1000); // Convert to kbps
            } else {
                $bandwidthKbps = 0;
            }

            $data['last_update'] = $now;
            $data['current_bandwidth_kbps'] = round($bandwidthKbps, 2);

            // Store bandwidth data in Redis (expires in 1 hour)
            Redis::setex($bandwidthKey, 3600, json_encode($data));

            // Update database periodically (every 10 seconds to avoid too many writes)
            if ($now % 10 === 0) {
                SharedStream::where('stream_id', $streamKey)->update([
                    'bytes_transferred' => $data['total_bytes'],
                    'bandwidth_kbps' => (int)$bandwidthKbps
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error tracking bandwidth for {$streamKey}: " . $e->getMessage());
        }
    }

    /**
     * Update stream activity timestamp
     */
    private function updateStreamActivity(string $streamKey): void
    {
        $streamInfo = $this->getStreamInfo($streamKey);

        if ($streamInfo) {
            Log::channel('ffmpeg')->debug(">>>>> Updating stream activity for {$streamKey}");

            $streamInfo['last_activity'] = now()->timestamp;
            $this->setStreamInfo($streamKey, $streamInfo);
        } else {
            Log::channel('ffmpeg')->warning("Stream {$streamKey}: No stream info found to update activity timestamp");
        }
    }

    /**
     * Update client activity timestamp in database
     */
    private function updateClientActivity(string $streamKey, string $clientId): void
    {
        try {
            $updated = SharedStreamClient::where('stream_id', $streamKey)
                ->where('client_id', $clientId)
                ->where('status', 'connected')
                ->update(['last_activity_at' => now()]);

            if ($updated) {
                Log::channel('ffmpeg')->debug(">>>>> Updating client activity for {$streamKey} - client ID: {$clientId}");
            } else {
                Log::channel('ffmpeg')->warning("Stream {$streamKey}: No active client found for client ID {$clientId} to update activity timestamp");
            }
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error updating client activity for {$streamKey} client {$clientId}: " . $e->getMessage());
        }
    }

    /**
     * Cleanup stream data
     */
    public function cleanupStream(string $streamKey, bool $removeFiles = false): void
    {
        // First, kill the FFmpeg process if it exists
        $pid = $this->getProcessPid($streamKey);
        if ($pid && $this->isProcessRunning($pid)) {
            Log::channel('ffmpeg')->debug("Terminating FFmpeg process (PID: {$pid}) for stream {$streamKey}");

            // Try graceful termination first (SIGTERM)
            exec("kill -TERM {$pid} 2>/dev/null");

            // Wait a moment for graceful shutdown
            sleep(1);

            // If still running, force kill (SIGKILL)
            if ($this->isProcessRunning($pid)) {
                Log::channel('ffmpeg')->warning("FFmpeg process {$pid} didn't respond to SIGTERM, using SIGKILL");
                exec("kill -KILL {$pid} 2>/dev/null");
                sleep(1); // Give it a moment to die
            }

            // Verify it's actually dead
            if (!$this->isProcessRunning($pid)) {
                Log::channel('ffmpeg')->debug("FFmpeg process {$pid} successfully terminated for stream {$streamKey}");
            } else {
                Log::channel('ffmpeg')->error("Failed to terminate FFmpeg process {$pid} for stream {$streamKey}");
            }
        }

        // Clean up active process if it exists
        if (isset($this->activeProcesses[$streamKey])) {
            $processInfo = $this->activeProcesses[$streamKey];

            // Close file handles
            if (is_resource($processInfo['stdout'])) {
                fclose($processInfo['stdout']);
            }
            if (is_resource($processInfo['stderr'])) {
                fclose($processInfo['stderr']);
            }

            // Close the process
            if (is_resource($processInfo['process'])) {
                proc_close($processInfo['process']);
            }

            unset($this->activeProcesses[$streamKey]);
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Cleaned up active process handles");
        }

        // Remove all Redis data related to this stream

        // Use the unique part of the stream identifier (1223568:hash)
        preg_match('/channel:(\d+:[a-f0-9]+)/', $streamKey, $matches);
        $streamIdentifier = $matches[1] ?? str_replace('shared_stream:', '', $streamKey);

        // Use shell command since Laravel Redis isn't finding the keys correctly
        $deleteCommand = "redis-cli --scan --pattern '*{$streamIdentifier}*' | xargs redis-cli del 2>/dev/null";
        $output = shell_exec($deleteCommand);
        $deletedCount = (int) trim($output);

        if ($deletedCount > 0) {
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Cleaned up {$deletedCount} Redis keys including all buffer segments via shell command");
        } else {
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: No Redis keys found to clean up (pattern: *{$streamIdentifier}*)");
        }

        Log::channel('ffmpeg')->debug("Stream {$streamKey}: All Redis buffer data and keys cleaned up");

        // Clean up files if requested
        if ($removeFiles) {
            $storageDir = $this->getStreamStorageDir($streamKey);
            if (is_dir($storageDir)) {
                $this->removeDirectory($storageDir);
            }
        }

        // Clean up connected clients
        SharedStreamClient::where('stream_id', $streamKey)
            ->update(['status' => 'disconnected', 'last_activity_at' => now()]);

        // Finally, update the database to mark the stream as stopped
        SharedStream::where('stream_id', $streamKey)
            ->update([
                'status' => 'stopped',
                'started_at' => null,
                'stopped_at' => now(),
                'process_id' => null,
                'buffer_size' => 0, // Reset buffer size
                'error_message' => null, // Reset error message
                // 'stream_info' => null, // Reset stream info (should we do this? might need details in other methods...)
                'client_count' => 0,
                'bandwidth_kbps' => 0,
                'bytes_transferred' => 0,
                'last_client_activity' => null,
                'health_check_at' => null, // Reset health check timestamp
                'health_status' => 'unknown' // Reset health status
            ]);
    }

    /**
     * Cleanup inactive streams and disconnected clients
     */
    public function cleanupInactiveStreams(): array
    {
        $cleanedStreams = 0;
        $cleanedClients = 0;

        try {
            // Get all shared streams from database
            $inactiveThreshold = now()->subMinutes(30); // 30 minutes
            $deadProcessThreshold = now()->subMinutes(5); // 5 minutes for dead processes

            $sharedStreams = SharedStream::all();

            foreach ($sharedStreams as $sharedStream) {
                $streamKey = $sharedStream->stream_id;
                $shouldCleanup = false;

                Log::info("Processing stream for cleanup: {$streamKey}");

                // Check if stream is inactive
                if ($sharedStream->last_client_activity && $sharedStream->last_client_activity < $inactiveThreshold) {
                    Log::info("Stream {$streamKey} inactive since " . $sharedStream->last_client_activity->format('Y-m-d H:i:s'));
                    $shouldCleanup = true;
                }

                // Check if process is dead
                if (
                    $sharedStream->process_id && !$this->isProcessRunning($sharedStream->process_id) &&
                    $sharedStream->last_client_activity && $sharedStream->last_client_activity < $deadProcessThreshold
                ) {
                    Log::info("Stream {$streamKey} has dead process (PID: {$sharedStream->process_id})");
                    $shouldCleanup = true;
                }

                // Check if status indicates error or stopped
                if (in_array($sharedStream->status, ['error', 'stopped'])) {
                    Log::info("Stream {$streamKey} has status: {$sharedStream->status}");
                    $shouldCleanup = true;
                }

                if ($shouldCleanup) {
                    // Count clients before cleanup
                    $clientCount = SharedStreamClient::where('stream_id', $streamKey)->count();
                    $cleanedClients += $clientCount;

                    // Cleanup the stream
                    $this->cleanupStream($streamKey, true);
                    $cleanedStreams++;

                    Log::info("Cleaned up inactive stream: {$streamKey}");
                } else {
                    // Clean up disconnected clients for active streams
                    $inactiveClients = SharedStreamClient::where('stream_id', $streamKey)
                        ->where('last_activity_at', '<', $inactiveThreshold)
                        ->get();

                    foreach ($inactiveClients as $client) {
                        $client->delete();
                        $cleanedClients++;
                        Log::info("Removed inactive client {$client->client_id} from stream {$streamKey}");
                    }

                    // Update stream client count
                    $remainingClients = SharedStreamClient::where('stream_id', $streamKey)
                        ->where('status', 'connected')
                        ->count();

                    $sharedStream->update(['client_count' => $remainingClients]);
                }
            }

            Log::info("Cleanup completed: {$cleanedStreams} streams, {$cleanedClients} clients");
        } catch (\Exception $e) {
            Log::error('Error during stream cleanup: ' . $e->getMessage());
        }

        return [
            'cleaned_streams' => $cleanedStreams,
            'cleaned_clients' => $cleanedClients
        ];
    }

    /**
     * Clean up orphaned Redis keys that don't have corresponding database records
     */
    public function cleanupOrphanedKeys(): int
    {
        $cleanedKeys = 0;

        try {
            // Get all shared stream keys from Redis
            $pattern1 = 'shared_stream:*';
            $pattern2 = '*shared_stream:*';

            $keys1 = Redis::keys($pattern1);
            $keys2 = Redis::keys($pattern2);
            $allKeys = array_merge($keys1, $keys2);

            // Filter to only main stream info keys (not buffer, PID, etc.)
            $streamInfoKeys = array_filter($allKeys, function ($key) {
                return preg_match('/shared_stream:channel:\d+:[a-f0-9]+$/', str_replace(config('database.redis.options.prefix', ''), '', $key));
            });

            foreach ($streamInfoKeys as $redisKey) {
                $streamKey = str_replace([
                    config('database.redis.options.prefix', ''),
                ], '', $redisKey);

                // Check if corresponding database record exists
                $dbRecord = SharedStream::where('stream_id', $streamKey)->first();

                if (!$dbRecord && Redis::exists($redisKey)) {
                    // Orphaned Redis key - clean it up
                    Redis::del($redisKey);
                    $cleanedKeys++;
                    Log::info("Cleaned up orphaned Redis key: {$redisKey}");
                }
            }
        } catch (\Exception $e) {
            Log::error('Error during orphaned keys cleanup: ' . $e->getMessage());
        }

        return $cleanedKeys;
    }

    /**
     * Get next stream segments for a client
     * 
     * DO NOT DELETE THIS METHOD!
     */
    public function getNextStreamSegments(string &$streamKey, string $clientId, int &$lastSegment): ?string
    {
        // Prevent infinite redirect loops by tracking redirects per client across all streams for this channel
        $streamInfo = $this->getStreamInfo($streamKey);
        $channelId = $streamInfo['primary_channel_id'] ?? $streamInfo['model_id'] ?? 'unknown';

        // Use channel-based redirect tracking instead of stream-based to prevent loops
        $redirectTrackingKey = "redirect_tracking:{$clientId}:channel:{$channelId}";
        $redirectCount = (int)$this->redis()->get($redirectTrackingKey);

        // Check if we're already on a failover stream and give it time to buffer
        $isFailoverStream = isset($streamInfo['is_failover']) && $streamInfo['is_failover'] === true;
        $streamAge = isset($streamInfo['created_at']) ? (time() - $streamInfo['created_at']) : 0;
        $failoverGracePeriod = 30; // Give failover streams 30 seconds to start producing data

        $shouldCheckForFailover = true;

        // If we're on a failover stream that's still young, don't look for more failovers yet
        if ($isFailoverStream && $streamAge < $failoverGracePeriod) {
            $shouldCheckForFailover = false;
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: On failover stream (age: {$streamAge}s), waiting for buffering to complete");
        }

        // Only check for failover if the current stream has actually failed
        if ($shouldCheckForFailover) {
            $redirectedStreamKey = $this->checkForFailoverRedirect($streamKey, $clientId);
            if ($redirectedStreamKey && $redirectedStreamKey !== $streamKey) {
                if ($redirectCount >= $this->maxRedirects) {
                    Log::channel('ffmpeg')->warning("Stream {$streamKey}: Client {$clientId} reached maximum redirect attempts for channel {$channelId}, stopping failover");
                    $this->redis()->del($redirectTrackingKey);
                    return null;
                }

                // Increment redirect count with configurable TTL
                $this->redis()->setex($redirectTrackingKey, $this->redirectTtl, $redirectCount + 1);

                Log::channel('ffmpeg')->debug("Stream {$streamKey}: Redirecting client {$clientId} to failover stream {$redirectedStreamKey} (attempt " . ($redirectCount + 1) . " for channel {$channelId})");

                // Update the streamKey by reference
                $streamKey = $redirectedStreamKey;

                // Reset last segment for the new stream to start fresh
                $lastSegment = -1;
            } else {
                // Reset redirect count if we're not redirecting (stream is working)
                if ($redirectCount > 0) {
                    $this->redis()->del($redirectTrackingKey);
                }
            }
        }

        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $segmentNumbers = $this->redis()->lrange("{$bufferKey}:segments", 0, -1);

        $data = '';
        $segmentsRetrieved = 0;
        $maxSegmentsPerCall = 5; // Limit segments per call to prevent overwhelming clients

        // First, try to get data from existing buffered segments
        if (!empty($segmentNumbers)) {
            // Convert segment numbers to integers and sort them
            $segmentNumbers = array_map('intval', $segmentNumbers);
            sort($segmentNumbers);

            foreach ($segmentNumbers as $segmentNumber) {
                // Only get segments newer than the last one sent to this client
                if ($segmentNumber > $lastSegment && $segmentsRetrieved < $maxSegmentsPerCall) {
                    $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                    $segmentData = $this->redis()->get($segmentKey);
                    if ($segmentData) {
                        $data .= $segmentData;
                        $lastSegment = $segmentNumber; // Update the reference variable
                        $segmentsRetrieved++;
                    }
                }
            }
        }

        // If no buffered data is available, try to read directly from FFmpeg
        if (empty($data) && isset($this->activeProcesses[$streamKey])) {
            $data = $this->readDirectFromFFmpeg($streamKey, $lastSegment);
        }

        // If data is not empty, track bandwidth and update activity
        if (!empty($data)) {
            $this->trackBandwidth($streamKey, strlen($data));
            $this->updateStreamActivity($streamKey);
            $this->updateClientActivity($streamKey, $clientId);
        }

        return $data;
    }

    /**
     * Read data directly from FFmpeg process
     */
    private function readDirectFromFFmpeg(string $streamKey, int &$lastSegment): ?string
    {
        if (!isset($this->activeProcesses[$streamKey])) {
            return null;
        }

        $processInfo = $this->activeProcesses[$streamKey];
        $stdout = $processInfo['stdout'];
        $stderr = $processInfo['stderr'];
        $process = $processInfo['process'];

        // Check if process is still running
        $status = proc_get_status($process);
        if (!$status['running']) {
            Log::channel('ffmpeg')->warning("Stream {$streamKey}: FFmpeg process ended, attempting failover if available");
            unset($this->activeProcesses[$streamKey]);

            // Attempt failover if this is a channel stream
            $streamInfo = $this->getStreamInfo($streamKey);

            if ($streamInfo && ($streamInfo['type'] ?? '') === 'channel') {
                Log::channel('ffmpeg')->debug("Stream {$streamKey}: Attempting failover for channel {$streamInfo['model_id']}");
                $failoverStreamKey = $this->attemptStreamFailover($streamKey, $streamInfo);

                if ($failoverStreamKey && $failoverStreamKey !== $streamKey) {
                    // Failover succeeded, try to get initial data from the new stream
                    Log::channel('ffmpeg')->debug("Stream {$streamKey}: Failover completed, getting data from new stream {$failoverStreamKey}");

                    // Wait a moment for the new stream to be ready
                    sleep(1);

                    // Try to get data from the new stream
                    if (isset($this->activeProcesses[$failoverStreamKey])) {
                        return $this->readDirectFromFFmpeg($failoverStreamKey, $lastSegment);
                    }
                }
            }

            return null;
        }

        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $redis = $this->redis();
        $targetChunkSize = 188 * 1000; // 188KB chunks
        $accumulatedData = '';
        $accumulatedSize = 0;
        $maxReadTime = 2; // Maximum 2 seconds to read data
        $startTime = time();
        $readAttempts = 0;
        $maxReadAttempts = 20; // Maximum read attempts

        while (
            $accumulatedSize < $targetChunkSize &&
            (time() - $startTime) < $maxReadTime &&
            $readAttempts < $maxReadAttempts
        ) {

            $readAttempts++;

            // Try to read data from FFmpeg stdout
            $chunk = fread($stdout, 32768); // Read 32KB chunks
            if ($chunk !== false && strlen($chunk) > 0) {
                $accumulatedData .= $chunk;
                $accumulatedSize += strlen($chunk);

                // Only log every 10th read attempt or when target size is reached to reduce noise
                if ($accumulatedSize >= $targetChunkSize || ($readAttempts % 10 === 0 && $readAttempts > 0)) {
                    Log::channel('ffmpeg')->debug("Stream {$streamKey}: Read " . round($accumulatedSize / 1024, 1) . "KB from FFmpeg in {$readAttempts} attempts");
                }
            } else {
                // No immediate data available, small sleep
                usleep(100000); // 100ms
            }

            // Check for errors
            $error = fread($stderr, 1024);
            if ($error !== false && strlen($error) > 0) {
                Log::channel('ffmpeg')->error("Stream {$streamKey}: FFmpeg error: {$error}");
            }
        }

        if ($accumulatedSize > 0) {
            // Get the highest existing segment number and increment it
            $existingSegments = $redis->lrange("{$bufferKey}:segments", 0, -1);
            $newSegmentNumber = 0;

            if (!empty($existingSegments)) {
                $maxSegment = max(array_map('intval', $existingSegments));
                $newSegmentNumber = $maxSegment + 1;
            }

            $segmentKey = "{$bufferKey}:segment_{$newSegmentNumber}";
            $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
            $redis->lpush("{$bufferKey}:segments", $newSegmentNumber);

            // Keep only recent segments (prevent memory bloat)
            $redis->ltrim("{$bufferKey}:segments", 0, 50);

            // Clean up old segments - only delete segments that are definitely old
            $segmentsToCleanup = $redis->lrange("{$bufferKey}:segments", 50, -1);
            foreach ($segmentsToCleanup as $oldSegmentNum) {
                $oldSegmentKey = "{$bufferKey}:segment_{$oldSegmentNum}";
                $redis->del($oldSegmentKey);
            }

            $lastSegment = $newSegmentNumber;

            // Only log every 10th segment to reduce noise, unless it's the first few segments
            if ($newSegmentNumber <= 5 || $newSegmentNumber % 10 === 0) {
                Log::channel('ffmpeg')->debug("Stream {$streamKey}: Stored " . round($accumulatedSize / 1024, 1) . "KB as segment {$newSegmentNumber}");
            }

            return $accumulatedData;
        }

        return null;
    }

    /**
     * Get stream statistics/status information
     */
    public function getStreamStats(string $streamKey): ?array
    {
        $streamInfo = $this->getStreamInfo($streamKey);
        if (!$streamInfo) {
            return null;
        }

        return [
            'status' => $streamInfo['status'] ?? 'unknown',
            'client_count' => $streamInfo['client_count'] ?? 0,
            'created_at' => $streamInfo['created_at'] ?? null,
            'last_activity' => $streamInfo['last_activity'] ?? null,
            'process_id' => $streamInfo['process_id'] ?? null,
            'uptime' => isset($streamInfo['created_at']) ? (time() - $streamInfo['created_at']) : 0
        ];
    }

    /**
     * Stop a specific stream manually (for monitor interface)
     * 
     * DO NOT DELETE THIS METHOD!
     */
    public function stopStream(string $streamId): bool
    {
        try {
            Log::channel('ffmpeg')->debug("Manual stop requested for stream: {$streamId}");

            // Get stream info first
            $streamInfo = $this->getStreamInfo($streamId);
            if (!$streamInfo) {
                Log::channel('ffmpeg')->warning("Stream {$streamId} not found for manual stop");
                return false;
            }

            // Stop the FFmpeg process if it exists (will be handled by cleanupStream)
            $pid = $this->getProcessPid($streamId);
            if ($pid) {
                Log::channel('ffmpeg')->debug("Stopping FFmpeg process (PID: {$pid}) for stream {$streamId}");
            }

            // Clean up the stream data (this will also stop the process)
            $this->cleanupStream($streamId, true);
            Log::channel('ffmpeg')->debug("Successfully stopped stream {$streamId} manually");
            return true;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error stopping stream {$streamId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new shared stream manually (for monitor interface)
     * 
     * DO NOT DELETE THIS METHOD!
     */
    public function createSharedStream(string $sourceUrl, string $format = 'ts'): ?string
    {
        try {
            // Generate a stream key for the new stream
            $streamKey = 'shared_stream:manual:' . time() . ':' . md5($sourceUrl);

            Log::channel('ffmpeg')->debug("Creating manual shared stream: {$streamKey}");

            $streamInfo = [
                'stream_key' => $streamKey,
                'type' => 'manual',
                'model_id' => 0,
                'stream_url' => $sourceUrl,
                'title' => 'Manual Stream',
                'format' => $format,
                'status' => 'starting',
                'client_count' => 0,
                'created_at' => now()->timestamp,
                'last_client_activity' => now()->timestamp,
                'options' => []
            ];

            // Store stream info
            $this->setStreamInfo($streamKey, $streamInfo);

            // Create database record
            SharedStream::create([
                'stream_id' => $streamKey,
                'source_url' => $sourceUrl,
                'format' => $format,
                'status' => 'starting',
                'client_count' => 1, // Start with 1 since we have the initial client
                'last_client_activity' => now(),
                'stream_info' => $streamInfo, // Store as array, model will cast to JSON
                'started_at' => now()
            ]);

            // Start the streaming process
            $this->startStreamingProcess($streamKey, $streamInfo);

            Log::channel('ffmpeg')->debug("Created manual shared stream: {$streamKey}");
            return $streamKey;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error creating manual stream: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Synchronize state between database and Redis
     * 
     * @return array<string, int>
     */
    public function synchronizeState(): array
    {
        $stats = [
            'db_updated' => 0,
            'redis_cleaned' => 0,
            'inconsistencies_fixed' => 0
        ];

        try {
            // Get all Redis stream keys
            $redisKeys = Redis::keys(self::STREAM_PREFIX . '*');
            $activeRedisStreams = [];

            foreach ($redisKeys as $key) {
                if (strpos($key, ':clients') === false && strpos($key, ':buffer') === false) {
                    $streamId = str_replace(self::STREAM_PREFIX, '', $key);
                    $activeRedisStreams[] = $streamId;
                }
            }

            // Get all database streams
            $dbStreams = SharedStream::all();

            // Check Redis streams that don't exist in DB
            foreach ($activeRedisStreams as $streamId) {
                $dbStream = $dbStreams->firstWhere('stream_id', $streamId);
                if (!$dbStream) {
                    // Redis stream without DB record - clean up Redis
                    $this->cleanupStream($streamId, true);
                    $stats['redis_cleaned']++;
                    $stats['inconsistencies_fixed']++;
                }
            }

            // Check DB streams for inconsistencies
            foreach ($dbStreams as $dbStream) {
                $streamKey = self::STREAM_PREFIX . $dbStream->stream_id;
                $redisExists = Redis::exists($streamKey);
                $processRunning = $dbStream->process_id ? $this->isProcessRunning($dbStream->process_id) : false;

                $needsUpdate = false;

                // If DB says active but no Redis data and no process
                if ($dbStream->status === 'active' && !$redisExists && !$processRunning) {
                    $dbStream->update([
                        'status' => 'stopped',
                        'process_id' => null,
                        'client_count' => 0
                    ]);
                    $needsUpdate = true;
                    $stats['inconsistencies_fixed']++;
                }

                // If DB says stopped but Redis data exists
                if ($dbStream->status === 'stopped' && $redisExists) {
                    // Check if there are actually clients
                    $clientCount = $this->getClientCount($dbStream->stream_id);
                    if ($clientCount > 0 && $processRunning) {
                        $dbStream->update([
                            'status' => 'active',
                            'client_count' => $clientCount
                        ]);
                        $needsUpdate = true;
                    } else {
                        // Clean up orphaned Redis data
                        $this->cleanupStream($dbStream->stream_id, true);
                        $stats['redis_cleaned']++;
                    }
                    $stats['inconsistencies_fixed']++;
                }

                // Update client count if mismatch
                if ($redisExists) {
                    $actualClientCount = $this->getClientCount($dbStream->stream_id);
                    if ($dbStream->client_count !== $actualClientCount) {
                        $dbStream->update(['client_count' => $actualClientCount]);
                        $needsUpdate = true;
                    }
                }

                if ($needsUpdate) {
                    $stats['db_updated']++;
                }
            }

            // Clean up orphaned Redis keys
            $orphanedKeys = $this->cleanupOrphanedKeys();
            $stats['redis_cleaned'] += $orphanedKeys;

            Log::info('Stream state synchronization completed', $stats);
        } catch (\Exception $e) {
            Log::error('Stream state synchronization failed: ' . $e->getMessage());
            throw $e;
        }

        return $stats;
    }

    /**
     * Get the current client count for a stream
     * 
     * @param string $streamId
     * @return int
     */
    public function getClientCount(string $streamId): int
    {
        // Count clients from database
        return SharedStreamClient::where('stream_id', $streamId)
            ->where('status', 'connected')
            ->count();
    }

    /**
     * Attempt to failover a failed stream to a backup channel
     */
    private function attemptStreamFailover(string $originalStreamKey, array $streamInfo): ?string
    {
        $modelId = $streamInfo['model_id'] ?? null;
        $type = $streamInfo['type'] ?? null;
        $originalStreamInfo = [
            ...$streamInfo, // create a copy of the original stream info
        ];

        if (!$modelId || $type !== 'channel') {
            Log::channel('ffmpeg')->debug("Stream {$originalStreamKey}: No model ID or not a channel, cannot attempt failover");
            return null;
        }

        try {
            // Get the original channel with failover channels
            $primaryChannel = Channel::with('failoverChannels')->find($modelId);
            if (!$primaryChannel || $primaryChannel->failoverChannels->isEmpty()) {
                Log::channel('ffmpeg')->debug("Stream {$originalStreamKey}: No failover channels available for channel {$modelId}");
                return null;
            }

            Log::channel('ffmpeg')->debug("Stream {$originalStreamKey}: Primary channel {$modelId} failed, attempting failover to " . $primaryChannel->failoverChannels->count() . " backup channels");

            // Get current failover attempt count
            $currentAttempts = $streamInfo['failover_attempts'] ?? 0;
            $availableFailovers = $primaryChannel->failoverChannels->slice($currentAttempts);

            if ($availableFailovers->isEmpty()) {
                Log::channel('ffmpeg')->error("Stream {$originalStreamKey}: All failover channels exhausted for channel {$modelId}");
                $this->markStreamFailed($originalStreamKey, "All failover channels failed");
                return null;
            }

            // Try each failover channel
            foreach ($availableFailovers as $index => $failoverChannel) {
                $attemptNumber = $currentAttempts + $index + 1;
                $failoverUrl = $failoverChannel->url_custom ?? $failoverChannel->url;
                $failoverTitle = $failoverChannel->title_custom ?? $failoverChannel->title;

                if (!$failoverUrl) {
                    Log::channel('ffmpeg')->debug("Stream {$originalStreamKey}: Failover channel {$failoverChannel->id} has no URL, skipping");
                    continue;
                }

                Log::channel('ffmpeg')->debug("Stream {$originalStreamKey}: Attempting failover #{$attemptNumber} to channel {$failoverChannel->id} ({$failoverTitle})");
                try {
                    // Generate new stream key for failover
                    $failoverStreamKey = $this->getStreamKey('channel', $failoverChannel->id, $failoverUrl);

                    // Update stream info to reflect failover
                    $streamInfo['active_channel_id'] = $failoverChannel->id;
                    $streamInfo['failover_attempts'] = $attemptNumber;
                    $streamInfo['is_failover'] = true;
                    $streamInfo['primary_channel_id'] = $modelId; // Add this critical field
                    $streamInfo['stream_url'] = $failoverUrl;
                    $streamInfo['title'] = $failoverTitle;
                    $streamInfo['model_id'] = $failoverChannel->id;
                    $streamInfo['status'] = 'starting'; // Will be active after starting
                    $streamInfo['restart_attempt'] = time();
                    unset($streamInfo['error_message']);

                    $failoverStream = SharedStream::updateOrCreate(
                        ['stream_id' => $failoverStreamKey],
                        [
                            'source_url' => $failoverUrl,
                            'status' => 'starting',
                            'process_id' => $this->getProcessPid($failoverStreamKey),
                            'error_message' => null,
                            'started_at' => now(),
                            'stream_info' => $streamInfo,
                            'health_status' => 'unknown',
                            'health_check_at' => null,
                        ]
                    );

                    // Start the failover stream
                    // NOTE: Stream will be marked as ACTIVE after starting
                    if ($streamInfo['format'] === 'hls') {
                        $this->startHLSStream($failoverStreamKey, $streamInfo);
                    } else {
                        $this->startDirectStream($failoverStreamKey, $streamInfo);
                    }

                    // Mark primary stream as failed over
                    SharedStream::where('stream_id', $originalStreamKey)->update([
                        'status' => 'error',
                        'stopped_at' => now(),
                        'error_message' => "Failed over to channel {$failoverChannel->id} after {$attemptNumber} attempts",
                        // 'client_count' => 0, // Clients should be migrated, not deleted, so we don't reset this
                        // 'bandwidth_kbps' => 0,
                        // 'bytes_transferred' => 0,
                        'last_client_activity' => null, // Reset last activity since this stream is no longer active
                        'health_check_at' => now(),
                        'health_status' => 'unhealthy',
                        'stream_info' => [
                            ...$originalStreamInfo,
                            'status' => 'failed_over',
                            'failover_stream_key' => $failoverStreamKey,
                            'failed_over_at' => now()->timestamp,
                        ]
                    ]);

                    // Don't immediately clean up original stream Redis data - leave redirect info
                    // Store redirect mapping for future client requests  
                    $failoverRedirectKey = "stream_failover_redirect:{$originalStreamKey}";
                    $this->redis()->setex($failoverRedirectKey, 600, $failoverStreamKey); // Cache for 10 minutes

                    // Move clients to new stream key
                    $this->migrateClientsToFailoverStream($originalStreamKey, $failoverStreamKey);

                    Log::channel('ffmpeg')->debug("Stream {$originalStreamKey}: Successfully failed over to channel {$failoverChannel->id} (attempt #{$attemptNumber}). New stream key: {$failoverStreamKey}");

                    // Return the failover stream key so the client can be redirected
                    return $failoverStreamKey;
                } catch (\Exception $e) {
                    Log::channel('ffmpeg')->error("Stream {$originalStreamKey}: Failover attempt #{$attemptNumber} to channel {$failoverChannel->id} failed: " . $e->getMessage());
                    continue;
                }
            }

            // All failover attempts failed
            Log::channel('ffmpeg')->error("Stream {$originalStreamKey}: All available failover channels failed for channel {$modelId}");
            $this->markStreamFailed($originalStreamKey, "All failover channels failed after {$currentAttempts} attempts");
            return null;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Stream {$originalStreamKey}: Error during failover attempt: " . $e->getMessage());
            $this->markStreamFailed($originalStreamKey, "Failover error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Mark a stream as failed
     */
    private function markStreamFailed(string $streamKey, string $reason): void
    {
        $streamInfo = $this->getStreamInfo($streamKey) ?: [];
        SharedStream::where('stream_id', $streamKey)->update([
            'status' => 'error',
            'error_message' => $reason,
            'stopped_at' => now(),
            'stream_info' => [
                ...$streamInfo,
                'status' => 'error',
                'error_message' => $reason,
            ],
        ]);

        Log::channel('ffmpeg')->error("Stream {$streamKey}: Marked as failed - {$reason}");
    }

    /**
     * Clean up Redis data for a stream without touching active processes
     */
    private function cleanupStreamRedisData(string $streamKey): void
    {
        // Get all keys related to this stream
        $keys = $this->redis()->keys("*{$streamKey}*");

        // Filter out redirect keys to preserve them for client redirection
        $keysToDelete = array_filter($keys, function ($key) {
            return strpos($key, 'stream_failover_redirect:') === false;
        });

        if (!empty($keysToDelete)) {
            $this->redis()->del($keysToDelete);
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Cleaned up " . count($keysToDelete) . " Redis keys (preserved " . (count($keys) - count($keysToDelete)) . " redirect keys)");
        }
    }

    /**
     * Migrate clients from original stream to failover stream
     */
    private function migrateClientsToFailoverStream(string $originalStreamKey, string $failoverStreamKey): void
    {
        // Get all clients for the original stream from database
        $clients = SharedStreamClient::where('stream_id', $originalStreamKey)
            ->where('status', 'connected')
            ->get();

        // If no clients, nothing to migrate
        if ($clients->isEmpty()) {
            return;
        }

        Log::channel('ffmpeg')->debug("Migrating " . $clients->count() . " clients from {$originalStreamKey} to {$failoverStreamKey}");
        foreach ($clients as $client) {
            // Register client with the failover stream
            $this->registerClient($failoverStreamKey, $client->client_id, $client->options ?? []);

            // Remove client from the original stream
            $client->delete();
            Log::channel('ffmpeg')->debug("Migrated client {$client->client_id} from {$originalStreamKey} to {$failoverStreamKey}");
        }

        // Update the client count in the database for both streams
        $failoverClientCount = SharedStreamClient::where('stream_id', $failoverStreamKey)
            ->where('status', 'connected')->count();
        SharedStream::where('stream_id', $failoverStreamKey)
            ->update(['client_count' => $failoverClientCount]);

        $originalClientCount = SharedStreamClient::where('stream_id', $originalStreamKey)
            ->where('status', 'connected')->count();
        SharedStream::where('stream_id', $originalStreamKey)
            ->update(['client_count' => $originalClientCount]);

        Log::channel('ffmpeg')->debug("Updated client count for failover stream {$failoverStreamKey} to {$failoverClientCount}");
    }

    /**
     * Check if a failover is currently in progress for a stream
     */
    public function isFailoverInProgress(string $streamKey): bool
    {
        // Use a short-term cache to prevent excessive calls
        $cacheKey = "failover_check_cache:{$streamKey}";
        $cached = $this->redis()->get($cacheKey);
        if ($cached !== null && $cached !== false) {
            return (bool) $cached;
        }

        try {
            $result = $this->checkFailoverProgress($streamKey);

            // Cache the result for 5 seconds to prevent excessive checks
            $this->redis()->setex($cacheKey, 5, $result ? '1' : '0');

            return $result;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->warning("Stream {$streamKey}: Error checking failover progress: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Internal method to check failover progress
     */
    private function checkFailoverProgress(string $streamKey): bool
    {
        try {
            // Check if original stream is marked as failed_over
            $streamInfo = $this->getStreamInfo($streamKey);
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Checking failover progress - Stream info status: " . ($streamInfo['status'] ?? 'null'));

            if ($streamInfo && isset($streamInfo['status']) && ($streamInfo['status'] === 'failed_over' || $streamInfo['status'] === 'error')) {
                if ($streamInfo['status'] === 'failed_over') {
                    // Check how recent the failover was (allow up to 2 minutes for failover to complete)
                    $failedOverAt = $streamInfo['failed_over_at'] ?? 0;
                    if ($failedOverAt > 0 && (time() - $failedOverAt) < $this->clientTimeout) {
                        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Recent failover detected (failed over " . (time() - $failedOverAt) . "s ago)");
                        return true;
                    }
                } elseif ($streamInfo['status'] === 'error') {
                    // Check if this is a recent error that might indicate failed process (potential failover scenario)
                    $lastActivity = $streamInfo['last_activity'] ?? 0;
                    if ($lastActivity > 0 && (time() - $lastActivity) < $this->clientTimeout) {
                        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Recent error detected, treating as potential failover scenario");
                        return true;
                    }
                }
            }

            // Check if there's an active failover redirect for this stream
            $failoverKey = "stream_failover_redirect:{$streamKey}";
            $redirectStreamKey = $this->redis()->get($failoverKey);

            if ($redirectStreamKey) {
                Log::channel('ffmpeg')->debug("Stream {$streamKey}: Found failover redirect to {$redirectStreamKey}");
                // Check if the redirect target is actually active 
                $redirectStreamInfo = $this->getStreamInfo($redirectStreamKey);
                if ($redirectStreamInfo && isset($redirectStreamInfo['status']) && $redirectStreamInfo['status'] === 'active') {
                    Log::channel('ffmpeg')->debug("Stream {$streamKey}: Failover redirect target is active");
                    return true;
                }
            }

            // Check if stream is marked as 'starting' which might indicate failover startup
            if ($streamInfo && isset($streamInfo['status']) && $streamInfo['status'] === 'starting') {
                $restartAttempt = $streamInfo['restart_attempt'] ?? 0;
                // Only consider it a failover if it has failover_attempts > 0 and is recent
                $hasFailoverAttempts = isset($streamInfo['failover_attempts']) && $streamInfo['failover_attempts'] > 0;
                if ($restartAttempt > 0 && (time() - $restartAttempt) < 60 && $hasFailoverAttempts) {
                    Log::channel('ffmpeg')->debug("Stream {$streamKey}: Recent failover startup detected");
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->warning("Stream {$streamKey}: Error checking failover progress: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a stream should be redirected to a failover stream
     */
    private function checkForFailoverRedirect(string $originalStreamKey, string $clientId): ?string
    {
        try {
            // First check if the original stream still exists and is active
            $originalStreamInfo = $this->getStreamInfo($originalStreamKey);
            if ($originalStreamInfo && isset($originalStreamInfo['status'])) {
                if ($originalStreamInfo['status'] === 'active') {
                    // Stream is still active, no redirect needed
                    // If this is already a failover stream, don't look for another failover
                    if (isset($originalStreamInfo['is_failover']) && $originalStreamInfo['is_failover'] === true) {
                        Log::channel('ffmpeg')->debug("Stream {$originalStreamKey}: Already on active failover stream, no further redirect needed");
                        return $originalStreamKey; // Return the current stream, don't look for alternatives
                    }
                    return $originalStreamKey;
                } elseif ($originalStreamInfo['status'] === 'failed_over' && isset($originalStreamInfo['failover_stream_key'])) {
                    // Stream has failover info in its metadata
                    $failoverStreamKey = $originalStreamInfo['failover_stream_key'];
                    Log::channel('ffmpeg')->debug("Stream {$originalStreamKey}: Found failover stream key in metadata: {$failoverStreamKey}");

                    // Verify the failover stream is active
                    $failoverStreamInfo = $this->getStreamInfo($failoverStreamKey);
                    if ($failoverStreamInfo && isset($failoverStreamInfo['status']) && $failoverStreamInfo['status'] === 'active') {
                        return $failoverStreamKey;
                    }
                } elseif ($originalStreamInfo['status'] === 'failed' || $originalStreamInfo['status'] === 'stopped') {
                    // Only look for failovers if the stream has actually failed
                    Log::channel('ffmpeg')->debug("Stream {$originalStreamKey}: Stream status is {$originalStreamInfo['status']}, looking for failover");
                } else {
                    // Stream is in some other state, don't redirect yet
                    Log::channel('ffmpeg')->debug("Stream {$originalStreamKey}: Stream status is {$originalStreamInfo['status']}, not looking for failover yet");
                    return $originalStreamKey;
                }
            }

            // Check if we have a failover mapping stored in Redis
            $failoverKey = "stream_failover_redirect:{$originalStreamKey}";
            $redirectStreamKey = $this->redis()->get($failoverKey);

            if ($redirectStreamKey) {
                // Verify the redirect stream is actually active
                $redirectStreamInfo = $this->getStreamInfo($redirectStreamKey);
                if ($redirectStreamInfo && isset($redirectStreamInfo['status']) && $redirectStreamInfo['status'] === 'active') {
                    Log::channel('ffmpeg')->debug("Stream {$originalStreamKey}: Found active failover redirect to {$redirectStreamKey} for client {$clientId}");
                    return $redirectStreamKey;
                } else {
                    // Redirect stream is not active, clean up the redirect
                    $this->redis()->del($failoverKey);
                }
            }

            // Check database for recent failover by looking for streams with same original model_id
            if ($originalStreamInfo && isset($originalStreamInfo['model_id'])) {
                $modelId = $originalStreamInfo['model_id'];

                // Look for active streams with failover indicators for this model_id
                $allStreamKeys = $this->redis()->keys(self::CACHE_PREFIX . '*');

                foreach ($allStreamKeys as $key) {
                    $key = str_replace('m3u_editor_database_', '', $key); // Remove the prefix to get the actual key

                    // Skip if this is the same stream we're checking for (prevent self-redirect)
                    if ($key === $originalStreamKey) {
                        continue;
                    }

                    $streamInfo = $this->getStreamInfo($key);
                    if (
                        $streamInfo &&
                        isset($streamInfo['is_failover']) && $streamInfo['is_failover'] === true &&
                        isset($streamInfo['primary_channel_id']) && $streamInfo['primary_channel_id'] == $modelId &&
                        isset($streamInfo['status']) && $streamInfo['status'] === 'active'
                    ) {

                        // Found an active failover stream for this channel
                        Log::channel('ffmpeg')->debug("Stream {$originalStreamKey}: Found failover stream {$key} for model {$modelId}, setting up redirect");

                        // Store the redirect for future lookups
                        $this->redis()->setex($failoverKey, 300, $key); // Cache for 5 minutes

                        return $key;
                    }
                }
            }

            // No active failover found
            return null;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->warning("Stream {$originalStreamKey}: Error checking failover redirect: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Retrieve the HLS playlist for a given stream key.
     */
    public function getHLSPlaylist(string $streamKey, string $clientId): ?array
    {
        $maxAttempts = $this->settings->hls_playlist_max_attempts ?? 10;
        $sleepSeconds = $this->settings->hls_playlist_sleep_seconds ?? 1.0;
        $m3u8Path = $this->getStreamStoragePath($streamKey) . '/playlist.m3u8';
        return [
            'max_attempts' => $maxAttempts,
            'sleep_seconds' => $sleepSeconds,
            'm3u8_path' => $m3u8Path
        ];
    }

    /**
     * Retrieve HLS segment path for a given stream key and segment name.
     */
    public function getHLSSegmentPath(string $streamKey, string $segment): ?string
    {
        return $this->getStreamStoragePath($streamKey) . "/{$segment}";
    }

    /**
     * Track bandwidth usage for HLS streaming (file-based segments)
     */
    public function trackHLSBandwidth(string $streamKey, string $clientId, int $bytesTransferred, string $segment): void
    {
        try {
            // Track bandwidth using the same method as direct streaming
            $this->trackBandwidth($streamKey, $bytesTransferred);

            // Update client and stream activity
            // This is already happening since the client is regularly fetching the playlist
            // and segments, so we don't need to explicitly update here.
            // If you want to update activity, uncomment the following lines:
            //$this->updateStreamActivity($streamKey);
            //$this->updateClientActivity($streamKey, $clientId);

            Log::channel('ffmpeg')->debug("HLS bandwidth tracked for {$streamKey}: {$bytesTransferred} bytes from client {$clientId} for segment {$segment}");
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error tracking HLS bandwidth for {$streamKey}: " . $e->getMessage());
        }
    }
}
