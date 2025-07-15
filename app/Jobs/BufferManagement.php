<?php

namespace App\Jobs;

use App\Services\SharedStreamService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Background job for managing shared stream buffers
 * 
 * This job runs periodically to:
 * - Clean up old buffer segments
 * - Optimize buffer sizes based on client count
 * - Manage disk space usage
 * - Rotate buffer files
 */
class BufferManagement implements ShouldQueue
{
    use Queueable;

    public $timeout = 180; // 3 minutes
    public $tries = 1;

    public function __construct()
    {
        // This job will be dispatched every 5 minutes by the scheduler
    }

    /**
     * Execute the job.
     */
    public function handle(SharedStreamService $sharedStreamService): void
    {
        Log::channel('ffmpeg')->debug('BufferManagement: Starting buffer management process');

        try {
            $activeStreams = $sharedStreamService->getAllActiveStreams();
            $totalCleaned = 0;
            $totalOptimized = 0;
            $totalDiskFreed = 0;

            foreach ($activeStreams as $streamKey => $streamData) {
                $streamInfo = $streamData['stream_info'];
                $clientCount = $streamData['client_count'];
                
                try {
                    // Clean up old buffer segments (keep only recent ones)
                    $segmentsCleaned = $sharedStreamService->cleanupOldBufferSegments($streamKey);
                    $totalCleaned += $segmentsCleaned;
                    
                    // Optimize buffer size based on client count
                    $optimized = $sharedStreamService->optimizeBufferSize($streamKey, $clientCount);
                    if ($optimized) {
                        $totalOptimized++;
                        Log::channel('ffmpeg')->debug("BufferManagement: Optimized buffer for stream {$streamKey} ({$clientCount} clients)");
                    }
                    
                    // Check disk usage for this stream's buffers
                    $diskUsage = $sharedStreamService->getStreamBufferDiskUsage($streamKey);
                    if ($diskUsage > 100 * 1024 * 1024) { // More than 100MB
                        $freed = $sharedStreamService->trimBufferToSize($streamKey, 50 * 1024 * 1024); // Trim to 50MB
                        $totalDiskFreed += $freed;
                        Log::channel('ffmpeg')->info("BufferManagement: Trimmed buffer for stream {$streamKey}, freed " . round($freed / 1024 / 1024, 2) . "MB");
                    }
                    
                } catch (\Exception $e) {
                    Log::channel('ffmpeg')->error("BufferManagement: Error managing buffer for stream {$streamKey}: " . $e->getMessage());
                }
            }

            // Global buffer cleanup
            $globalCleanup = $this->performGlobalCleanup($sharedStreamService);
            $totalDiskFreed += $globalCleanup['disk_freed'];

            // Log summary
            Log::channel('ffmpeg')->info(
                "BufferManagement: Completed - Cleaned {$totalCleaned} segments, " .
                "optimized {$totalOptimized} buffers, freed " . round($totalDiskFreed / 1024 / 1024, 2) . "MB disk space"
            );

        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error('BufferManagement: Error during buffer management: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Perform global cleanup operations
     */
    private function performGlobalCleanup(SharedStreamService $sharedStreamService): array
    {
        $results = ['disk_freed' => 0];

        try {
            // Clean up orphaned buffer directories
            $orphanedDirs = $sharedStreamService->findOrphanedBufferDirectories();
            foreach ($orphanedDirs as $dir) {
                $size = $sharedStreamService->getDirectorySize($dir);
                if ($sharedStreamService->removeDirectory($dir)) {
                    $results['disk_freed'] += $size;
                    Log::channel('ffmpeg')->debug("BufferManagement: Removed orphaned buffer directory: {$dir}");
                }
            }

            // Clean up temporary files older than 1 hour
            $tempFilesCleanup = $sharedStreamService->cleanupTempFiles(3600);
            $results['disk_freed'] += $tempFilesCleanup;

            // Enforce global disk usage limits
            $totalBufferSize = $sharedStreamService->getTotalBufferDiskUsage();
            $maxGlobalBufferSize = 1024 * 1024 * 1024; // 1GB total limit
            
            if ($totalBufferSize > $maxGlobalBufferSize) {
                $targetSize = $maxGlobalBufferSize * 0.8; // Trim to 80% of limit
                $freed = $sharedStreamService->trimOldestBuffers($targetSize);
                $results['disk_freed'] += $freed;
                Log::channel('ffmpeg')->warning(
                    "BufferManagement: Global buffer limit exceeded ({$totalBufferSize} bytes), " .
                    "freed " . round($freed / 1024 / 1024, 2) . "MB"
                );
            }

        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error('BufferManagement: Error during global cleanup: ' . $e->getMessage());
        }

        return $results;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('ffmpeg')->error('BufferManagement: Job failed permanently: ' . $exception->getMessage());
    }
}
