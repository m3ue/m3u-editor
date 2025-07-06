<?php

namespace App\Services;

use Exception;
use App\Models\Channel;
use App\Models\Episode;
use App\Exceptions\SourceNotResponding;
use App\Exceptions\FatalStreamContentException; // Added
use App\Exceptions\AllPlaylistsExhaustedException;
use App\Traits\TracksActiveStreams;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as SymfonyProcess;

class HlsStreamService
{
    use TracksActiveStreams;

    /**
     * Start an HLS stream with failover support for the given channel.
     * This method also tracks connections and performs pre-checks using ffprobe.
     *
     * @param string $type
     * @param Channel|Episode $model The Channel model instance
     * @param string $title The title of the channel
     */
    public function startStream(
        string $type,
        Channel|Episode $model, // This $model is the *original* requested channel/episode
        string $title           // This $title is the title of the *original* model
    ): ?object {
        $streamSettings = ProxyService::getStreamSettings();
        $ffprobeTimeout = $streamSettings['ffmpeg_ffprobe_timeout'] ?? 5; // Default to 5 if not set

        // Get the failover channels (if any)
        $streams = collect([$model]);

        // If type is channel, include failover channels
        if ($model instanceof Channel) {
            if ($model->is_custom && !($model->url_custom || $model->url)) {
                // If the custom channel has no URL set, log it and set $streams to the channel failovers only
                Log::channel('ffmpeg')->debug("HLS Stream: Custom channel {$model->id} ({$title}) has no URL set. Using failover channels only.");

                // Set $streams to only the failover channels if no URL is set
                $streams = $model->failoverChannels;
            } else {
                $streams = $streams->concat($model->failoverChannels);
            }
        }

        // First check if any of the streams (including failovers) are already running
        foreach ($streams as $stream) {
            if ($this->isRunning($type, $stream->id)) {
                $existingStreamTitle = $type === 'channel'
                    ? ($stream->title_custom ?? $stream->title)
                    : $stream->title;
                $existingStreamTitle = strip_tags($existingStreamTitle);

                Log::channel('ffmpeg')->debug("HLS Stream: Found existing running stream for $type ID {$stream->id} ({$existingStreamTitle}) - reusing for original request {$model->id} ({$title}).");
                return $stream; // Return the already running stream
            }
        }

        // Record timestamp in Redis for the original model (never expires until we prune)
        Redis::set("hls:{$type}_last_seen:{$model->id}", now()->timestamp);

        // Add to active IDs set for the original model
        Redis::sadd("hls:active_{$type}_ids", $model->id);

        // Keep track of playlists that have exhausted their available streams during this request
        $exhaustedPlaylistIds = [];
        $allAttemptsFailedDueToPlaylistLimits = true; // Assume true initially
        $atLeastOneAttemptHitLimit = false; // Track if we actually encountered a limit
        $numberOfAttempts = 0;

        // Loop over the failover channels and grab the first one that works.
        foreach ($streams as $stream) { // $stream is the current primary or failover channel being attempted
            $numberOfAttempts++;
            // URL for the current stream being attempted
            $currentAttemptStreamUrl = $type === 'channel'
                ? ($stream->url_custom ?? $stream->url) // Use current $stream's URL
                : $stream->url;

            // Get the title for the current stream in the loop
            $currentStreamTitle = $type === 'channel'
                ? ($stream->title_custom ?? $stream->title)
                : $stream->title;
            $currentStreamTitle = strip_tags($currentStreamTitle);

            // Check if playlist is specified for the current stream
            $playlist = $stream->getEffectivePlaylist();

            // If this stream's playlist is already marked as exhausted for this request, skip it.
            if (in_array($playlist->id, $exhaustedPlaylistIds)) {
                Log::channel('ffmpeg')->debug("HLS Stream: Playlist {$playlist->name} ({$playlist->id}) previously marked as exhausted. Skipping channel {$currentStreamTitle} ({$stream->id}).");
                continue;
            }

            // Make sure we have a valid source channel (using current stream's ID and its playlist ID)
            $badSourceCacheKey = ProxyService::BAD_SOURCE_CACHE_PREFIX . $stream->id . ':' . $playlist->id;
            if (Redis::exists($badSourceCacheKey)) {
                if ($model->id === $stream->id) {
                    Log::channel('ffmpeg')->debug("Skipping source ID {$currentStreamTitle} ({$stream->id}) for as it was recently marked as bad for playlist {$playlist->id}. Reason: " . (Redis::get($badSourceCacheKey) ?: 'N/A'));
                } else {
                    Log::channel('ffmpeg')->debug("Skipping Failover {$type} {$stream->name} for source {$model->title} ({$model->id}) as it (stream ID {$stream->id}) was recently marked as bad for playlist {$playlist->id}. Reason: " . (Redis::get($badSourceCacheKey) ?: 'N/A'));
                }
                $allAttemptsFailedDueToPlaylistLimits = false; // Failure not due to playlist limit
                continue;
            }

            // Keep track of the active streams for this playlist using optimistic locking pattern
            $activeStreams = $this->incrementActiveStreams($playlist->id);

            // Then check if we're over limit
            if ($this->wouldExceedStreamLimit($playlist->id, $playlist->available_streams, $activeStreams)) {
                // We're over limit, so decrement and skip
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->debug("HLS Stream: Max streams reached for playlist {$playlist->name} ({$playlist->id}). Skipping channel {$currentStreamTitle}. Adding playlist to exhausted list for this request.");
                // Add this playlist to the exhausted list for the current request scope
                if (!in_array($playlist->id, $exhaustedPlaylistIds)) {
                    $exhaustedPlaylistIds[] = $playlist->id;
                }
                $atLeastOneAttemptHitLimit = true; // Mark that we hit a limit
                // Do not set $allAttemptsFailedDueToPlaylistLimits to false here, as this IS a playlist limit failure
                continue;
            }

            $userAgent = $playlist->user_agent ?? null;
            try {
                // Pass the ffprobe timeout to runPreCheck
                // Pass $type and $stream->id for caching stream info
                $this->runPreCheck($type, $stream->id, $currentAttemptStreamUrl, $userAgent, $currentStreamTitle, $ffprobeTimeout);

                $this->startStreamingProcess(
                    type: $type,
                    model: $stream, // Pass the current $stream object
                    streamUrl: $currentAttemptStreamUrl, // Pass the URL of the current $stream
                    title: $currentStreamTitle, // Pass the title of the current $stream
                    playlistId: $playlist->id,
                    userAgent: $userAgent,
                );
                Log::channel('ffmpeg')->debug("Successfully started HLS stream for {$type} {$currentStreamTitle} (ID: {$stream->id}) on playlist {$playlist->id}.");
                return $stream; // Return the successful stream object

            } catch (SourceNotResponding $e) {
                // Log the error and cache the bad source
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->error("Source not responding for channel {$currentStreamTitle} (Original: {$title}): " . $e->getMessage());
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS, $e->getMessage());
                $allAttemptsFailedDueToPlaylistLimits = false; // Mark failure as not due to playlist limits
                // Try the next failover channel
                continue;
            } catch (FatalStreamContentException $e) {
                // This is our new exception for critical FFmpeg content errors
                $this->decrementActiveStreams($playlist->id); // Decrement active streams for the playlist
                Redis::srem("hls:active_{$type}_ids", $stream->id); // Remove current stream from active set
                Log::channel('ffmpeg')->error("Fatal stream content error for {$currentStreamTitle} (Original: {$title}, Stream ID: {$stream->id}): " . $e->getMessage() . ". Attempting failover.");
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS_CONTENT_ERROR ?? 10, "Fatal content error: " . $e->getMessage()); // Mark bad for a very short time
                $allAttemptsFailedDueToPlaylistLimits = false; // Mark failure as not due to playlist limits

                // If a fatal content error occurs on a stream, and we are about to try a failover,
                // touch the 'last_seen' timestamp of the *original* model. This gives the client
                // time to be redirected and start requesting segments from the failover stream
                // before the PruneStaleHlsProcesses job might consider the original model ID stale.
                if ($model->id != $stream->id) { // Check if this was an attempt on a failover stream already
                    // This logic primarily applies if the *first* attempt (original model) fails this way.
                    // If a failover stream itself has a fatal content error, the original model's last_seen
                    // would have already been updated when the first failover was initiated.
                } else {
                    // This means the original stream attempt had a fatal content error.
                    // Update its last_seen to prevent immediate pruning.
                    Redis::set("hls:{$type}_last_seen:{$model->id}", now()->timestamp);
                    Log::channel('ffmpeg')->debug("HLS Stream: Touched last_seen for original $type ID {$model->id} due to fatal content error, before attempting further failovers.");
                }
                continue;
            } catch (Exception $e) {
                // Log the error and potentially mark source as bad before trying next failover
                $this->decrementActiveStreams($playlist->id); // Ensure decrement if not already handled
                Redis::srem("hls:active_{$type}_ids", $stream->id); // Remove current stream from active set
                Log::channel('ffmpeg')->error("General error streaming channel {$currentStreamTitle} (Original: {$title}, Stream ID: {$stream->id}): " . $e->getMessage());
                Redis::setex($badSourceCacheKey, ProxyService::BAD_SOURCE_CACHE_SECONDS_GENERAL_ERROR ?? ProxyService::BAD_SOURCE_CACHE_SECONDS, "General error: " . $e->getMessage());
                $allAttemptsFailedDueToPlaylistLimits = false; // Mark failure as not due to playlist limits
                // Try the next failover channel
                continue;
            }
        }

        // If loop finishes, no stream was successfully started
        if ($numberOfAttempts > 0 && $allAttemptsFailedDueToPlaylistLimits && $atLeastOneAttemptHitLimit) {
            $errorMessage = "All stream attempts for {$type} '{$title}' (Original Model ID: {$model->id}) failed because relevant playlists reached their maximum stream limits.";
            Log::channel('ffmpeg')->warning($errorMessage);
            throw new AllPlaylistsExhaustedException($errorMessage);
        }

        Log::channel('ffmpeg')->error("No available (HLS) streams for {$type} {$title} (Original Model ID: {$model->id}) after trying all sources.");
        return null; // Return null if no stream started
    }

    /**
     * Start a stream process.
     *
     * @param string $type
     * @param Channel|Episode $model
     * @param string $streamUrl
     * @param string $title
     * @param int $playlistId
     * @param string|null $userAgent
     * 
     * @return int The FFmpeg process ID
     * @throws Exception If the stream fails
     */
    private function startStreamingProcess(
        string $type,
        Channel|Episode $model,
        string $streamUrl,
        string $title,
        int $playlistId,
        string|null $userAgent,
    ): int {
        // Define critical error signatures
        $criticalErrors = [
            'non-existing SPS 0 referenced',
            'non-existing PPS 0 referenced',
            'decode_slice_header error',
            'no frame!',
            'More data is required to decode header' // Common for QSV when headers are missing/corrupt
        ];

        // Setup the stream
        $cmd = $this->buildCmd($type, $model->id, $userAgent, $streamUrl);

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];
        $pipes = [];
        $workingDir = $type === 'episode'
            ? Storage::disk('app')->path("hls/e/{$model->id}")
            : Storage::disk('app')->path("hls/{$model->id}");

        $process = proc_open($cmd, $descriptors, $pipes, $workingDir);

        if (!is_resource($process)) {
            throw new Exception("Failed to launch FFmpeg for {$title}");
        }

        fclose($pipes[0]); // Close stdin
        fclose($pipes[1]); // Close stdout

        stream_set_blocking($pipes[2], false); // Make stderr non-blocking

        $logger = Log::channel('ffmpeg');
        $stderrPipe = $pipes[2];
        $pidCacheKey = "hls:pid:{$type}:{$model->id}";

        // Get the PID and cache it immediately
        $status = proc_get_status($process);
        $pid = $status['pid'];
        Cache::forever($pidCacheKey, $pid);

        // Store the process start time
        $startTimeCacheKey = "hls:streaminfo:starttime:{$type}:{$model->id}";
        $currentTime = now()->timestamp;
        Redis::setex($startTimeCacheKey, 604800, $currentTime); // 7 days TTL
        Log::channel('ffmpeg')->debug("Stored ffmpeg process start time for {$type} ID {$model->id} at {$currentTime}");

        // Record timestamp in Redis (never expires until we prune)
        // This key represents when the startStream method was last invoked for this model,
        // which is different from the ffmpeg process actual start time. Keep for now.
        Redis::set("hls:{$type}_last_seen:{$model->id}", now()->timestamp);
        Redis::sadd("hls:active_{$type}_ids", $model->id); // Ensure this is added early

        Log::channel('ffmpeg')->debug("Streaming {$type} {$title} (PID: {$pid}) with command: {$cmd}");

        // Active error monitoring and shutdown function
        // This combined approach ensures we try to catch errors early and still drain stderr on normal exit.
        $processStillNeedsMonitoring = true;

        register_shutdown_function(function () use ($stderrPipe, $process, $logger, &$processStillNeedsMonitoring, $pid, $pidCacheKey, $type, $model, $title) {
            if ($processStillNeedsMonitoring) { // Only run if not already handled by error detection
                $logger->debug("Shutdown function: Draining stderr for PID {$pid} ({$title})");
                while (($line = fgets($stderrPipe)) !== false) {
                    if (!empty(trim($line))) {
                        $logger->error(trim($line));
                    }
                }
            }
            // Ensure pipe is closed and process status is updated
            if (is_resource($stderrPipe)) fclose($stderrPipe);
            if (is_resource($process)) proc_close($process);
            $logger->debug("Shutdown function: Closed resources for PID {$pid} ({$title})");
        });

        // Give FFmpeg a very brief moment to start and potentially output initial errors
        usleep(500000); // 0.5 seconds

        $errorCheckStartTime = microtime(true);
        $initialErrorCheckDuration = 10; // Check for critical errors actively for X seconds

        while (microtime(true) - $errorCheckStartTime < $initialErrorCheckDuration) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $processStillNeedsMonitoring = false; // Process ended on its own
                $errorOutput = stream_get_contents($stderrPipe);
                fclose($stderrPipe);
                proc_close($process);
                Cache::forget($pidCacheKey);
                $exitCode = $status['exitcode'];
                $logger->error("FFmpeg process {$pid} for {$type} {$title} exited prematurely. Exit code: {$exitCode}. Stderr: " . trim($errorOutput));
                // Do NOT decrement here; startStream's catch block will handle it.
                throw new Exception("FFmpeg process {$pid} for {$title} exited prematurely. Exit code: {$exitCode}.");
            }

            $output = fgets($stderrPipe);
            if ($output !== false && !empty(trim($output))) {
                $trimmedOutput = trim($output);
                $logger->error($trimmedOutput); // Log all FFmpeg stderr output

                foreach ($criticalErrors as $errorSignature) {
                    if (str_contains($trimmedOutput, $errorSignature)) {
                        $processStillNeedsMonitoring = false;
                        $logger->error("Critical FFmpeg error detected for {$type} {$title} (PID: {$pid}): '{$trimmedOutput}'. Terminating process.");

                        // Terminate the process
                        posix_kill($pid, SIGTERM);
                        $attempts = 0;
                        while ($attempts < 10 && posix_kill($pid, 0)) { // Check for 1 second
                            usleep(100000); // 100ms
                            $attempts++;
                        }
                        if (posix_kill($pid, 0)) {
                            posix_kill($pid, SIGKILL);
                            $logger->warning("Force killed FFmpeg process {$pid} for {$type} {$title} due to critical error.");
                        }

                        fclose($stderrPipe);
                        proc_close($process);
                        Cache::forget($pidCacheKey);
                        // Do NOT decrement here; startStream's catch block for FatalStreamContentException will handle logging,
                        // and the main loop's structure ensures active stream counts are managed.
                        throw new FatalStreamContentException("Critical FFmpeg error for {$title}: {$trimmedOutput}");
                    }
                }
            }
            usleep(100000); // 0.1 seconds polling interval
        }

        $processStillNeedsMonitoring = false; // Active monitoring period is over. Let shutdown function handle remaining stderr.
        $logger->debug("Initial error monitoring period ended for {$type} {$title} (PID: {$pid}). FFmpeg continues in background.");

        return $pid;
    }

    /**
     * Run a pre-check using ffprobe to validate the stream.
     *
     * @param string $modelType // 'channel' or 'episode'
     * @param int|string $modelId    // ID of the channel or episode
     * @param string $streamUrl
     * @param string|null $userAgent
     * @param string $title
     * @param int $ffprobeTimeout The timeout for the ffprobe process in seconds
     * 
     * @throws Exception If the pre-check fails
     */
    private function runPreCheck(string $modelType, $modelId, $streamUrl, $userAgent, $title, int $ffprobeTimeout)
    {
        $ffprobePath = config('proxy.ffprobe_path', 'ffprobe');

        // Updated command to include -show_format and remove -select_streams to get all streams for detailed info
        $cmd = "$ffprobePath -v quiet -print_format json -show_streams -show_format -user_agent " . escapeshellarg($userAgent) . " " . escapeshellarg($streamUrl);

        Log::channel('ffmpeg')->debug("[PRE-CHECK] Executing ffprobe command for [{$title}] with timeout {$ffprobeTimeout}s: {$cmd}");
        $precheckProcess = SymfonyProcess::fromShellCommandline($cmd);
        $precheckProcess->setTimeout($ffprobeTimeout);
        try {
            $precheckProcess->run();
            if (!$precheckProcess->isSuccessful()) {
                Log::channel('ffmpeg')->error("[PRE-CHECK] ffprobe failed for source [{$title}]. Exit Code: " . $precheckProcess->getExitCode() . ". Error Output: " . $precheckProcess->getErrorOutput());
                throw new SourceNotResponding("failed_ffprobe (Exit: " . $precheckProcess->getExitCode() . ")");
            }
            Log::channel('ffmpeg')->debug("[PRE-CHECK] ffprobe successful for source [{$title}].");

            // Check channel health
            $ffprobeJsonOutput = $precheckProcess->getOutput();
            $streamInfo = json_decode($ffprobeJsonOutput, true);
            $extractedDetails = [];

            if (json_last_error() === JSON_ERROR_NONE && !empty($streamInfo)) {
                // Format Section
                if (isset($streamInfo['format'])) {
                    $format = $streamInfo['format'];
                    $extractedDetails['format'] = [
                        'duration' => $format['duration'] ?? null,
                        'size' => $format['size'] ?? null,
                        'bit_rate' => $format['bit_rate'] ?? null,
                        'nb_streams' => $format['nb_streams'] ?? null,
                        'tags' => $format['tags'] ?? [],
                    ];
                }

                $videoStreamFound = false;
                $audioStreamFound = false;

                if (isset($streamInfo['streams']) && is_array($streamInfo['streams'])) {
                    foreach ($streamInfo['streams'] as $stream) {
                        if (!$videoStreamFound && isset($stream['codec_type']) && $stream['codec_type'] === 'video') {
                            $extractedDetails['video'] = [
                                'codec_long_name' => $stream['codec_long_name'] ?? null,
                                'width' => $stream['width'] ?? null,
                                'height' => $stream['height'] ?? null,
                                'color_range' => $stream['color_range'] ?? null,
                                'color_space' => $stream['color_space'] ?? null,
                                'color_transfer' => $stream['color_transfer'] ?? null,
                                'color_primaries' => $stream['color_primaries'] ?? null,
                                'tags' => $stream['tags'] ?? [],
                            ];
                            $logResolution = ($stream['width'] ?? 'N/A') . 'x' . ($stream['height'] ?? 'N/A');
                            Log::channel('ffmpeg')->debug(
                                "[PRE-CHECK] Source [{$title}] video stream: " .
                                    "Codec: " . ($stream['codec_name'] ?? 'N/A') . ", " .
                                    "Format: " . ($stream['pix_fmt'] ?? 'N/A') . ", " .
                                    "Resolution: " . $logResolution . ", " .
                                    "Profile: " . ($stream['profile'] ?? 'N/A') . ", " .
                                    "Level: " . ($stream['level'] ?? 'N/A')
                            );
                            $videoStreamFound = true;
                        } elseif (!$audioStreamFound && isset($stream['codec_type']) && $stream['codec_type'] === 'audio') {
                            $extractedDetails['audio'] = [
                                'codec_name' => $stream['codec_name'] ?? null,
                                'profile' => $stream['profile'] ?? null,
                                'channels' => $stream['channels'] ?? null,
                                'channel_layout' => $stream['channel_layout'] ?? null,
                                'tags' => $stream['tags'] ?? [],
                            ];
                            $audioStreamFound = true;
                        }
                        if ($videoStreamFound && $audioStreamFound) {
                            break;
                        }
                    }
                }
                if (!empty($extractedDetails)) {
                    $detailsCacheKey = "hls:streaminfo:details:{$modelType}:{$modelId}";
                    Redis::setex($detailsCacheKey, 86400, json_encode($extractedDetails)); // Cache for 24 hours
                    Log::channel('ffmpeg')->debug("[PRE-CHECK] Cached detailed streaminfo for {$modelType} ID {$modelId}.");
                }
            } else {
                Log::channel('ffmpeg')->warning("[PRE-CHECK] Could not decode ffprobe JSON output for [{$title}]. Output: " . $ffprobeJsonOutput);
            }
        } catch (Exception $e) {
            throw new SourceNotResponding("failed_ffprobe_exception (" . $e->getMessage() . ")");
        }
    }

    /**
     * Stop FFmpeg for the given HLS stream channel (if currently running).
     *
     * @param string $type
     * @param string $id
     * @param string|null $reason Optional reason for stopping the stream
     * @return bool
     */
    public function stopStream($type, $id, $reason = null): bool
    {
        $logReason = $reason ? " (Reason: {$reason})" : "";
        Log::channel('ffmpeg')->info("Attempting to stop HLS stream for {$type} ID {$id}{$logReason}");

        $cacheKey = "hls:pid:{$type}:{$id}";
        $pid = Cache::get($cacheKey);
        $wasRunning = false;

        // Get the model to access playlist for stream count decrementing
        $model = null;
        if ($type === 'channel') {
            $model = Channel::find($id);
        } elseif ($type === 'episode') {
            $model = Episode::find($id);
        }

        if ($this->isRunning($type, $id)) {
            $wasRunning = true;

            // Give process time to cleanup gracefully
            posix_kill($pid, SIGTERM);
            $attempts = 0;
            while ($attempts < 30 && posix_kill($pid, 0)) {
                usleep(100000); // 100ms
                $attempts++;
            }

            // Force kill if still running
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGKILL);
                Log::channel('ffmpeg')->warning("Force killed FFmpeg process {$pid} for {$type} {$id}");
            }
            Cache::forget($cacheKey);
        } else {
            Log::channel('ffmpeg')->warning("No running FFmpeg process for channel {$id} to stop.");
        }

        // Remove from active IDs set
        Redis::srem("hls:active_{$type}_ids", $id);
        Redis::del("hls:streaminfo:starttime:{$type}:{$id}");
        Redis::del("hls:streaminfo:details:{$type}:{$id}");

        // Cleanup on-disk HLS files
        if ($type === 'episode') {
            $storageDir = Storage::disk('app')->path("hls/e/{$id}");
        } else {
            $storageDir = Storage::disk('app')->path("hls/{$id}");
        }
        File::deleteDirectory($storageDir);

        // Decrement active streams count only if a process was actually running
        // and we have the model and playlist
        if ($wasRunning && $model) {
            $playlist = $model->getEffectivePlaylist();
            if ($playlist) {
                $this->decrementActiveStreams($playlist->id);
                Log::channel('ffmpeg')->debug("Decremented active stream count for playlist {$playlist->id} as stream {$type} ID {$id} was running and is now stopped.");
            }
        } elseif (!$wasRunning && $model && $model->getEffectivePlaylist()) {
            Log::channel('ffmpeg')->debug("Did NOT decrement active stream count for playlist {$model->getEffectivePlaylist()->id} as stream {$type} ID {$id} was not running when stop was attempted.");
        }

        // Clean up any stream mappings that point to this stopped stream
        $mappingPattern = "hls:stream_mapping:{$type}:*";
        $mappingKeys = Redis::keys($mappingPattern);
        foreach ($mappingKeys as $key) {
            if (Cache::get($key) == $id) {
                Cache::forget($key);
                Log::channel('ffmpeg')->debug("Cleaned up stream mapping: {$key} -> {$id}");
            }
        }
        Log::channel('ffmpeg')->debug("Cleaned up stream resources for {$type} {$id}");

        return $wasRunning;
    }

    /**
     * Check if an HLS stream is currently running for the given channel ID.
     *
     * @param string $type
     * @param string $id
     * @return bool
     */
    public function isRunning($type, $id): bool
    {
        $cacheKey = "hls:pid:{$type}:{$id}";
        $pid = Cache::get($cacheKey);
        return $pid && posix_kill($pid, 0) && $this->isFfmpeg($pid);
    }

    /**
     * Get the PID of the currently running HLS stream for the given channel ID.
     *
     * @param string $type
     * @param string $id
     * @return bool
     */
    public function getPid($type, $id): ?int
    {
        $cacheKey = "hls:pid:{$type}:{$id}";
        return Cache::get($cacheKey);
    }

    /**
     * Return true if $pid is alive and matches an ffmpeg command.
     */
    protected function isFfmpeg(int $pid): bool
    {
        // On Linux systems
        if (PHP_OS_FAMILY === 'Linux' && file_exists("/proc/{$pid}/cmdline")) {
            $cmdline = file_get_contents("/proc/{$pid}/cmdline");
            return $cmdline && (strpos($cmdline, 'ffmpeg') !== false);
        }

        // On macOS/BSD systems
        if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD') {
            $output = [];
            exec("ps -p {$pid} -o command=", $output);
            return !empty($output) && strpos($output[0], 'ffmpeg') !== false;
        }

        // Default fallback (just check if process exists)
        return posix_kill($pid, 0);
    }

    /**
     * Build the FFmpeg command for HLS streaming.
     *
     * @param string $type
     * @param string $id
     * @param string|null $userAgent
     * @param string $streamUrl
     * 
     * @return string The complete FFmpeg command
     */
    private function buildCmd(
        $type,
        $id,
        $userAgent,
        $streamUrl
    ): string {
        // Get default stream settings
        $settings = ProxyService::getStreamSettings();
        $customCommandTemplate = $settings['ffmpeg_custom_command_template'] ?? null;

        // Setup the stream file paths
        if ($type === 'episode') {
            $storageDir = Storage::disk('app')->path("hls/e/{$id}");
        } else {
            $storageDir = Storage::disk('app')->path("hls/{$id}");
        }
        File::ensureDirectoryExists($storageDir, 0755);

        // Setup the stream URL
        $m3uPlaylist = "{$storageDir}/stream.m3u8";
        $segment = "{$storageDir}/segment_%03d.ts";

        // Construct segmentBaseUrl based on proxy_url_override
        $proxyOverrideUrl = config('proxy.url_override');
        if (!empty($proxyOverrideUrl)) {
            $parsedUrl = parse_url($proxyOverrideUrl);
            $scheme = $parsedUrl['scheme'] ?? 'http';
            $host = $parsedUrl['host'];
            $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
            $base = "{$scheme}://{$host}{$port}";
            $path = $type === 'channel' ? "/api/stream/{$id}/" : "/api/stream/e/{$id}/";
            $segmentBaseUrl = $base . $path;
        } else {
            $segmentBaseUrl = $type === 'channel' ? url("/api/stream/{$id}") . '/' : url("/api/stream/e/{$id}") . '/';
        }

        // Get ffmpeg path
        $ffmpegPath = config('proxy.ffmpeg_path') ?: $settings['ffmpeg_path'];
        if (empty($ffmpegPath)) {
            $ffmpegPath = 'jellyfin-ffmpeg';
        }

        // Determine the effective video codec based on config and settings
        $finalVideoCodec = ProxyService::determineVideoCodec(
            config('proxy.ffmpeg_codec_video', null),
            $settings['ffmpeg_codec_video'] ?? 'copy' // Default to 'copy' if not set
        );

        // Initialize Hardware Acceleration and Codec Specific Argument Variables
        $hwaccelInitArgs = '';    // For -init_hw_device
        $hwaccelInputArgs = '';   // For -hwaccel options before input (e.g., -hwaccel vaapi -hwaccel_output_format vaapi)
        $videoFilterArgs = '';    // For -vf
        $codecSpecificArgs = '';  // For encoder options like -profile:v, -preset, etc.
        $outputVideoCodec = $finalVideoCodec; // This might be overridden by hw accel logic

        // Get user defined options
        $userArgs = config('proxy.ffmpeg_additional_args', '');
        if (!empty($userArgs)) {
            $userArgs .= ' ';
        }

        // Command construction logic
        if (empty($customCommandTemplate)) {
            // VA-API Settings from GeneralSettings
            $vaapiEnabled = (($settings['hardware_acceleration_method'] ?? 'none') === 'vaapi');
            $vaapiDevice = escapeshellarg($settings['ffmpeg_vaapi_device'] ?? '/dev/dri/renderD128');
            $vaapiFilterFromSettings = $settings['ffmpeg_vaapi_video_filter'] ?? '';

            // QSV Settings from GeneralSettings
            $qsvEnabled = (($settings['hardware_acceleration_method'] ?? 'none') === 'qsv');
            $qsvDevice = escapeshellarg($settings['ffmpeg_qsv_device'] ?? '/dev/dri/renderD128');
            $qsvFilterFromSettings = $settings['ffmpeg_qsv_video_filter'] ?? '';
            $qsvEncoderOptions = $settings['ffmpeg_qsv_encoder_options'] ?? null;
            $qsvAdditionalArgs = $settings['ffmpeg_qsv_additional_args'] ?? null;

            $isVaapiCodec = str_contains($finalVideoCodec, '_vaapi');
            $isQsvCodec = str_contains($finalVideoCodec, '_qsv');

            if ($vaapiEnabled || $isVaapiCodec) {
                $outputVideoCodec = $isVaapiCodec ? $finalVideoCodec : 'h264_vaapi'; // Default to h264_vaapi if only toggle is on

                $hwaccelInitArgs = "-init_hw_device vaapi=va_device:{$vaapiDevice} -filter_hw_device va_device ";

                // These args are for full hardware acceleration (decode using VA-API)
                $hwaccelInputArgs = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi ";

                if (!empty($vaapiFilterFromSettings)) {
                    $videoFilterArgs = "-vf '" . trim($vaapiFilterFromSettings, "'") . "' ";
                } else {
                    $videoFilterArgs = ""; // No default -vf filter
                }
                // If $vaapiFilterFromSettings is empty, no -vf is added here for VA-API.
                // FFmpeg will handle conversions if possible, or fail if direct path isn't supported.

            } elseif ($qsvEnabled || $isQsvCodec) {
                // Only apply QSV if VA-API wasn't chosen/enabled
                $outputVideoCodec = $isQsvCodec ? $finalVideoCodec : 'h264_qsv'; // Default to h264_qsv
                $qsvDeviceName = 'qsv_hw'; // Internal FFmpeg label

                $hwaccelInitArgs = "-init_hw_device qsv={$qsvDeviceName}:{$qsvDevice} ";
                // These args are for full hardware acceleration (decode using QSV)
                $hwaccelInputArgs = "-hwaccel qsv -hwaccel_device {$qsvDeviceName} -hwaccel_output_format qsv ";

                if (!empty($qsvFilterFromSettings)) {
                    // This filter is applied to frames already in QSV format
                    $videoFilterArgs = "-vf '" . trim($qsvFilterFromSettings, "'") . "' ";
                } else {
                    // Add default QSV video filter for HLS if not set by user
                    $videoFilterArgs = "-vf 'hwupload=extra_hw_frames=64,scale_qsv=format=nv12' ";
                }
                if (!empty($qsvEncoderOptions)) { // $qsvEncoderOptions = $settings['ffmpeg_qsv_encoder_options']
                    $codecSpecificArgs = trim($qsvEncoderOptions) . " ";
                } else {
                    // Default QSV encoder options
                    $codecSpecificArgs = "-preset medium ";
                    // Only add -global_quality if NOT using libopus
                    if ($audioCodec !== 'libopus') { // $audioCodec here is the one determined at the top of buildCmd
                        $codecSpecificArgs .= "-global_quality 23 ";
                    }
                }
                if (!empty($qsvAdditionalArgs)) {
                    $userArgs = trim($qsvAdditionalArgs) . ($userArgs ? " " . $userArgs : "");
                }
            }
            // If neither VA-API nor QSV is applicable, $outputVideoCodec uses $finalVideoCodec (e.g. libx264 or copy)
            // and $hwaccelInitArgs, $hwaccelInputArgs, $videoFilterArgs remain empty from hw accel logic.

            // Get ffmpeg output codec formats
            $audioCodec = config('proxy.ffmpeg_codec_audio') ?: $settings['ffmpeg_codec_audio']; // This is the target codec
            $subtitleCodec = config('proxy.ffmpeg_codec_subtitles') ?: $settings['ffmpeg_codec_subtitles'];
            $audioParams = '';

            if ($audioCodec === 'opus') {
                $audioCodec = 'libopus'; // Ensure we use libopus
                Log::channel('ffmpeg')->debug("HLS: Switched audio codec from 'opus' to 'libopus'.");
            }

            if ($audioCodec === 'libopus' && $audioCodec !== 'copy') {
                $audioParams = " -vbr 1";
                // Check if user already specified a bitrate in global args, if not, add default
                if (strpos($userArgs, '-b:a') === false) {
                     $audioParams .= " -b:a 128k";
                }
                Log::channel('ffmpeg')->debug("HLS: Setting VBR and bitrate for libopus. Params: {$audioParams}");
            } elseif (($audioCodec === 'vorbis' || $audioCodec === 'libvorbis') && $audioCodec !== 'copy') {
                $audioParams = ' -strict -2';
                Log::channel('ffmpeg')->debug("HLS: Setting -strict -2 for vorbis.");
            }


            // Start building ffmpeg output codec formats
            $outputFormat = "-c:v {$outputVideoCodec} " .
                ($codecSpecificArgs ? trim($codecSpecificArgs) . " " : "");

            // Conditionally add audio codec
            if (!empty($audioCodec)) {
                $outputFormat .= "-c:a {$audioCodec}{$audioParams} ";
            }

            // Conditionally add subtitle codec
            if (!empty($subtitleCodec)) {
                $outputFormat .= "-c:s {$subtitleCodec} ";
            }
            $outputFormat = trim($outputFormat); // Trim trailing space

            // Reconstruct FFmpeg Command (ensure $ffmpegPath is escaped if it can contain spaces, though unlikely for a binary name)
            $cmd = escapeshellcmd($ffmpegPath) . ' ';
            $cmd .= $hwaccelInitArgs;  // e.g., -init_hw_device (goes before input options that use it, but after global options)
            $cmd .= $hwaccelInputArgs; // e.g., -hwaccel vaapi (these must go BEFORE the -i input)

            // Low-latency flags for better HLS performance
            $cmd .= '-fflags nobuffer+igndts -flags low_delay -avoid_negative_ts disabled ';

            // Input analysis optimization for faster stream start
            $cmd .= '-analyzeduration 1M -probesize 1M -max_delay 500000 -fpsprobesize 0 ';

            // Better error handling
            $cmd .= '-err_detect ignore_err -ignore_unknown ';

            // Use the user agent from settings, escape it. $userAgent parameter is ignored for now.
            $effectiveUserAgent = $userAgent ?: $settings['ffmpeg_user_agent'];
            $cmd .= "-user_agent " . escapeshellarg($effectiveUserAgent) . " -referer \"MyComputer\" " .
                '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                '-reconnect_on_http_error 5xx,4xx,509 -reconnect_streamed 1 ' .
                '-reconnect_delay_max 2 -noautorotate ';

            $cmd .= $userArgs; // User-defined global args from config/proxy.php or QSV additional args
            $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';
            $cmd .= $videoFilterArgs; // e.g., -vf 'scale_vaapi=format=nv12' or -vf 'vpp_qsv=format=nv12'

            $cmd .= $outputFormat . ' ';
            $cmd .= '-vsync cfr '; // Add the vsync flag here
        } else {
            // Custom command template is provided
            $cmd = $customCommandTemplate;

            // Prepare placeholder values
            $hwaccelInitArgsValue = '';
            $hwaccelArgsValue = '';
            $videoFilterArgsValue = '';

            // QSV options
            $qsvEncoderOptionsValue = $settings['ffmpeg_qsv_encoder_options'] ? escapeshellarg($settings['ffmpeg_qsv_encoder_options']) : '';
            $qsvAdditionalArgsValue = $settings['ffmpeg_qsv_additional_args'] ? escapeshellarg($settings['ffmpeg_qsv_additional_args']) : '';

            // Determine codec type
            $isVaapiCodec = str_contains($finalVideoCodec, '_vaapi');
            $isQsvCodec = str_contains($finalVideoCodec, '_qsv');

            if ($settings['ffmpeg_vaapi_enabled'] ?? false) {
                $finalVideoCodec = $isVaapiCodec ? $finalVideoCodec : 'h264_vaapi'; // Default to h264_vaapi if not already set
                if (!empty($settings['ffmpeg_vaapi_device'])) {
                    $hwaccelInitArgsValue = "-init_hw_device vaapi=va_device:" . escapeshellarg($settings['ffmpeg_vaapi_device']) . " -filter_hw_device va_device ";
                    $hwaccelArgsValue = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi ";
                }
                if (!empty($settings['ffmpeg_vaapi_video_filter'])) {
                    $videoFilterArgsValue = "-vf " . escapeshellarg(trim($settings['ffmpeg_vaapi_video_filter'], "'\",")) . " ";
                }
            } else if ($settings['ffmpeg_qsv_enabled'] ?? false) {
                $finalVideoCodec = $isQsvCodec ? $finalVideoCodec : 'h264_qsv'; // Default to h264_qsv if not already set
                if (!empty($settings['ffmpeg_qsv_device'])) {
                    $hwaccelInitArgsValue = "-init_hw_device qsv=qsv_hw:" . escapeshellarg($settings['ffmpeg_qsv_device']) . " ";
                    $hwaccelArgsValue = '-hwaccel qsv -hwaccel_device qsv_hw -hwaccel_output_format qsv ';
                }
                if (!empty($settings['ffmpeg_qsv_video_filter'])) {
                    $videoFilterArgsValue = "-vf " . escapeshellarg(trim($settings['ffmpeg_qsv_video_filter'], "'\",")) . " ";
                }

                // Additional QSV specific options
                $codecSpecificArgs = $settings['ffmpeg_qsv_encoder_options'] ? escapeshellarg($settings['ffmpeg_qsv_encoder_options']) : '';
                if (!empty($settings['ffmpeg_qsv_additional_args'])) {
                    $userArgs = trim($settings['ffmpeg_qsv_additional_args']) . ($userArgs ? " " . $userArgs : "");
                }
            }

            $videoCodecForTemplate = $settings['ffmpeg_codec_video'] ?: 'copy';
            $audioCodecForTemplate = $settings['ffmpeg_codec_audio'] ?: 'copy';
            $subtitleCodecForTemplate = $settings['ffmpeg_codec_subtitles'] ?: 'copy';
            $audioParamsForTemplate = '';

            if ($audioCodecForTemplate === 'opus') {
                $audioCodecForTemplate = 'libopus';
                Log::channel('ffmpeg')->debug("HLS: Switched audio codec (template) from 'opus' to 'libopus'.");
            }

            if ($audioCodecForTemplate === 'libopus' && $audioCodecForTemplate !== 'copy') {
                $audioParamsForTemplate = ' -vbr 1 -b:a 128k'; // Ensure -vbr 1 comes before -b:a
                Log::channel('ffmpeg')->debug("HLS: Setting default VBR and bitrate (template) for libopus: 1, 128k.");
                // If QSV is enabled and we're using libopus, ensure QSV_ENCODER_OPTIONS doesn't add -global_quality
                if ($settings['ffmpeg_qsv_enabled'] ?? false) {
                    if (empty($settings['ffmpeg_qsv_encoder_options'])) { // Only override if user hasn't set their own
                        $qsvEncoderOptionsValue = '-preset medium'; // Remove global_quality
                    }
                }
            } elseif (($audioCodecForTemplate === 'vorbis' || $audioCodecForTemplate === 'libvorbis') && $audioCodecForTemplate !== 'copy') {
                $audioParamsForTemplate = ' -strict -2';
                Log::channel('ffmpeg')->debug("HLS: Setting -strict -2 (template) for vorbis.");
            }


            $outputCommandSegment = "-c:v {$outputVideoCodec} " .
                ($codecSpecificArgs ? trim($codecSpecificArgs) . " " : "") .
                "-c:a {$audioCodecForTemplate}{$audioParamsForTemplate} -c:s {$subtitleCodecForTemplate}";

            $videoCodecArgs = "-c:v {$videoCodecForTemplate}" . ($codecSpecificArgs ? " " . trim($codecSpecificArgs) : "");
            $audioCodecArgs = "-c:a {$audioCodecForTemplate}{$audioParamsForTemplate}";
            $subtitleCodecArgs = "-c:s {$subtitleCodecForTemplate}";

            // Perform replacements
            $cmd = str_replace('{FFMPEG_PATH}', escapeshellcmd($ffmpegPath), $cmd);
            $cmd = str_replace('{INPUT_URL}', escapeshellarg($streamUrl), $cmd);
            $cmd = str_replace('{OUTPUT_OPTIONS}', $outputCommandSegment, $cmd);
            $cmd = str_replace('{USER_AGENT}', $userAgent, $cmd); // $userAgent is already escaped
            $cmd = str_replace('{REFERER}', escapeshellarg("MyComputer"), $cmd);
            $cmd = str_replace('{HWACCEL_INIT_ARGS}', $hwaccelInitArgsValue, $cmd);
            $cmd = str_replace('{HWACCEL_ARGS}', $hwaccelArgsValue, $cmd);
            $cmd = str_replace('{VIDEO_FILTER_ARGS}', $videoFilterArgsValue, $cmd);
            $cmd = str_replace('{VIDEO_CODEC_ARGS}', $videoCodecArgs, $cmd);
            $cmd = str_replace('{AUDIO_CODEC_ARGS}', $audioCodecArgs, $cmd);
            $cmd = str_replace('{SUBTITLE_CODEC_ARGS}', $subtitleCodecArgs, $cmd);
            $cmd = str_replace('{QSV_ENCODER_OPTIONS}', $qsvEncoderOptionsValue, $cmd);
            $cmd = str_replace('{QSV_ADDITIONAL_ARGS}', $qsvAdditionalArgsValue, $cmd);
            $cmd = str_replace('{ADDITIONAL_ARGS}', $userArgs, $cmd); // If user wants to include general additional args
        }

        // Get HLS time from settings or use default
        $hlsTime = $settings['ffmpeg_hls_time'] ?? 4;
        $hlsListSize = 15; // Kept as a variable for future configurability

        // ... rest of the options and command suffix ...
        $cmd .= " -f hls -hls_time {$hlsTime} -hls_list_size {$hlsListSize} " .
            '-hls_flags delete_segments+append_list+split_by_time ' .
            '-use_wallclock_as_timestamps 1 -start_number 0 ' .
            '-hls_allow_cache 0 -hls_segment_type mpegts ' .
            '-hls_segment_filename ' . escapeshellarg($segment) . ' ' .
            '-hls_base_url ' . escapeshellarg($segmentBaseUrl) . ' ' .
            escapeshellarg($m3uPlaylist) . ' ';

        $cmd .= ($settings['ffmpeg_debug'] ? ' -loglevel verbose' : ' -hide_banner -nostats -loglevel error');

        return $cmd;
    }
}
