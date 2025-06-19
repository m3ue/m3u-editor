<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\SharedStream;
use App\Models\SharedStreamClient;
use App\Traits\TracksActiveStreams;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as SymfonyProcess;

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
    const CLIENT_PREFIX = 'stream_clients:';
    const BUFFER_PREFIX = 'stream_buffer:';
    const STREAM_PREFIX = 'shared_stream:'; // For compatibility with existing code
    const SEGMENT_EXPIRY = 300; // 5 minutes
    const CLIENT_TIMEOUT = 30; // 30 seconds

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
        array $options = []
    ): array {
        $streamKey = $this->getStreamKey($type, $modelId, $streamUrl);
        $streamInfo = $this->getStreamInfo($streamKey);

        if (!$streamInfo || !$this->isStreamActive($streamKey)) {
            // Create new shared stream first
            $streamInfo = $this->createSharedStreamInternal(
                $streamKey,
                $type,
                $modelId,
                $streamUrl,
                $title,
                $format,
                $options
            );
            $isNewStream = true;
        } else {
            // Join existing stream
            Log::channel('ffmpeg')->debug("Client {$clientId} joining existing shared stream {$streamKey}");
            $this->incrementClientCount($streamKey);
            $isNewStream = false;
        }

        // Register this client for the stream AFTER ensuring the stream exists
        $this->registerClient($streamKey, $clientId, $options);

        // Add metadata about whether this was a new stream
        $streamInfo['is_new_stream'] = $isNewStream;
        
        return $streamInfo;
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
            'last_activity' => now()->timestamp,
            'options' => $options
        ];

        // Store stream info in Redis
        $this->setStreamInfo($streamKey, $streamInfo);

        // Also create database record for persistent tracking
        SharedStream::firstOrCreate(
            ['stream_id' => $streamKey],
            [
                'source_url' => $streamUrl,
                'format' => $format,
                'status' => 'starting',
                'stream_info' => json_encode($streamInfo),
                'started_at' => now()
            ]
        );

        // Start the streaming process directly (temporary fix for queue issues)
        try {
            Log::channel('ffmpeg')->debug("Starting stream process inline for {$streamKey}");
            $this->startStreamingProcess($streamKey, $streamInfo);
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Failed to start stream process for {$streamKey}: " . $e->getMessage());
            throw $e;
        }

        Log::channel('ffmpeg')->debug("Created new shared stream {$streamKey} for {$type} {$title}");

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
     * Start the actual streaming process (FFmpeg) - Async version for background jobs
     */
    public function startStreamingProcessAsync(string $streamKey, array $streamInfo): void
    {
        $format = $streamInfo['format'];
        $streamUrl = $streamInfo['stream_url'];
        $title = $streamInfo['title'];

        try {
            Log::channel('ffmpeg')->info("Starting async streaming process for {$streamKey} ({$title})");
            
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
            
            Log::channel('ffmpeg')->info("Successfully started async streaming process for {$streamKey} - waiting for data");
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Failed to start async streaming process for {$streamKey}: " . $e->getMessage());
            
            // Update stream status to error
            $streamInfo['status'] = 'error';
            $streamInfo['error_message'] = $e->getMessage();
            $this->setStreamInfo($streamKey, $streamInfo);
            
            // Update database status
            SharedStream::where('stream_id', $streamKey)->update([
                'status' => 'error',
                'error_message' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Start HLS streaming with segment buffering
     */
    private function startHLSStream(string $streamKey, array $streamInfo): void
    {
        $storageDir = $this->getStreamStorageDir($streamKey);
        Storage::makeDirectory($storageDir);

        $settings = ProxyService::getStreamSettings();
        $ffmpegPath = $settings['ffmpeg_path'] ?? 'jellyfin-ffmpeg';
        $userAgent = $settings['ffmpeg_user_agent'] ?? 'VLC/3.0.21';

        // Build FFmpeg command for HLS output
        $cmd = $this->buildHLSCommand($ffmpegPath, $streamInfo, $storageDir, $userAgent);

        // Use proc_open approach like HlsStreamService for consistency
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        
        $pipes = [];
        $absoluteStorageDir = Storage::path($storageDir);
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

        Log::channel('ffmpeg')->info("Started HLS shared stream {$streamKey} with PID {$pid}");
        Log::channel('ffmpeg')->debug("HLS Command: {$cmd}");
    }

    /**
     * Start direct streaming with buffering (similar to MPTS)
     */
    private function startDirectStream(string $streamKey, array $streamInfo): void
    {
        $settings = ProxyService::getStreamSettings();
        $ffmpegPath = $settings['ffmpeg_path'] ?? 'jellyfin-ffmpeg';
        $userAgent = $settings['ffmpeg_user_agent'] ?? 'VLC/3.0.21';

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

        // Store process info in stream data
        $streamInfo['pid'] = $pid;
        $this->setStreamInfo($streamKey, $streamInfo);

        // Start continuous buffer management for direct streaming
        $this->startContinuousBuffering($streamKey, $pipes[1], $pipes[2], $process);

        Log::channel('ffmpeg')->info("Started direct shared stream {$streamKey} with PID {$pid}");
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
        $pidKey = "stream_pid:{$streamKey}";
        return $this->redis()->get($pidKey);
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
        
        // Use a background approach that doesn't block the main thread
        $this->runAsyncBufferManager($streamKey, $stdout, $stderr, $process);
    }



    /**
     * Manage buffering for direct streams (simplified)
     */
    private function manageDirectStreamBuffer(string $streamKey, $stdout, $stderr, $process): void
    {
        // This method is now replaced by the simplified startContinuousBuffering
        $this->startContinuousBuffering($streamKey, $stdout, $stderr, $process);
    }

    /**
     * Run buffer manager in background (simplified)
     */
    private function runBufferManagerBackground(string $streamKey, $stdout, $stderr, $process): void
    {
        // Set streams to non-blocking immediately
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);
        
        // Use a register_shutdown_function approach instead of forking
        // This prevents broken pipe issues caused by parent process file handle closure
        register_shutdown_function(function () use ($streamKey, $stdout, $stderr, $process) {
            $this->drainPipesOnShutdown($streamKey, $stdout, $stderr, $process);
        });
        
        // Start continuous buffering immediately in current process
        // This prevents the broken pipe issue by keeping the parent process active
        $this->runBufferManager($streamKey, $stdout, $stderr, $process);
        
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Buffer manager running in current process");
    }

    /**
     * Start immediate buffering to prevent FFmpeg from hanging (simplified version)
     */
    private function startImmediateBuffering(string $streamKey, $stdout, $stderr, $process): void
    {
        // This is just a rename of the existing method for clarity
        $this->startInitialBuffering($streamKey, $stdout, $stderr, $process);
    }

    /**
     * Run buffer manager directly in current process (simplified fallback)
     */
    private function runDirectBufferManager(string $streamKey, $stdout, $stderr, $process): void
    {
        // Set up a lightweight buffer reader to prevent pipe overflow
        register_shutdown_function(function () use ($streamKey, $stdout, $stderr, $process) {
            $this->drainPipesOnShutdown($streamKey, $stdout, $stderr, $process);
        });
        
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Set up direct buffer management with shutdown drain");
    }

    /**
     * Simple pipe drain on shutdown to prevent overflow
     */
    private function drainPipesOnShutdown(string $streamKey, $stdout, $stderr, $process): void
    {
        try {
            $bufferKey = self::BUFFER_PREFIX . $streamKey;
            $segmentNumber = 0;
            $maxReads = 20; // Limit reads to prevent hanging
            
            // Quickly drain stdout to prevent pipe overflow
            while ($maxReads-- > 0 && !feof($stdout)) {
                $data = fread($stdout, 188000); // ~188KB chunks
                if ($data === false || strlen($data) === 0) {
                    break;
                }
                
                // Store in Redis
                $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                $this->redis()->setex($segmentKey, self::SEGMENT_EXPIRY, $data);
                $this->redis()->lpush("{$bufferKey}:segments", $segmentNumber);
                $this->redis()->ltrim("{$bufferKey}:segments", 0, 30);
                $segmentNumber++;
            }
            
            // Drain stderr for errors
            while (!feof($stderr)) {
                $error = fread($stderr, 1024);
                if ($error !== false && !empty(trim($error))) {
                    Log::channel('ffmpeg')->error("Stream {$streamKey}: " . trim($error));
                } else {
                    break;
                }
            }
            
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Shutdown drain completed, read {$segmentNumber} segments");
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Stream {$streamKey}: Error during shutdown drain: " . $e->getMessage());
        } finally {
            // Clean up resources
            if (is_resource($stdout)) fclose($stdout);
            if (is_resource($stderr)) fclose($stderr);
            if (is_resource($process)) proc_close($process);
        }
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

        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Starting initial buffering (waiting for FFmpeg data)");

        // Wait for FFmpeg to start producing data and build initial buffer
        $accumulatedData = '';
        $accumulatedSize = 0;
        $hasFirstData = false;
        $redis = $this->redis();

        while ($waitTime < $maxWait) {
            // Try to read data in larger chunks for better performance
            $chunk = fread($stdout, $readChunkSize);
            if ($chunk !== false && strlen($chunk) > 0) {
                if (!$hasFirstData) {
                    Log::channel('ffmpeg')->info("Stream {$streamKey}: First data received! Starting initial buffer accumulation");
                    $hasFirstData = true;
                }
                
                $accumulatedData .= $chunk;
                $accumulatedSize += strlen($chunk);
                $this->updateStreamActivity($streamKey);
                
                // Create segments more aggressively for faster startup
                if ($accumulatedSize >= $targetSegmentSize || 
                    ($accumulatedSize >= 64000 && $segmentNumber < 2)) { // Smaller initial segments for faster startup
                    
                    // Use direct Redis calls for initial buffering (faster than pipeline for small operations)
                    $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                    $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
                    $redis->lpush("{$bufferKey}:segments", $segmentNumber);
                    $redis->ltrim("{$bufferKey}:segments", 0, 100);
                    
                    Log::channel('ffmpeg')->debug("Stream {$streamKey}: Initial buffer segment {$segmentNumber} buffered ({$accumulatedSize} bytes)");
                    
                    $segmentNumber++;
                    $accumulatedData = '';
                    $accumulatedSize = 0;
                    
                    // Build up fewer initial segments for faster startup
                    if ($segmentNumber >= 2) { // Reduced from 3 to 2 for faster startup
                        break;
                    }
                }
            } else {
                // No immediate data available, check for errors and wait less
                $error = fread($stderr, 1024);
                if ($error !== false && strlen($error) > 0) {
                    Log::channel('ffmpeg')->error("Stream {$streamKey} FFmpeg error during initial buffering: {$error}");
                }
                
                usleep(100000); // Reduced from 200ms to 100ms for faster startup
                $waitTime += 0.1;
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
            Log::channel('ffmpeg')->info("Stream {$streamKey}: Initial buffering completed successfully with {$segmentNumber} segments");
            
            // Update stream status to active since we have data
            $streamInfo = $this->getStreamInfo($streamKey);
            if ($streamInfo) {
                $streamInfo['status'] = 'active';
                $streamInfo['first_data_at'] = time();
                $streamInfo['initial_segments'] = $segmentNumber;
                $this->setStreamInfo($streamKey, $streamInfo);
                
                // Also update database status
                SharedStream::where('stream_id', $streamKey)->update([
                    'status' => 'active'
                ]);
                
                Log::channel('ffmpeg')->info("Stream {$streamKey}: Status updated to 'active' - stream is ready for clients");
            }
        } else {
            // Even if no initial buffering succeeded, mark stream as starting so clients can attempt to connect
            // This handles cases where FFmpeg takes time to start producing data
            Log::channel('ffmpeg')->warning("Stream {$streamKey}: No initial data received after {$maxWait}s, marking as starting for client attempts");
            
            $streamInfo = $this->getStreamInfo($streamKey);
            if ($streamInfo) {
                $streamInfo['status'] = 'starting'; // Changed from 'error' to 'starting'
                $streamInfo['warning_message'] = 'No initial data received, stream may take time to start';
                $this->setStreamInfo($streamKey, $streamInfo);
                
                // Update database status to starting instead of error
                SharedStream::where('stream_id', $streamKey)->update([
                    'status' => 'starting',
                    'error_message' => null
                ]);
                
                Log::channel('ffmpeg')->info("Stream {$streamKey}: Status updated to 'starting' - allowing client attempts despite no initial data");
            }
        }
    }

    /**
     * Run the buffer manager (reads from FFmpeg, stores in Redis for sharing)
     * Optimized for performance and VLC compatibility
     */
    private function runBufferManager(string $streamKey, $stdout, $stderr, $process): void
    {
        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $segmentNumber = 0;
        
        // Optimized buffer settings based on xTeVe approach
        $targetChunkSize = 188 * 1000; // 188KB chunks (similar to xTeVe default)
        $readChunkSize = 32768; // 32KB reads for better performance
        $maxSegmentsInMemory = 100; // Keep more segments for better client experience
        
        $lastActivity = time();
        $maxInactiveTime = 60; // 60 seconds of no data before giving up

        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Buffer manager starting with {$targetChunkSize} byte chunks, {$readChunkSize} read size");

        try {
            $accumulatedData = '';
            $accumulatedSize = 0;
            $lastFlushTime = time();
            $maxAccumulationTime = 1; // Max 1 second before force-flushing (more responsive)
            $redis = $this->redis();

            while (!feof($stdout) && $this->isStreamActive($streamKey)) {
                $hasData = false;

                // Read larger chunks for better performance
                $chunk = fread($stdout, $readChunkSize);
                if ($chunk !== false && strlen($chunk) > 0) {
                    $accumulatedData .= $chunk;
                    $accumulatedSize += strlen($chunk);
                    $hasData = true;
                    $lastActivity = time();
                    
                    // More aggressive flushing for better responsiveness
                    $shouldFlush = false;
                    
                    // Flush when we have enough data for a good chunk
                    if ($accumulatedSize >= $targetChunkSize) {
                        $shouldFlush = true;
                    }
                    // Flush if we've been accumulating for too long (prevents delays)
                    elseif (time() - $lastFlushTime >= $maxAccumulationTime) {
                        $shouldFlush = true;
                    }
                    // Flush if we have reasonable amount and no immediate data
                    elseif ($accumulatedSize >= 64000) { // 64KB minimum for reasonable chunks
                        // Check if more data is immediately available
                        stream_set_blocking($stdout, false);
                        $peek = fread($stdout, 1);
                        if ($peek === false || strlen($peek) === 0) {
                            $shouldFlush = true;
                        } else {
                            // Put the peeked byte back into accumulated data
                            $accumulatedData .= $peek;
                            $accumulatedSize += 1;
                        }
                    }
                    
                    if ($shouldFlush && $accumulatedSize > 0) {
                        // Use pipeline for better Redis performance
                        $pipeline = $redis->pipeline();
                        
                        $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                        $pipeline->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
                        $pipeline->lpush("{$bufferKey}:segments", $segmentNumber);
                        $pipeline->ltrim("{$bufferKey}:segments", 0, $maxSegmentsInMemory);
                        
                        // Update stream activity
                        $pipeline->setex("{$bufferKey}:activity", 300, time());
                        
                        $pipeline->execute();
                        
                        // Log progress less frequently to reduce overhead
                        if ($segmentNumber <= 10 || $segmentNumber % 200 === 0) {
                            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Buffered segment {$segmentNumber} ({$accumulatedSize} bytes)");
                        }
                        
                        $segmentNumber++;
                        $accumulatedData = '';
                        $accumulatedSize = 0;
                        $lastFlushTime = time();
                    }
                }

                // Check for errors from FFmpeg stderr (less frequently)
                if ($segmentNumber % 10 === 0) { // Check every 10 segments
                    $error = fread($stderr, 1024);
                    if ($error !== false && strlen($error) > 0) {
                        Log::channel('ffmpeg')->error("Stream {$streamKey} FFmpeg error: {$error}");
                    }
                }

                // Check if process is still running (less frequently)
                if ($segmentNumber % 50 === 0 || !$hasData) { // Check every 50 segments or when no data
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        // Flush any remaining accumulated data before ending
                        if ($accumulatedSize > 0) {
                            $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                            $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
                            $redis->lpush("{$bufferKey}:segments", $segmentNumber);
                            $redis->ltrim("{$bufferKey}:segments", 0, $maxSegmentsInMemory);
                            $segmentNumber++;
                            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Flushed final segment {$segmentNumber} ({$accumulatedSize} bytes)");
                        }
                        Log::channel('ffmpeg')->warning("Stream {$streamKey}: FFmpeg process terminated (exit code: {$status['exitcode']})");
                        break;
                    }
                }

                // Optimized sleep timing for better responsiveness
                if (!$hasData) {
                    if (time() - $lastActivity > $maxInactiveTime) {
                        // Flush any remaining data before ending
                        if ($accumulatedSize > 0) {
                            $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                            $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
                            $redis->lpush("{$bufferKey}:segments", $segmentNumber);
                            $redis->ltrim("{$bufferKey}:segments", 0, $maxSegmentsInMemory);
                            $segmentNumber++;
                            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Flushed timeout segment {$segmentNumber} ({$accumulatedSize} bytes)");
                        }
                        Log::channel('ffmpeg')->warning("Stream {$streamKey}: No data for {$maxInactiveTime}s, ending buffer manager");
                        break;
                    }
                    usleep(10000); // 10ms sleep when no data (faster response than before)
                } else {
                    usleep(500); // 0.5ms sleep when actively buffering (very responsive)
                }
            }
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Stream {$streamKey}: Buffer manager error: " . $e->getMessage());
        } finally {
            // Safer cleanup that doesn't cause Redis connection issues
            try {
                if (is_resource($stdout)) {
                    fclose($stdout);
                }
                if (is_resource($stderr)) {
                    fclose($stderr);
                }
                if (is_resource($process)) {
                    proc_close($process);
                }
                
                // Update database status only (avoid Redis cleanup that can cause connection errors)
                SharedStream::where('stream_id', $streamKey)->update([
                    'status' => 'stopped',
                    'stopped_at' => now()
                ]);
                
            } catch (\Exception $e) {
                Log::channel('ffmpeg')->error("Stream {$streamKey}: Error during safe cleanup: " . $e->getMessage());
            }
            
            Log::channel('ffmpeg')->info("Stream {$streamKey}: Buffer manager ended after {$segmentNumber} segments");
        }
    }

    /**
     * Run buffer manager asynchronously using xTeVe-inspired approach
     */
    private function runAsyncBufferManager(string $streamKey, $stdout, $stderr, $process): void
    {
        // IMPORTANT: Don't use pcntl_fork in web context as it causes issues with:
        // - Redis connections being shared across processes
        // - File handle inheritance problems
        // - HTTP response handling
        
        // Instead, use a more stable approach that keeps everything in the same process
        // but uses non-blocking I/O and proper resource management
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Starting buffer manager in current process (no fork)");
        
        // Run the optimized buffer manager that uses stream_select for efficient I/O
        $this->runOptimizedBufferManager($streamKey, $stdout, $stderr, $process);
    }

    /**
     * Optimized buffer manager inspired by xTeVe's approach
     */
    private function runOptimizedBufferManager(string $streamKey, $stdout, $stderr, $process): void
    {
        // Set up immediate non-blocking streams
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);
        
        // Register a shutdown function to handle cleanup safely
        register_shutdown_function(function () use ($streamKey, $stdout, $stderr, $process) {
            $this->performFinalCleanupSafe($streamKey, $stdout, $stderr, $process);
        });
        
        // Start a background buffer reader using stream_select for efficiency
        $this->startStreamSelector($streamKey, $stdout, $stderr, $process);
    }

    /**
     * Use stream_select for efficient I/O handling (xTeVe-style)
     */
    private function startStreamSelector(string $streamKey, $stdout, $stderr, $process): void
    {
        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $segmentNumber = 0;
        $accumulatedData = '';
        $accumulatedSize = 0;
        $targetChunkSize = 188 * 1000; // xTeVe-compatible chunk size
        $redis = $this->redis();
        
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Starting stream selector buffer manager");
        
        // Remove arbitrary iteration limit that was causing premature termination
        $lastActivity = time();
        $maxInactiveTime = 60; // 60 seconds of no data before giving up
        
        while ($this->isStreamActive($streamKey)) {
            $read = [$stdout, $stderr];
            $write = null;
            $except = null;
            
            // Use stream_select with short timeout for responsiveness
            $result = stream_select($read, $write, $except, 0, 100000); // 100ms timeout
            
            if ($result > 0) {
                $hasData = false;
                
                // Data available on stdout
                if (in_array($stdout, $read)) {
                    $chunk = fread($stdout, 32768);
                    if ($chunk !== false && strlen($chunk) > 0) {
                        $accumulatedData .= $chunk;
                        $accumulatedSize += strlen($chunk);
                        $hasData = true;
                        $lastActivity = time();
                        
                        // Flush when we have enough data
                        if ($accumulatedSize >= $targetChunkSize) {
                            $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                            $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
                            $redis->lpush("{$bufferKey}:segments", $segmentNumber);
                            $redis->ltrim("{$bufferKey}:segments", 0, 50);
                            
                            $segmentNumber++;
                            $accumulatedData = '';
                            $accumulatedSize = 0;
                            
                            // Update stream activity
                            $this->updateStreamActivity($streamKey);
                            
                            // Log progress for first few segments and then periodically
                            if ($segmentNumber <= 10 || $segmentNumber % 100 === 0) {
                                Log::channel('ffmpeg')->debug("Stream {$streamKey}: Buffered segment {$segmentNumber}");
                            }
                        }
                    }
                }
                
                // Handle stderr
                if (in_array($stderr, $read)) {
                    $error = fread($stderr, 1024);
                    if ($error !== false && strlen($error) > 0) {
                        Log::channel('ffmpeg')->error("Stream {$streamKey}: {$error}");
                    }
                }
                
                // Check if we have any data activity
                if ($hasData) {
                    $lastActivity = time();
                }
            }
            
            // Check if process is still running periodically
            if ($segmentNumber % 50 === 0 || (time() - $lastActivity) > 10) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    // Flush any remaining data before ending
                    if ($accumulatedSize > 0) {
                        $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                        $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
                        $redis->lpush("{$bufferKey}:segments", $segmentNumber);
                        $segmentNumber++;
                    }
                    Log::channel('ffmpeg')->info("Stream {$streamKey}: FFmpeg process ended, stopping buffer manager");
                    break;
                }
            }
            
            // Check for inactivity timeout
            if (time() - $lastActivity > $maxInactiveTime) {
                Log::channel('ffmpeg')->warning("Stream {$streamKey}: No data for {$maxInactiveTime}s, ending buffer manager");
                break;
            }
            
            // Small sleep to prevent CPU spinning
            usleep(1000); // 1ms sleep
        }
        
        // Flush any remaining data
        if ($accumulatedSize > 0) {
            $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
            $redis->setex($segmentKey, self::SEGMENT_EXPIRY, $accumulatedData);
            $redis->lpush("{$bufferKey}:segments", $segmentNumber);
            $segmentNumber++;
        }
        
        Log::channel('ffmpeg')->debug("Stream {$streamKey}: Stream selector completed with {$segmentNumber} segments");
    }

    /**
     * Perform final cleanup safely without causing Redis connection issues
     */
    private function performFinalCleanupSafe(string $streamKey, $stdout, $stderr, $process): void
    {
        try {
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Starting safe final cleanup");
            
            // Close file handles first
            if (is_resource($stdout)) {
                fclose($stdout);
            }
            if (is_resource($stderr)) {
                fclose($stderr);
            }
            if (is_resource($process)) {
                proc_close($process);
            }
            
            // Update stream status in database only (avoid Redis operations in shutdown function)
            // This prevents Redis connection issues during cleanup
            try {
                SharedStream::where('stream_id', $streamKey)->update([
                    'status' => 'stopped',
                    'stopped_at' => now()
                ]);
            } catch (\Exception $e) {
                // Don't log errors during shutdown to avoid recursive issues
            }
            
        } catch (\Exception $e) {
            // Silent handling to prevent cascading errors during shutdown
        }
    }

    /**
     * Perform final cleanup on shutdown
     */
    private function performFinalCleanup(string $streamKey, $stdout, $stderr, $process): void
    {
        try {
            // Quick drain of any remaining data
            if (is_resource($stdout)) {
                while (!feof($stdout)) {
                    $data = fread($stdout, 8192);
                    if ($data === false || strlen($data) === 0) break;
                }
                fclose($stdout);
            }
            
            if (is_resource($stderr)) {
                while (!feof($stderr)) {
                    $error = fread($stderr, 1024);
                    if ($error === false || strlen($error) === 0) break;
                    if (!empty(trim($error))) {
                        Log::channel('ffmpeg')->error("Stream {$streamKey} final: {$error}");
                    }
                }
                fclose($stderr);
            }
            
            if (is_resource($process)) {
                proc_close($process);
            }
            
            // Mark stream as stopped
            $streamInfo = $this->getStreamInfo($streamKey);
            if ($streamInfo) {
                $streamInfo['status'] = 'stopped';
                $this->setStreamInfo($streamKey, $streamInfo);
            }
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Stream {$streamKey}: Error during final cleanup: " . $e->getMessage());
        }
    }

    /**
     * Build HLS command for shared HLS streaming
     */
    private function buildHLSCommand(string $ffmpegPath, array $streamInfo, string $storageDir, string $userAgent): string
    {
        $settings = ProxyService::getStreamSettings();
        $streamUrl = $streamInfo['stream_url'];
        
        $cmd = escapeshellcmd($ffmpegPath) . ' ';
        $cmd .= '-hide_banner -loglevel error ';
        $cmd .= '-user_agent ' . escapeshellarg($userAgent) . ' ';
        $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';
        $cmd .= '-c copy -f hls ';
        $cmd .= '-hls_time 4 -hls_list_size 10 ';
        $cmd .= '-hls_flags delete_segments ';
        $cmd .= escapeshellarg($storageDir . '/playlist.m3u8');
        
        return $cmd;
    }

    /**
     * Generate stream key from type, model ID, and URL
     */
    private function getStreamKey(string $type, int $modelId, string $streamUrl): string
    {
        return self::CACHE_PREFIX . $type . ':' . $modelId . ':' . md5($streamUrl);
    }

    /**
     * Get stream info from Redis
     */
    private function getStreamInfo(string $streamKey): ?array
    {
        try {
            $data = $this->redis()->get($streamKey);
            if ($data === null || $data === false) {
                Log::channel('ffmpeg')->debug("Stream {$streamKey}: No stream info found in Redis");
                return null;
            }
            
            if (empty($data)) {
                Log::channel('ffmpeg')->warning("Stream {$streamKey}: Empty stream info data in Redis");
                return null;
            }
            
            $decoded = json_decode($data, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                Log::channel('ffmpeg')->error("Stream {$streamKey}: Failed to decode stream info JSON: " . json_last_error_msg() . " (data: " . substr($data, 0, 100) . ")");
                return null;
            }
            
            Log::channel('ffmpeg')->debug("Stream {$streamKey}: Successfully retrieved stream info from Redis (" . strlen($data) . " bytes)");
            return $decoded;
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Stream {$streamKey}: Error retrieving stream info: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Set stream info in Redis
     */
    private function setStreamInfo(string $streamKey, array $streamInfo): void
    {
        try {
            $jsonData = json_encode($streamInfo);
            if ($jsonData === false) {
                Log::channel('ffmpeg')->error("Stream {$streamKey}: Failed to encode stream info to JSON: " . json_last_error_msg());
                return;
            }
            
            $result = $this->redis()->setex($streamKey, self::SEGMENT_EXPIRY, $jsonData);
            if (!$result) {
                Log::channel('ffmpeg')->error("Stream {$streamKey}: Failed to store stream info in Redis");
            } else {
                Log::channel('ffmpeg')->debug("Stream {$streamKey}: Successfully stored stream info in Redis (" . strlen($jsonData) . " bytes)");
            }
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Stream {$streamKey}: Error storing stream info: " . $e->getMessage());
        }
    }

    /**
     * Check if stream is active
     */
    private function isStreamActive(string $streamKey): bool
    {
        $streamInfo = $this->getStreamInfo($streamKey);
        return $streamInfo && ($streamInfo['status'] === 'active' || $streamInfo['status'] === 'starting');
    }

    /**
     * Increment client count for a stream
     */
    private function incrementClientCount(string $streamKey): void
    {
        $streamInfo = $this->getStreamInfo($streamKey);
        if ($streamInfo) {
            $streamInfo['client_count'] = ($streamInfo['client_count'] ?? 0) + 1;
            $streamInfo['last_activity'] = now()->timestamp;
            $this->setStreamInfo($streamKey, $streamInfo);
        }
    }

    /**
     * Register a client for a stream
     */
    private function registerClient(string $streamKey, string $clientId, array $options = []): void
    {
        $clientKey = self::CLIENT_PREFIX . $streamKey . ':' . $clientId;
        $clientInfo = [
            'client_id' => $clientId,
            'connected_at' => now()->timestamp,
            'last_activity' => now()->timestamp,
            'options' => $options
        ];
        $this->redis()->setex($clientKey, self::CLIENT_TIMEOUT, json_encode($clientInfo));
    }

    /**
     * Get all active streams with their information and client counts
     * Called by BufferManagement job and other services
     */
    public function getAllActiveStreams(): array
    {
        try {
            $redis = $this->redis();
            $streams = [];
            
            // Get all stream keys from Redis
            $streamKeys = $redis->keys(self::CACHE_PREFIX . '*');
            
            // Ensure we have an array before foreach
            if (is_array($streamKeys)) {
                foreach ($streamKeys as $fullKey) {
                    $streamKey = str_replace(self::CACHE_PREFIX, '', $fullKey);
                    $streamInfo = $this->getStreamInfo($fullKey);
                    
                    if ($streamInfo && in_array($streamInfo['status'] ?? '', ['active', 'starting'])) {
                        // Get client count from Redis client keys
                        $clientKeys = $redis->keys(self::CLIENT_PREFIX . $fullKey . ':*');
                        $clientCount = is_array($clientKeys) ? count($clientKeys) : 0;
                        
                        // Calculate uptime
                        $uptime = (time() - ($streamInfo['created_at'] ?? time()));
                        
                        $streams[$streamKey] = [
                            'stream_info' => $streamInfo,
                            'client_count' => $clientCount,
                            'uptime' => $uptime,
                            'last_activity' => $streamInfo['last_activity'] ?? time()
                        ];
                    }
                }
            }
            
            return $streams;
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error getting all active streams: " . $e->getMessage());
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
            uasort($streamSizes, function($a, $b) {
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
     * Update stream statistics
     */
    public function updateStreamStats(string $streamKey): void
    {
        try {
            $streamInfo = $this->getStreamInfo($streamKey);
            if (!$streamInfo) {
                return;
            }
            
            // Update last activity
            $streamInfo['last_activity'] = time();
            $this->setStreamInfo($streamKey, $streamInfo);
            
            // Update database record
            SharedStream::where('stream_id', $streamKey)->update([
                'last_activity' => now(),
                'client_count' => $streamInfo['client_count'] ?? 0
            ]);
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error updating stream stats for {$streamKey}: " . $e->getMessage());
        }
    }

    /**
     * Clean up orphaned Redis keys
     */
    public function cleanupOrphanedKeys(): int
    {
        try {
            $redis = $this->redis();
            $cleaned = 0;
            
            // Get all stream keys
            $streamKeys = $redis->keys(self::CACHE_PREFIX . '*');
            $activeStreamKeys = [];
            
            foreach ($streamKeys as $key) {
                $streamInfo = $redis->get($key);
                if ($streamInfo) {
                    $data = json_decode($streamInfo, true);
                    $streamKey = str_replace(self::CACHE_PREFIX, '', $key);
                    $status = $data['status'] ?? 'UNKNOWN';
                    
                    if ($data && isset($data['status']) && in_array($data['status'], ['active', 'starting'])) {
                        $activeStreamKeys[] = $streamKey;
                        Log::channel('ffmpeg')->debug("CleanupOrphanedKeys: Keeping active stream {$streamKey} (status: {$status})");
                    } else {
                        // Remove inactive stream key
                        Log::channel('ffmpeg')->info("CleanupOrphanedKeys: Removing inactive stream {$streamKey} (status: {$status})");
                        $redis->del($key);
                        $cleaned++;
                    }
                } else {
                    $streamKey = str_replace(self::CACHE_PREFIX, '', $key);
                    Log::channel('ffmpeg')->info("CleanupOrphanedKeys: Removing empty stream key {$streamKey}");
                    $redis->del($key);
                    $cleaned++;
                }
            }
            
            // Clean up client keys for non-existent streams
            $clientKeys = $redis->keys(self::CLIENT_PREFIX . '*');
            if (is_array($clientKeys)) {
                foreach ($clientKeys as $clientKey) {
                    $streamKeyPart = str_replace(self::CLIENT_PREFIX, '', $clientKey);
                    $streamKey = explode(':', $streamKeyPart)[0];
                    
                    if (!in_array($streamKey, $activeStreamKeys)) {
                        $redis->del($clientKey);
                        $cleaned++;
                    }
                }
            }
            
            // Clean up buffer keys for non-existent streams
            $bufferKeys = $redis->keys(self::BUFFER_PREFIX . '*');
            if (is_array($bufferKeys)) {
                foreach ($bufferKeys as $bufferKey) {
                    $streamKey = str_replace(self::BUFFER_PREFIX, '', $bufferKey);
                    $streamKey = explode(':', $streamKey)[0];
                    
                    if (!in_array($streamKey, $activeStreamKeys)) {
                        $redis->del($bufferKey);
                        $cleaned++;
                    }
                }
            }
            
            return $cleaned;
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error cleaning up orphaned keys: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Remove a client from a stream
     */
    public function removeClient(string $streamKey, string $clientId): void
    {
        try {
            $clientKey = self::CLIENT_PREFIX . $streamKey . ':' . $clientId;
            $this->redis()->del($clientKey);
            
            // Decrement client count
            $streamInfo = $this->getStreamInfo($streamKey);
            if ($streamInfo) {
                $streamInfo['client_count'] = max(0, ($streamInfo['client_count'] ?? 1) - 1);
                $streamInfo['last_activity'] = time();
                $this->setStreamInfo($streamKey, $streamInfo);
                
                Log::channel('ffmpeg')->debug("Client {$clientId} removed from stream {$streamKey}. Remaining clients: {$streamInfo['client_count']}");
            }
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error removing client {$clientId} from stream {$streamKey}: " . $e->getMessage());
        }
    }

    /**
     * Stop a stream
     */
    public function stopStream(string $streamKey): bool
    {
        try {
            $streamInfo = $this->getStreamInfo($streamKey);
            if (!$streamInfo) {
                return false;
            }
            
            // Kill the process if it exists
            $pid = $streamInfo['pid'] ?? null;
            if ($pid && $this->isProcessRunning($pid)) {
                if (function_exists('posix_kill')) {
                    posix_kill($pid, SIGTERM);
                    sleep(2);
                    if ($this->isProcessRunning($pid)) {
                        posix_kill($pid, SIGKILL);
                    }
                } else {
                    exec("kill -TERM $pid");
                    sleep(2);
                    exec("kill -KILL $pid 2>/dev/null");
                }
            }
            
            // Clean up stream
            $this->cleanupStream($streamKey, true);
            
            Log::channel('ffmpeg')->info("Stream {$streamKey} stopped successfully");
            return true;
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error stopping stream {$streamKey}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Attempt to restart a stream that died but still has clients
     */
    private function attemptStreamRestart(string $streamKey, array $streamInfo): bool
    {
        try {
            Log::channel('ffmpeg')->info("Stream {$streamKey}: Attempting automatic restart");
            
            // Update stream status to starting
            $streamInfo['status'] = 'starting';
            $streamInfo['restart_attempt'] = time();
            $this->setStreamInfo($streamKey, $streamInfo);
            
            // Start the streaming process based on format
            if ($streamInfo['format'] === 'hls') {
                $this->startHLSStream($streamKey, $streamInfo);
            } else {
                $this->startDirectStream($streamKey, $streamInfo);
            }
            
            // Update database
            SharedStream::where('stream_id', $streamKey)->update([
                'status' => 'starting',
                'process_id' => $this->getProcessPid($streamKey),
                'last_activity' => now()
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Stream {$streamKey}: Failed to restart - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get stream statistics/status
     */
    public function getStreamStats(string $streamKey): ?array
    {
        try {
            // If streamKey doesn't have prefix, add it for Redis lookup
            $redisKey = str_starts_with($streamKey, self::CACHE_PREFIX) ? $streamKey : self::CACHE_PREFIX . $streamKey;
            $streamInfo = $this->getStreamInfo($redisKey);
            if (!$streamInfo) {
                Log::channel('ffmpeg')->debug("getStreamStats: No stream info found for key {$streamKey} (Redis key: {$redisKey})");
                return null;
            }
            
            // Get client count - use clean streamKey for client lookup
            $cleanStreamKey = str_starts_with($streamKey, self::CACHE_PREFIX) ? 
                str_replace(self::CACHE_PREFIX, '', $streamKey) : $streamKey;
            $clientKeys = $this->redis()->keys(self::CLIENT_PREFIX . $cleanStreamKey . ':*');
            $clientCount = count($clientKeys);
            
            // Check if process is running
            $pid = $streamInfo['pid'] ?? null;
            $isProcessRunning = $pid ? $this->isProcessRunning($pid) : false;
            
            // Determine status - be more lenient about dead processes and add restart logic
            $status = $streamInfo['status'] ?? 'unknown';
            
            // Handle dead processes more intelligently
            if (!$isProcessRunning && $status === 'active') {
                if ($clientCount === 0) {
                    // No clients connected, safe to mark as stopped
                    $status = 'stopped';
                } else {
                    // Clients are still connected - attempt to restart the stream
                    Log::channel('ffmpeg')->info("Stream {$streamKey}: Process died but {$clientCount} clients connected, attempting restart");
                    
                    // Try to restart the stream
                    $restartSuccess = $this->attemptStreamRestart($streamKey, $streamInfo);
                    
                    if ($restartSuccess) {
                        $status = 'starting';
                        Log::channel('ffmpeg')->info("Stream {$streamKey}: Successfully restarted for {$clientCount} clients");
                    } else {
                        $status = 'starting'; // Keep as starting to allow manual restart
                        Log::channel('ffmpeg')->warning("Stream {$streamKey}: Failed to restart, keeping as 'starting' for manual intervention");
                    }
                }
            }
            
            // Debug logging to track the 30-second timeout issue
            Log::channel('ffmpeg')->debug("getStreamStats for {$streamKey}: status='{$status}', pid={$pid}, processRunning=" . ($isProcessRunning ? 'true' : 'false') . ", clientCount={$clientCount}");
            
            return [
                'status' => $status,
                'client_count' => $clientCount,
                'uptime' => time() - ($streamInfo['created_at'] ?? time()),
                'last_activity' => $streamInfo['last_activity'] ?? time(),
                'process_running' => $isProcessRunning,
                'title' => $streamInfo['title'] ?? 'Unknown',
                'format' => $streamInfo['format'] ?? 'unknown'
            ];
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error getting stream stats for {$streamKey}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get next stream segments for a client
     */
    public function getNextStreamSegments(string $streamKey, string $clientId, int &$lastSegment): ?string
    {
        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $segmentNumbers = $this->redis()->lrange("{$bufferKey}:segments", 0, -1);
        
        if (empty($segmentNumbers)) {
            return null;
        }
        
        $data = '';
        foreach ($segmentNumbers as $segmentNumber) {
            if ($segmentNumber > $lastSegment) {
                $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                $segmentData = $this->redis()->get($segmentKey);
                if ($segmentData) {
                    $data .= $segmentData;
                    $lastSegment = $segmentNumber;
                }
            }
        }
        
        return !empty($data) ? $data : null;
    }

    /**
     * Synchronize stream state between Redis and database
     * Called by ManageSharedStreams command
     */
    public function synchronizeState(): void
    {
        try {
            $redis = $this->redis();
            
            // Get all active stream keys from Redis
            $streamKeys = $redis->keys(self::STREAM_PREFIX . '*');
            
            foreach ($streamKeys as $fullKey) {
                $streamKey = str_replace(self::STREAM_PREFIX, '', $fullKey);
                $streamInfo = $this->getStreamInfo($streamKey);
                
                if ($streamInfo) {
                    // Check if process is still running
                    $pid = $streamInfo['pid'] ?? null;
                    $isRunning = false;
                    
                    if ($pid) {
                        $isRunning = $this->isProcessRunning($pid);
                    }
                    
                    // Update database status based on actual process state
                    $status = $isRunning ? 'active' : 'stopped';
                    
                    SharedStream::where('stream_id', $streamKey)->update([
                        'status' => $status,
                        'last_activity' => now()
                    ]);
                    
                    // Clean up if process is dead
                    if (!$isRunning) {
                        $this->cleanupStream($streamKey, true);
                    }
                }
            }
            
            Log::channel('ffmpeg')->debug("Synchronized " . count($streamKeys) . " stream states");
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error synchronizing stream states: " . $e->getMessage());
        }
    }

    /**
     * Update stream activity timestamp
     */
    private function updateStreamActivity(string $streamKey): void
    {
        $streamInfo = $this->getStreamInfo($streamKey);
        if ($streamInfo) {
            $streamInfo['last_activity'] = time();
            $this->setStreamInfo($streamKey, $streamInfo);
        }
    }

    /**
     * Get stream storage directory
     */
    private function getStreamStorageDir(string $streamKey): string
    {
        return 'shared_streams/' . md5($streamKey);
    }

    /**
     * Set stream process PID
     */
    private function setStreamProcess(string $streamKey, int $pid): void
    {
        $pidKey = "stream_pid:{$streamKey}";
        $this->redis()->setex($pidKey, self::SEGMENT_EXPIRY, $pid);
    }

    /**
     * Clean up stream resources
     */
    private function cleanupStream(string $streamKey, bool $removeData = false): void
    {
        try {
            if ($removeData) {
                // Use a separate Redis connection for cleanup to avoid connection issues
                $redis = $this->redis();
                
                // Remove stream info from Redis
                $redis->del($streamKey);
                
                // Remove buffer data
                $bufferKey = self::BUFFER_PREFIX . $streamKey;
                $segmentNumbers = $redis->lrange("{$bufferKey}:segments", 0, -1);
                
                // Ensure we have an array before foreach
                if (is_array($segmentNumbers)) {
                    foreach ($segmentNumbers as $segmentNumber) {
                        $redis->del("{$bufferKey}:segment_{$segmentNumber}");
                    }
                }
                $redis->del("{$bufferKey}:segments");
                
                // Remove client keys
                $clientKeys = $redis->keys(self::CLIENT_PREFIX . $streamKey . ':*');
                
                // Ensure we have an array before foreach
                if (is_array($clientKeys)) {
                    foreach ($clientKeys as $clientKey) {
                        $redis->del($clientKey);
                    }
                }
                
                // Remove process PID
                $redis->del("stream_pid:{$streamKey}");
                
                Log::channel('ffmpeg')->debug("Stream {$streamKey}: Redis cleanup completed");
            }
            
            // Update database status
            SharedStream::where('stream_id', $streamKey)->update([
                'status' => 'stopped',
                'stopped_at' => now()
            ]);
            
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error("Error cleaning up stream {$streamKey}: " . $e->getMessage());
        }
    }

    /**
     * Check if a process is running by PID
     */
    private function isProcessRunning(int $pid): bool
    {
        try {
            if (function_exists('posix_kill')) {
                return posix_kill($pid, 0);
            }
            
            // Fallback for systems without posix functions
            $result = shell_exec("ps -p $pid");
            return !empty($result) && strpos($result, (string)$pid) !== false;
            
        } catch (\Exception $e) {
            return false;
        }
    }

}
