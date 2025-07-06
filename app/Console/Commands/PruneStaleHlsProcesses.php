<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use App\Services\HlsStreamService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class PruneStaleHlsProcesses extends Command
{
    protected $signature = 'app:hls-prune {--threshold=15}';
    protected $description = 'Stop FFmpeg for HLS streams with no segment requests recently';

    private $hlsService;

    public function __construct(HlsStreamService $hlsStreamService)
    {
        $this->hlsService = $hlsStreamService;
        parent::__construct();
    }

    public function handle()
    {
        // Get the threshold from the command line option (default is 10 seconds)
        $threshold = (int)$this->option('threshold');

        // Fetch the list of active channel IDs from Redis
        $activeChannelIds = Redis::smembers('hls:active_channel_ids');
        $activeEspisodeIds = Redis::smembers('hls:active_episode_ids');

        $stoppedChannels = 0;
        $stoppedEpisodes = 0;

        // For each active channel, check staleness
        foreach ($activeChannelIds as $channelId) {
            $this->processStaleStream('channel', $channelId, $threshold, $stoppedChannels);
        }

        // For each active episode, check staleness
        foreach ($activeEspisodeIds as $episodeId) {
            $this->processStaleStream('episode', $episodeId, $threshold, $stoppedEpisodes);
        }

        // Only output summary if there was activity
        if ($stoppedChannels > 0 || $stoppedEpisodes > 0) {
            $this->info("HLS Prune: Stopped {$stoppedChannels} channels and {$stoppedEpisodes} episodes");
        }
    }

    private function processStaleStream(string $type, string $id, int $threshold, int &$stoppedCounter)
    {
        $originalIdLastSeenTs = Redis::get("hls:{$type}_last_seen:{$id}");

        if (!$originalIdLastSeenTs) {
            // If there's no last_seen for this ID, it might be an orphaned active_id or already cleaned up.
            // Try to stop it just in case to clean up any lingering PID cache or files if HlsStreamService allows.
            // HlsStreamService::stopStream is idempotent.
            $wasRunning = $this->hlsService->stopStream($type, $id, "stale_process_pruned_{$type}_no_last_seen");
            if ($wasRunning) {
                $stoppedCounter++;
                $this->info("🛑 Stopped {$type} {$id} (pruned due to missing last_seen timestamp, but was running)");
            } else {
                // If not running and no last_seen, ensure it's removed from active set if it's somehow still there
                Redis::srem("hls:active_{$type}_ids", $id);
            }
            return;
        }

        $originalIdLastSeen = Carbon::createFromTimestamp((int) $originalIdLastSeenTs);

        // Check if this ID is an original ID that has an active failover mapping
        $streamMappingKey = "hls:stream_mapping:{$type}:{$id}";
        $mappedStreamingId = Cache::get($streamMappingKey);

        if ($mappedStreamingId && $mappedStreamingId != $id) {
            // A failover has occurred. The current $id is the original, $mappedStreamingId is the actual one.
            // We should check the staleness of the $mappedStreamingId.
            $mappedIdLastSeenTs = Redis::get("hls:{$type}_last_seen:{$mappedStreamingId}");

            if ($mappedIdLastSeenTs) {
                $mappedIdLastSeen = Carbon::createFromTimestamp((int) $mappedIdLastSeenTs);
                if ($mappedIdLastSeen->addSeconds($threshold)->isPast()) {
                    // The *actual* streaming ID (failover) is stale. Stop it.
                    // And also stop/cleanup the original ID's resources.
                    $this->info("🔎 {$type} {$id} mapped to {$mappedStreamingId}. Mapped stream is stale.");
                    $wasRunningMapped = $this->hlsService->stopStream($type, $mappedStreamingId, "stale_process_pruned_mapped_{$type}");
                    if ($wasRunningMapped) {
                        $stoppedCounter++; // Count as one stopped logical stream
                        $this->info("🛑 Stopped stale mapped {$type} {$mappedStreamingId}");
                    }
                    // Now, also ensure the original ID is cleaned up.
                    // The reason here is slightly different to indicate it's part of a stale mapped cleanup.
                    $this->hlsService->stopStream($type, $id, "stale_process_pruned_original_of_stale_mapped_{$type}");
                    Cache::forget($streamMappingKey); // Clean up the mapping as it's stale
                } else {
                    // Mapped stream is active, so the original $id (logical stream) is considered active. Don't prune.
                    $this->info("🔎 {$type} {$id} mapped to {$mappedStreamingId}. Mapped stream is still active. Skipping prune for {$id}.");
                    return;
                }
            } else {
                // Mapped stream has no last_seen. This is unusual. Treat as stale.
                // Stop the mapped stream and the original.
                $this->info("🔎 {$type} {$id} mapped to {$mappedStreamingId}, but mapped stream has no last_seen. Treating as stale.");
                $this->hlsService->stopStream($type, $mappedStreamingId, "stale_process_pruned_mapped_{$type}_no_last_seen");
                $this->hlsService->stopStream($type, $id, "stale_process_pruned_original_of_unseen_mapped_{$type}");
                Cache::forget($streamMappingKey);
                // We don't increment counter here if mapped wasn't "running" per se, stopStream handles logging.
                // But it's good to ensure the original is also stopped.
            }
        } else {
            // No mapping, or mapped to itself. This $id is the one we check directly.
            if ($originalIdLastSeen->addSeconds($threshold)->isPast()) {
                $reason = "stale_process_pruned_{$type}";
                $wasRunning = $this->hlsService->stopStream($type, $id, $reason);
                if ($wasRunning) {
                    $stoppedCounter++;
                    $this->info("🛑 Stopped stale {$type} {$id} (pruned due to inactivity)");
                }
                // If there was a mapping to itself, clean it up if it's stale
                if ($mappedStreamingId && $mappedStreamingId == $id) {
                    Cache::forget($streamMappingKey);
                }
            }
        }
    }
}
