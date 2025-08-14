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
 */
class StreamBufferManager implements ShouldQueue
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
                } catch (\Exception $e) {
                    Log::channel('ffmpeg')->error("BufferManagement: Error managing buffer for stream {$streamKey}: " . $e->getMessage());
                }
            }

            // Log summary
            Log::channel('ffmpeg')->info(
                "BufferManagement: Completed - Cleaned {$totalCleaned} segments, " .
                    "optimized {$totalOptimized} buffers"
            );
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error('BufferManagement: Error during buffer management: ' . $e->getMessage());
            throw $e;
        }
    }
}
