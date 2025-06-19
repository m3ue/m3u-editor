<?php

namespace App\Jobs;

use App\Services\SharedStreamService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Background job for cleaning up stale shared streams
 * 
 * This job runs periodically to:
 * - Remove streams with no connected clients
 * - Clean up expired stream buffers
 * - Terminate orphaned FFmpeg processes
 * - Update stream statistics
 */
class SharedStreamCleanup implements ShouldQueue
{
    use Queueable;

    public $timeout = 120; // 2 minutes
    public $tries = 1;

    public function __construct()
    {
        // This job will be dispatched periodically by the scheduler
    }

    /**
     * Execute the job.
     */
    public function handle(SharedStreamService $sharedStreamService): void
    {
        Log::channel('ffmpeg')->debug('SharedStreamCleanup: Starting cleanup process');

        try {
            // Get all active streams
            $activeStreams = $sharedStreamService->getAllActiveStreams();
            $cleanedUp = 0;
            $staleStreams = 0;

            foreach ($activeStreams as $streamKey => $streamData) {
                $streamInfo = $streamData['stream_info'];
                $clientCount = $streamData['client_count'];
                $lastActivity = $streamData['last_activity'] ?? time();
                $uptime = $streamData['uptime'] ?? 0;
                $createdAt = $streamInfo['created_at'] ?? time();
                $streamAge = time() - $createdAt;
                
                // Check if stream is stale (no clients and inactive for more than 10 minutes, but only if stream is older than 2 minutes)
                $isStale = $clientCount === 0 && (time() - $lastActivity) > 600 && $streamAge > 120;
                
                // Check if stream is stuck (running for more than 4 hours with no recent activity)
                $isStuck = $uptime > 14400 && (time() - $lastActivity) > 1800; // 30 minutes
                
                if ($isStale || $isStuck) {
                    $reason = $isStale ? 'no clients for 10+ minutes' : 'stuck/inactive';
                    Log::channel('ffmpeg')->info("SharedStreamCleanup: Cleaning up stream {$streamKey} ({$reason}, age: {$streamAge}s)");
                    
                    // Force cleanup of the stream
                    $success = $sharedStreamService->cleanupStream($streamKey, true);
                    
                    if ($success) {
                        $cleanedUp++;
                        if ($isStale) $staleStreams++;
                    }
                } else {
                    // Stream is active, just update its statistics
                    $sharedStreamService->updateStreamStats($streamKey);
                }
            }

            // Clean up orphaned Redis keys
            $orphanedKeys = $sharedStreamService->cleanupOrphanedKeys();

            // Clean up temporary files older than 24 hours
            $tempFilesCleanup = $sharedStreamService->cleanupTempFiles();

            Log::channel('ffmpeg')->info("SharedStreamCleanup: Completed - Cleaned {$cleanedUp} streams ({$staleStreams} stale), {$orphanedKeys} orphaned keys, {$tempFilesCleanup} temp files");

        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error('SharedStreamCleanup: Error during cleanup: ' . $e->getMessage());
            throw $e; // Re-throw to trigger retry logic if configured
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('ffmpeg')->error('SharedStreamCleanup: Job failed permanently: ' . $exception->getMessage());
    }
}
