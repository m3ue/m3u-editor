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
        // Only run if the newer Shared Streaming is enabled
        if (!config('proxy.shared_streaming.enabled')) {
            return;
        }

        Log::channel('ffmpeg')->debug('SharedStreamCleanup: Starting cleanup process');
        try {
            // Get all active streams from database
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

                // Check if the process is still running
                $pid = $streamInfo['pid'] ?? null;
                $isProcessRunning = $pid ? $sharedStreamService->isProcessRunning($pid) : false;

                // Condition 1: Stream is stale (no clients, inactive, and process is not running)
                $isStale = $clientCount === 0 && !$isProcessRunning && (time() - $lastActivity) > 600 && $streamAge > 120;

                // Condition 2: Stream is a phantom (marked active, but process is dead)
                $isPhantom = !$isProcessRunning && in_array($streamInfo['status'], ['active', 'starting']);

                // Condition 3: Stream is stuck (running for a long time with no activity)
                $isStuck = $isProcessRunning && $uptime > 14400 && (time() - $lastActivity) > 1800; // 4 hours uptime, 30 mins inactivity

                if ($isStale || $isPhantom || $isStuck) {
                    $reason = 'unknown';
                    if ($isStale) $reason = 'stale (no clients for 10+ mins)';
                    if ($isPhantom) $reason = 'phantom (process not running)';
                    if ($isStuck) $reason = 'stuck/inactive';

                    Log::channel('ffmpeg')->info("SharedStreamCleanup: Cleaning up stream {$streamKey} ({$reason}, age: {$streamAge}s, PID: {$pid})");

                    // Force cleanup of the stream
                    $sharedStreamService->cleanupStream($streamKey, true);

                    $cleanedUp++;
                    if ($isStale) $staleStreams++;
                }
            }

            // Clean up orphaned Redis keys that don't have a database entry
            $orphanedKeys = $sharedStreamService->cleanupOrphanedKeys();
            Log::channel('ffmpeg')->info("SharedStreamCleanup: Completed - Cleaned {$cleanedUp} streams ({$staleStreams} stale), {$orphanedKeys} orphaned keys, {$tempFilesCleanup} temp files cleaned");
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error('SharedStreamCleanup: Error during cleanup: ' . $e->getMessage());
            throw $e; // Re-throw to allow for retries if configured
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
