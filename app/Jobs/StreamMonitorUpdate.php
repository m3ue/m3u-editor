<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Episode;
use App\Services\SharedStreamService;
use App\Services\StreamMonitorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Background job for monitoring stream health and updating statistics
 * 
 * This job runs frequently to:
 * - Monitor stream health and bandwidth
 * - Update real-time statistics
 * - Detect and handle stream failures
 * - Track client connections and disconnections
 */
class StreamMonitorUpdate implements ShouldQueue
{
    use Queueable;

    public $timeout = 60; // 1 minute
    public $tries = 1;

    public function __construct()
    {
        // This job will be dispatched every minute by the scheduler
    }

    /**
     * Execute the job.
     */
    public function handle(
        SharedStreamService $sharedStreamService,
        StreamMonitorService $monitorService
    ): void {
        try {
            // Update monitoring statistics
            $monitorService->updateSystemStats();

            // Get current stream status
            // $streamStats = $monitorService->getStreamStats(); // this is inaccurate and uses an earlier Redis version...
            $systemStats = $monitorService->getSystemStats();

            // Need to remove any stale clients
            // We can be pretty agressive with this, as the timestamp will be updated frequently for active connections
            $activeClients = $sharedStreamService->getAllActiveClients();
            $removedClients = 0;
            foreach ($activeClients as $client) {
                if (isset($client['last_activity_at']) && time() - $client['last_activity_at'] > 30) { // 30 seconds
                    Log::channel('ffmpeg')->info("StreamMonitor: Removing stale client {$client['client_id']} from stream {$client['stream_id']}");
                    $sharedStreamService->removeClient($client['stream_id'], $client['client_id']);
                    $removedClients++;
                }
            }

            // Update stream health for all active streams
            $activeStreams = $sharedStreamService->getAllActiveStreams();
            $unhealthyStreams = 0;
            $totalBandwidth = 0;
            foreach ($activeStreams as $streamKey => $streamData) {
                try {
                    // Check stream health
                    $health = $monitorService->checkStreamHealth($streamKey);

                    if (!$health['healthy']) {
                        $unhealthyStreams++;
                        Log::channel('ffmpeg')->warning("StreamMonitor: Unhealthy stream detected: {$streamKey} - {$health['reason']}");

                        // Attempt to restart if it's a critical failure
                        if ($health['critical']) {
                            Log::channel('ffmpeg')->error("StreamMonitor: Critical failure for stream {$streamKey}");

                            // @TODO: Implement failover logic if needed...
                        }
                    }

                    // Update bandwidth tracking
                    if (isset($health['bandwidth'])) {
                        $totalBandwidth += $health['bandwidth'];
                    }
                } catch (\Exception $e) {
                    Log::channel('ffmpeg')->error("StreamMonitor: Error checking health for stream {$streamKey}: " . $e->getMessage());
                }
            }

            // Update system-wide statistics
            $monitorService->updateGlobalStats([
                'total_streams' => count($activeStreams),
                'unhealthy_streams' => $unhealthyStreams,
                'total_bandwidth' => $totalBandwidth,
                'system_load' => $systemStats['load_average']['1min'] ?? 0,
                'memory_usage_percentage' => $systemStats['memory_usage']['percentage'] ?? 0,
                'redis_connected' => $systemStats['redis_connected'] ?? false,
                'timestamp' => time()
            ]);

            // Clean up stale monitoring data
            $monitorService->cleanupStaleData();

            Log::channel('ffmpeg')->debug(
                "StreamMonitor: Updated stats - " . count($activeStreams) . " streams, " .
                    "{$removedClients} stale clients removed, " .
                    "{$unhealthyStreams} unhealthy, " . round($totalBandwidth / 1024 / 1024, 2) . "MB/s bandwidth"
            );
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->error('StreamMonitor: Error during monitoring update: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('ffmpeg')->error('StreamMonitor: Monitoring job failed: ' . $exception->getMessage());
    }
}
