<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\SharedStream;
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
    const SEGMENT_EXPIRY = 300; // 5 minutes
    const CLIENT_TIMEOUT = 30; // 30 seconds

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

        // Register this client for the stream
        $this->registerClient($streamKey, $clientId, $options);

        if (!$streamInfo || !$this->isStreamActive($streamKey)) {
            // Create new shared stream
            $streamInfo = $this->createSharedStream(
                $streamKey,
                $type,
                $modelId,
                $streamUrl,
                $title,
                $format,
                $options
            );
        } else {
            // Join existing stream
            Log::channel('ffmpeg')->debug("Client {$clientId} joining existing shared stream {$streamKey}");
            $this->incrementClientCount($streamKey);
        }

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

        // Start the streaming process
        $this->startStreamingProcess($streamKey, $streamInfo);

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

        if ($format === 'hls') {
            $this->startHLSStream($streamKey, $streamInfo);
        } else {
            $this->startDirectStream($streamKey, $streamInfo);
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

        // Start the process
        $process = SymfonyProcess::fromShellCommandline($cmd);
        $process->setTimeout(null);
        $process->start();

        // Store process PID
        $this->setStreamProcess($streamKey, $process->getPid());

        // Monitor the stream in the background
        $this->monitorHLSStream($streamKey, $process, $storageDir);

        Log::channel('ffmpeg')->debug("Started HLS stream {$streamKey} with PID {$process->getPid()}");
    }

    /**
     * Start direct streaming with buffering
     */
    private function startDirectStream(string $streamKey, array $streamInfo): void
    {
        $settings = ProxyService::getStreamSettings();
        $ffmpegPath = $settings['ffmpeg_path'] ?? 'jellyfin-ffmpeg';
        $userAgent = $settings['ffmpeg_user_agent'] ?? 'VLC/3.0.21';

        // Build FFmpeg command for direct output
        $cmd = $this->buildDirectCommand($ffmpegPath, $streamInfo, $userAgent);

        // Start the process with buffering
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \Exception("Failed to start stream process for {$streamKey}");
        }

        // Close stdin
        fclose($pipes[0]);

        // Store process info
        $status = proc_get_status($process);
        $this->setStreamProcess($streamKey, $status['pid']);

        // Start buffer management
        $this->manageDirectStreamBuffer($streamKey, $pipes[1], $pipes[2]);

        Log::channel('ffmpeg')->debug("Started direct stream {$streamKey} with PID {$status['pid']}");
    }

    /**
     * Manage buffering for direct streams (similar to xTeVe)
     */
    private function manageDirectStreamBuffer(string $streamKey, $stdout, $stderr): void
    {
        // Make streams non-blocking
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        // Start buffer management in a separate process/thread
        $this->forkBufferManager($streamKey, $stdout, $stderr);
    }

    /**
     * Fork buffer manager process (simplified implementation)
     */
    private function forkBufferManager(string $streamKey, $stdout, $stderr): void
    {
        // In a production environment, you'd want to use a proper job queue
        // For now, we'll use a simple background process approach
        
        register_shutdown_function(function () use ($streamKey, $stdout, $stderr) {
            $this->runBufferManager($streamKey, $stdout, $stderr);
        });
    }

    /**
     * Run the buffer manager (reads from FFmpeg, stores in Redis for sharing)
     */
    private function runBufferManager(string $streamKey, $stdout, $stderr): void
    {
        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $segmentNumber = 0;
        $bufferSize = 188 * 1000; // ~188KB chunks (similar to xTeVe's approach)

        while (!feof($stdout) && $this->isStreamActive($streamKey)) {
            $data = fread($stdout, $bufferSize);
            if ($data !== false && strlen($data) > 0) {
                $segmentKey = "{$bufferKey}:segment_{$segmentNumber}";
                
                // Store segment in Redis with expiry
                Redis::setex($segmentKey, self::SEGMENT_EXPIRY, $data);
                
                // Update stream segment list
                Redis::lpush("{$bufferKey}:segments", $segmentNumber);
                Redis::ltrim("{$bufferKey}:segments", 0, 30); // Keep last 30 segments
                
                $segmentNumber++;
                
                // Update last activity
                $this->updateStreamActivity($streamKey);
            }

            // Check for errors
            $error = fread($stderr, 1024);
            if ($error !== false && strlen($error) > 0) {
                Log::channel('ffmpeg')->error("Stream {$streamKey} error: {$error}");
            }

            usleep(10000); // 10ms sleep to prevent excessive CPU usage
        }

        // Cleanup
        fclose($stdout);
        fclose($stderr);
        $this->cleanupStream($streamKey);
    }

    /**
     * Get stream data for a client
     */
    public function getStreamData(string $streamKey, string $clientId, int $fromSegment = 0): ?string
    {
        if (!$this->isClientRegistered($streamKey, $clientId)) {
            return null;
        }

        $this->updateClientActivity($streamKey, $clientId);

        $bufferKey = self::BUFFER_PREFIX . $streamKey;
        $segments = Redis::lrange("{$bufferKey}:segments", 0, -1);

        $data = '';
        foreach ($segments as $segmentNum) {
            if ($segmentNum >= $fromSegment) {
                $segmentKey = "{$bufferKey}:segment_{$segmentNum}";
                $segmentData = Redis::get($segmentKey);
                if ($segmentData) {
                    $data .= $segmentData;
                }
            }
        }

        return $data ?: null;
    }

    /**
     * Get HLS playlist for shared stream
     */
    public function getHLSPlaylist(string $streamKey, string $clientId): ?string
    {
        if (!$this->isClientRegistered($streamKey, $clientId)) {
            return null;
        }

        $this->updateClientActivity($streamKey, $clientId);

        $storageDir = $this->getStreamStorageDir($streamKey);
        $playlistPath = Storage::path("{$storageDir}/stream.m3u8");

        if (file_exists($playlistPath)) {
            return file_get_contents($playlistPath);
        }

        return null;
    }

    /**
     * Get HLS segment for shared stream
     */
    public function getHLSSegment(string $streamKey, string $clientId, string $segment): ?string
    {
        if (!$this->isClientRegistered($streamKey, $clientId)) {
            return null;
        }

        $this->updateClientActivity($streamKey, $clientId);

        $storageDir = $this->getStreamStorageDir($streamKey);
        $segmentPath = Storage::path("{$storageDir}/{$segment}");

        if (file_exists($segmentPath)) {
            return file_get_contents($segmentPath);
        }

        return null;
    }

    /**
     * Remove client from stream
     */
    public function removeClient(string $streamKey, string $clientId): void
    {
        $clientKey = self::CLIENT_PREFIX . $streamKey;
        Redis::hdel($clientKey, $clientId);
        
        $clientCount = $this->decrementClientCount($streamKey);
        
        Log::channel('ffmpeg')->debug("Removed client {$clientId} from stream {$streamKey}. Remaining clients: {$clientCount}");

        // If no clients left, cleanup stream
        if ($clientCount <= 0) {
            $this->cleanupStream($streamKey);
        }
    }

    /**
     * Build FFmpeg command for HLS streaming
     */
    private function buildHLSCommand(string $ffmpegPath, array $streamInfo, string $storageDir, string $userAgent): string
    {
        $streamUrl = $streamInfo['stream_url'];
        $absoluteStorageDir = Storage::path($storageDir);

        $cmd = escapeshellcmd($ffmpegPath) . ' ';
        $cmd .= '-hide_banner -loglevel error ';
        $cmd .= '-user_agent ' . escapeshellarg($userAgent) . ' ';
        $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';
        $cmd .= '-c:v copy -c:a copy ';
        $cmd .= '-f hls -hls_time 4 -hls_list_size 15 ';
        $cmd .= '-hls_segment_filename ' . escapeshellarg($absoluteStorageDir . '/segment_%03d.ts') . ' ';
        $cmd .= escapeshellarg($absoluteStorageDir . '/stream.m3u8');

        return $cmd;
    }

    /**
     * Build FFmpeg command for direct streaming
     */
    private function buildDirectCommand(string $ffmpegPath, array $streamInfo, string $userAgent): string
    {
        $streamUrl = $streamInfo['stream_url'];

        $cmd = escapeshellcmd($ffmpegPath) . ' ';
        $cmd .= '-hide_banner -loglevel error ';
        $cmd .= '-user_agent ' . escapeshellarg($userAgent) . ' ';
        $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';
        $cmd .= '-c:v copy -c:a copy -f mpegts pipe:1';

        return $cmd;
    }

    /**
     * Monitor HLS stream and manage segments
     */
    private function monitorHLSStream(string $streamKey, SymfonyProcess $process, string $storageDir): void
    {
        // This would typically run in a background job
        // For now, we'll set up basic monitoring via shutdown function
        register_shutdown_function(function () use ($streamKey, $process, $storageDir) {
            // Cleanup when process ends
            if (!$process->isRunning()) {
                $this->cleanupStream($streamKey);
            }
        });
    }

    // Helper methods for cache management
    
    private function getStreamKey(string $type, int $modelId, string $streamUrl): string
    {
        return md5("{$type}:{$modelId}:{$streamUrl}");
    }

    private function getStreamInfo(string $streamKey): ?array
    {
        $data = Redis::get(self::CACHE_PREFIX . $streamKey);
        return $data ? json_decode($data, true) : null;
    }

    private function setStreamInfo(string $streamKey, array $streamInfo): void
    {
        Redis::setex(self::CACHE_PREFIX . $streamKey, 3600, json_encode($streamInfo));
    }

    private function isStreamActive(string $streamKey): bool
    {
        return Redis::exists(self::CACHE_PREFIX . $streamKey);
    }

    private function registerClient(string $streamKey, string $clientId, array $options): void
    {
        $clientKey = self::CLIENT_PREFIX . $streamKey;
        $clientData = [
            'id' => $clientId,
            'connected_at' => now()->timestamp,
            'last_activity' => now()->timestamp,
            'options' => $options
        ];
        Redis::hset($clientKey, $clientId, json_encode($clientData));
        Redis::expire($clientKey, 3600);
    }

    private function isClientRegistered(string $streamKey, string $clientId): bool
    {
        $clientKey = self::CLIENT_PREFIX . $streamKey;
        return Redis::hexists($clientKey, $clientId);
    }

    private function updateClientActivity(string $streamKey, string $clientId): void
    {
        $clientKey = self::CLIENT_PREFIX . $streamKey;
        if (Redis::hexists($clientKey, $clientId)) {
            $clientData = json_decode(Redis::hget($clientKey, $clientId), true);
            $clientData['last_activity'] = now()->timestamp;
            Redis::hset($clientKey, $clientId, json_encode($clientData));
        }
    }

    private function updateStreamActivity(string $streamKey): void
    {
        $streamInfo = $this->getStreamInfo($streamKey);
        if ($streamInfo) {
            $streamInfo['last_activity'] = now()->timestamp;
            $this->setStreamInfo($streamKey, $streamInfo);
        }
    }

    private function incrementClientCount(string $streamKey): int
    {
        $clientKey = self::CLIENT_PREFIX . $streamKey;
        return Redis::hlen($clientKey);
    }

    private function decrementClientCount(string $streamKey): int
    {
        $clientKey = self::CLIENT_PREFIX . $streamKey;
        return Redis::hlen($clientKey);
    }

    private function setStreamProcess(string $streamKey, int $pid): void
    {
        Redis::setex("stream_pid:{$streamKey}", 3600, $pid);
    }

    private function getStreamStorageDir(string $streamKey): string
    {
        return "shared_streams/{$streamKey}";
    }

    /**
     * Cleanup a specific stream
     */
    public function cleanupStream(string $streamKey, bool $force = false): bool
    {
        try {
            $streamInfo = Redis::hGetAll("stream:$streamKey");
            
            if (empty($streamInfo) && !$force) {
                return true; // Already cleaned up
            }

            // Stop the stream process if running
            if (isset($streamInfo['pid']) && $streamInfo['pid']) {
                $this->stopStreamProcess((int)$streamInfo['pid']);
            }

            // Clean up Redis keys
            Redis::del("stream:$streamKey");
            Redis::del("stream:$streamKey:clients");
            Redis::del("stream:$streamKey:buffer");
            Redis::del("stream:$streamKey:stats");

            // Clean up buffer directory
            $bufferDir = storage_path("app/stream_buffers/$streamKey");
            if (is_dir($bufferDir)) {
                $this->removeDirectory($bufferDir);
            }

            Log::info("Stream cleaned up", ['stream_key' => $streamKey]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to cleanup stream: " . $e->getMessage(), [
                'stream_key' => $streamKey
            ]);
            return false;
        }
    }

    /**
     * Update stream statistics
     */
    public function updateStreamStats(string $streamKey): void
    {
        try {
            $streamInfo = Redis::hGetAll("stream:$streamKey");
            
            if (empty($streamInfo)) {
                return;
            }

            $clients = Redis::sMembers("stream:$streamKey:clients");
            $clientCount = count($clients);
            
            $stats = [
                'client_count' => $clientCount,
                'last_updated' => time(),
                'status' => $this->isStreamHealthy($streamKey) ? 'healthy' : 'unhealthy',
                'uptime' => isset($streamInfo['started_at']) ? time() - (int)$streamInfo['started_at'] : 0
            ];

            // Get buffer stats
            $bufferDir = storage_path("app/stream_buffers/$streamKey");
            if (is_dir($bufferDir)) {
                $stats['buffer_size'] = $this->getDirectorySize($bufferDir);
                $stats['segment_count'] = count(glob($bufferDir . '/*.ts'));
            }

            Redis::hMSet("stream:$streamKey:stats", $stats);
            
        } catch (\Exception $e) {
            Log::error("Failed to update stream stats: " . $e->getMessage(), [
                'stream_key' => $streamKey
            ]);
        }
    }

    /**
     * Clean up orphaned Redis keys
     */
    public function cleanupOrphanedKeys(): int
    {
        $cleanedCount = 0;
        
        try {
            $streamKeys = Redis::keys('stream:*');
            
            foreach ($streamKeys as $key) {
                if (strpos($key, ':clients') !== false || 
                    strpos($key, ':buffer') !== false || 
                    strpos($key, ':stats') !== false) {
                    continue; // Skip sub-keys, they'll be handled with main stream
                }
                
                $streamInfo = Redis::hGetAll($key);
                
                // Check if process is still running
                if (isset($streamInfo['pid']) && $streamInfo['pid']) {
                    if (!$this->isProcessRunning((int)$streamInfo['pid'])) {
                        $streamKey = str_replace('stream:', '', $key);
                        $this->cleanupStream($streamKey, true);
                        $cleanedCount++;
                        Log::channel('ffmpeg')->debug("Cleaned up orphaned stream key: {$streamKey}");
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to cleanup orphaned keys: " . $e->getMessage());
        }
        
        return $cleanedCount;
    }

    /**
     * Clean up temporary files
     */
    public function cleanupTempFiles(int $olderThanSeconds = 3600): int
    {
        $bytesFreed = 0;
        
        try {
            $tempDir = storage_path('app/temp');
            
            if (!is_dir($tempDir)) {
                return $bytesFreed;
            }
            
            $cutoffTime = time() - $olderThanSeconds;
            $files = glob($tempDir . '/*');
            
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $cutoffTime) {
                    $fileSize = filesize($file);
                    if (unlink($file)) {
                        $bytesFreed += $fileSize;
                        Log::channel('ffmpeg')->debug("Cleaned up temp file: " . basename($file) . " (" . $fileSize . " bytes)");
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to cleanup temp files: " . $e->getMessage());
        }
        
        return $bytesFreed;
    }

    /**
     * Restart a stream
     */
    public function restartStream(string $streamKey): bool
    {
        try {
            $streamInfo = Redis::hGetAll("stream:$streamKey");
            
            if (empty($streamInfo)) {
                Log::warning("Cannot restart stream - not found", ['stream_key' => $streamKey]);
                return false;
            }
            
            // Stop current process
            if (isset($streamInfo['pid']) && $streamInfo['pid']) {
                $this->stopStreamProcess((int)$streamInfo['pid']);
            }
            
            // Start new process
            $sourceUrl = $streamInfo['source_url'] ?? '';
            $format = $streamInfo['format'] ?? 'ts';
            
            if (!$sourceUrl) {
                Log::error("Cannot restart stream - no source URL", ['stream_key' => $streamKey]);
                return false;
            }
            
            return $this->startSharedStream($streamKey, $sourceUrl, $format) !== null;
            
        } catch (\Exception $e) {
            Log::error("Failed to restart stream: " . $e->getMessage(), [
                'stream_key' => $streamKey
            ]);
            return false;
        }
    }

    /**
     * Clean up old buffer segments
     */
    public function cleanupOldBufferSegments(string $streamKey): int
    {
        $cleaned = 0;
        
        try {
            $bufferDir = storage_path("app/stream_buffers/$streamKey");
            
            if (!is_dir($bufferDir)) {
                return 0;
            }
            
            $maxAge = config('proxy.shared_streaming.buffer.segment_retention', 300); // 5 minutes
            $cutoffTime = time() - $maxAge;
            
            $files = glob($bufferDir . '/*.ts');
            
            foreach ($files as $file) {
                if (filemtime($file) < $cutoffTime) {
                    if (unlink($file)) {
                        $cleaned++;
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to cleanup old buffer segments: " . $e->getMessage(), [
                'stream_key' => $streamKey
            ]);
        }
        
        return $cleaned;
    }

    /**
     * Optimize buffer size based on client count
     */
    public function optimizeBufferSize(string $streamKey, int $clientCount): bool
    {
        try {
            $bufferDir = storage_path("app/stream_buffers/$streamKey");
            
            if (!is_dir($bufferDir)) {
                return false;
            }
            
            // Calculate optimal segment count based on client count
            $baseSegments = config('proxy.shared_streaming.buffer.segments', 10);
            $optimalSegments = max($baseSegments, min($clientCount * 2, 30));
            
            $files = glob($bufferDir . '/*.ts');
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Remove oldest segments if we have too many
            $currentCount = count($files);
            if ($currentCount > $optimalSegments) {
                $toRemove = $currentCount - $optimalSegments;
                for ($i = 0; $i < $toRemove; $i++) {
                    unlink($files[$i]);
                }
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("Failed to optimize buffer size: " . $e->getMessage(), [
                'stream_key' => $streamKey
            ]);
            return false;
        }
    }

    /**
     * Get disk usage for a stream's buffer
     */
    public function getStreamBufferDiskUsage(string $streamKey): int
    {
        $bufferDir = storage_path("app/stream_buffers/$streamKey");
        return $this->getDirectorySize($bufferDir);
    }

    /**
     * Trim buffer to specified size
     */
    public function trimBufferToSize(string $streamKey, int $maxSizeBytes): int
    {
        $freed = 0;
        
        try {
            $bufferDir = storage_path("app/stream_buffers/$streamKey");
            
            if (!is_dir($bufferDir)) {
                return 0;
            }
            
            $files = glob($bufferDir . '/*.ts');
            
            // Sort by modification time (oldest first)
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            $currentSize = $this->getDirectorySize($bufferDir);
            
            foreach ($files as $file) {
                if ($currentSize <= $maxSizeBytes) {
                    break;
                }
                
                $fileSize = filesize($file);
                if (unlink($file)) {
                    $freed += $fileSize;
                    $currentSize -= $fileSize;
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to trim buffer: " . $e->getMessage(), [
                'stream_key' => $streamKey
            ]);
        }
        
        return $freed;
    }

    /**
     * Find orphaned buffer directories
     */
    public function findOrphanedBufferDirectories(): array
    {
        $orphaned = [];
        
        try {
            $bufferBaseDir = storage_path('app/stream_buffers');
            
            if (!is_dir($bufferBaseDir)) {
                return $orphaned;
            }
            
            $dirs = glob($bufferBaseDir . '/*', GLOB_ONLYDIR);
            
            foreach ($dirs as $dir) {
                $streamKey = basename($dir);
                
                // Check if stream exists in Redis
                if (!Redis::exists("stream:$streamKey")) {
                    $orphaned[] = $dir;
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to find orphaned directories: " . $e->getMessage());
        }
        
        return $orphaned;
    }

    /**
     * Get directory size in bytes
     */
    public function getDirectorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }
        
        $size = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($files as $file) {
            $size += $file->getSize();
        }
        
        return $size;
    }

    /**
     * Remove directory and all contents
     */
    public function removeDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }
        
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
            
            return rmdir($path);
            
        } catch (\Exception $e) {
            Log::error("Failed to remove directory: " . $e->getMessage(), [
                'path' => $path
            ]);
            return false;
        }
    }

    /**
     * Get total buffer disk usage across all streams
     */
    public function getTotalBufferDiskUsage(): int
    {
        $bufferBaseDir = storage_path('app/stream_buffers');
        return $this->getDirectorySize($bufferBaseDir);
    }

    /**
     * Trim oldest buffers to reach target size
     */
    public function trimOldestBuffers(int $targetSizeBytes): int
    {
        $freed = 0;
        
        try {
            $bufferBaseDir = storage_path('app/stream_buffers');
            
            if (!is_dir($bufferBaseDir)) {
                return 0;
            }
            
            $dirs = glob($bufferBaseDir . '/*', GLOB_ONLYDIR);
            
            // Sort by modification time (oldest first)
            usort($dirs, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            $currentTotal = $this->getTotalBufferDiskUsage();
            
            foreach ($dirs as $dir) {
                if ($currentTotal <= $targetSizeBytes) {
                    break;
                }
                
                $dirSize = $this->getDirectorySize($dir);
                $streamKey = basename($dir);
                
                // Try to trim this stream's buffer first
                $streamFreed = $this->trimBufferToSize($streamKey, $dirSize / 2);
                $freed += $streamFreed;
                $currentTotal -= $streamFreed;
                
                // If still over target, remove entire buffer
                if ($currentTotal > $targetSizeBytes) {
                    if ($this->removeDirectory($dir)) {
                        $freed += $dirSize - $streamFreed;
                        $currentTotal -= ($dirSize - $streamFreed);
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::error("Failed to trim oldest buffers: " . $e->getMessage());
        }
        
        return $freed;
    }

    /**
     * Check if a process is running
     */
    private function isProcessRunning(int $pid): bool
    {
        try {
            return posix_kill($pid, 0);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Stop a stream process
     */
    private function stopStreamProcess(int $pid): bool
    {
        try {
            // Try graceful shutdown first
            if (posix_kill($pid, SIGTERM)) {
                // Wait a bit for graceful shutdown
                $attempts = 0;
                while ($attempts < 30 && posix_kill($pid, 0)) {
                    usleep(100000); // 100ms
                    $attempts++;
                }
                
                // Force kill if still running
                if (posix_kill($pid, 0)) {
                    posix_kill($pid, SIGKILL);
                    Log::warning("Force killed stream process {$pid}");
                }
                
                return true;
            }
            return false;
        } catch (\Exception $e) {
            Log::error("Failed to stop process {$pid}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a stream is healthy
     */
    private function isStreamHealthy(string $streamKey): bool
    {
        try {
            $streamInfo = Redis::hGetAll("stream:$streamKey");
            
            if (empty($streamInfo)) {
                return false;
            }
            
            // Check if process is running
            if (isset($streamInfo['pid']) && $streamInfo['pid']) {
                if (!$this->isProcessRunning((int)$streamInfo['pid'])) {
                    return false;
                }
            }
            
            // Check last activity
            $lastActivity = $streamInfo['last_activity'] ?? 0;
            $now = time();
            $timeout = config('proxy.shared_streaming.monitoring.stream_timeout', 300);
            
            return ($now - $lastActivity) < $timeout;
            
        } catch (\Exception $e) {
            Log::error("Failed to check stream health: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Start a shared stream (used by restart functionality)
     */
    private function startSharedStream(string $streamKey, string $sourceUrl, string $format): ?string
    {
        try {
            // This is a simplified version - in reality you'd want to use the full createSharedStream logic
            $streamInfo = [
                'stream_key' => $streamKey,
                'stream_url' => $sourceUrl,
                'format' => $format,
                'status' => 'starting',
                'created_at' => time(),
                'last_activity' => time()
            ];
            
            $this->setStreamInfo($streamKey, $streamInfo);
            $this->startStreamingProcess($streamKey, $streamInfo);
            
            return $streamKey;
            
        } catch (\Exception $e) {
            Log::error("Failed to start shared stream: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the number of clients connected to a stream
     * 
     * @param string $streamKey
     * @return int Number of clients
     */
    public function getClientCount(string $streamKey): int
    {
        try {
            $clientKey = self::CLIENT_PREFIX . $streamKey;
            $clients = Redis::smembers($clientKey);
            
            // Clean up expired clients
            $activeClients = [];
            foreach ($clients as $clientId) {
                if ($this->isClientActive($clientId)) {
                    $activeClients[] = $clientId;
                } else {
                    Redis::srem($clientKey, $clientId);
                }
            }
            
            return count($activeClients);
            
        } catch (\Exception $e) {
            Log::error("Failed to get client count for stream {$streamKey}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check if a client is still active
     * 
     * @param string $clientId
     * @return bool
     */
    private function isClientActive(string $clientId): bool
    {
        try {
            $lastSeen = Redis::get("client_last_seen:{$clientId}");
            if (!$lastSeen) {
                return false;
            }
            
            $timeout = config('proxy.shared_streaming.monitoring.client_timeout', 30);
            return (time() - $lastSeen) < $timeout;
            
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get all active streams from Redis cache
     * 
     * @return array Array of stream information
     */
    public function getAllActiveStreams(): array
    {
        try {
            $pattern = self::CACHE_PREFIX . '*';
            $keys = Redis::keys($pattern);
            $streams = [];
            
            foreach ($keys as $key) {
                $streamKey = str_replace(self::CACHE_PREFIX, '', $key);
                $streamInfo = $this->getStreamInfo($streamKey);
                
                if ($streamInfo) {
                    $streamInfo['stream_key'] = $streamKey;
                    $streamInfo['clients'] = $this->getClientCount($streamKey);
                    $streams[] = $streamInfo;
                }
            }
            
            return $streams;
            
        } catch (\Exception $e) {
            Log::error("Failed to get all active streams: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Create a shared stream (simplified interface for testing)
     * 
     * @param string $sourceUrl
     * @param string $format
     * @return string Stream ID
     */
    public function createSharedStream(string $sourceUrl, string $format): string
    {
        $streamKey = $this->getStreamKey('test', 0, $sourceUrl);
        
        $streamInfo = $this->createSharedStreamInternal(
            $streamKey,
            'test',
            0,
            $sourceUrl,
            'Test Stream',
            $format,
            []
        );
        
        return $streamInfo['stream_key'];
    }

    /**
     * Join a stream (create if not exists)
     * 
     * @param string $sourceUrl
     * @param string $format
     * @param string $ipAddress
     * @return array
     */
    public function joinStream(string $sourceUrl, string $format, string $ipAddress): array
    {
        $streamKey = $this->getStreamKey('test', 0, $sourceUrl);
        
        // Check both Redis and database for existing stream
        $existingStream = $this->getStreamInfo($streamKey);
        $dbStream = SharedStream::where('stream_id', $streamKey)->first();
        
        // Accept streams that are 'starting' or 'active' as joinable
        $isExistingJoinable = ($existingStream && in_array($existingStream['status'], ['starting', 'active'])) || 
                              ($dbStream && in_array($dbStream->status, ['starting', 'active']));
        
        if ($isExistingJoinable) {
            // Join existing stream
            return [
                'stream_id' => $streamKey,
                'joined_existing' => true
            ];
        } else {
            // Create new stream
            $streamId = $this->createSharedStream($sourceUrl, $format);
            return [
                'stream_id' => $streamId,
                'joined_existing' => false
            ];
        }
    }

    /**
     * Stop a stream
     * 
     * @param string $streamId
     * @return bool
     */
    public function stopStream(string $streamId): bool
    {
        try {
            $streamInfo = $this->getStreamInfo($streamId);
            if (!$streamInfo) {
                return false;
            }
            
            // Update status to stopped in Redis
            $streamInfo['status'] = 'stopped';
            $streamInfo['stopped_at'] = time();
            $this->setStreamInfo($streamId, $streamInfo);
            
            // Update status in database
            SharedStream::where('stream_id', $streamId)->update([
                'status' => 'stopped',
                'stopped_at' => now()
            ]);
            
            // Clean up the stream
            $this->cleanupStream($streamId);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("Failed to stop stream {$streamId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get stream URL for accessing the stream
     * 
     * @param string $streamId
     * @param string $format
     * @return string
     */
    public function getStreamUrl(string $streamId, string $format): string
    {
        if ($format === 'hls') {
            return route('shared.stream.hls', ['streamKey' => $streamId]);
        } else {
            return route('shared.stream.direct', ['streamKey' => $streamId]);
        }
    }

    /**
     * Clean up inactive streams (streams with no clients or that are stale)
     */
    public function cleanupInactiveStreams(): array
    {
        $cleanedStreams = 0;
        $cleanedClients = 0;
        
        try {
            $activeStreams = $this->getAllActiveStreams();
            
            foreach ($activeStreams as $streamKey => $streamData) {
                $streamInfo = $streamData['stream_info'];
                $clientCount = $streamData['client_count'];
                $lastActivity = $streamData['last_activity'] ?? time();
                
                // Count existing clients before cleanup
                $cleanedClients += $clientCount;
                
                // Check if stream is inactive (no clients and inactive for more than 5 minutes)
                $isInactive = $clientCount === 0 && (time() - $lastActivity) > 300;
                
                // Check if stream is stale (running for more than 4 hours with no recent activity)
                $isStale = isset($streamData['uptime']) && 
                          $streamData['uptime'] > 14400 && 
                          (time() - $lastActivity) > 1800; // 30 minutes
                
                if ($isInactive || $isStale) {
                    $reason = $isInactive ? 'no clients' : 'stale/inactive';
                    Log::channel('ffmpeg')->info("CleanupInactiveStreams: Cleaning up stream {$streamKey} ({$reason})");
                    
                    $success = $this->cleanupStream($streamKey, true);
                    
                    if ($success) {
                        $cleanedStreams++;
                    }
                }
            }
            
            // Also clean up orphaned keys and temp files
            $orphanedKeys = $this->cleanupOrphanedKeys();
            $tempFiles = $this->cleanupTempFiles();
            
            Log::channel('ffmpeg')->info("CleanupInactiveStreams: Completed - Cleaned {$cleanedStreams} streams, {$cleanedClients} clients, {$orphanedKeys} orphaned keys, cleaned " . round($tempFiles / 1024 / 1024, 2) . "MB temp files");
            
            return [
                'cleaned_streams' => $cleanedStreams,
                'cleaned_clients' => $cleanedClients,
                'orphaned_keys' => $orphanedKeys,
                'temp_files_freed' => $tempFiles
            ];
            
        } catch (\Exception $e) {
            Log::error("Failed to cleanup inactive streams: " . $e->getMessage());
            throw $e;
        }
    }

}
