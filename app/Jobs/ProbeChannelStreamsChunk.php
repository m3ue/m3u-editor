<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Traits\ProviderRequestDelay;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProbeChannelStreamsChunk implements ShouldQueue
{
    use Batchable, ProviderRequestDelay, Queueable;

    public $tries = 1;

    public $deleteWhenMissingModels = true;

    public $timeout = 60 * 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $channelIds,
        public int $probeTimeout = 15,
    ) {}

    /**
     * Backfill properties that did not exist when older jobs were serialized into the queue.
     */
    public function __wakeup(): void
    {
        if (! isset($this->probeTimeout)) {
            $this->probeTimeout = 15;
        }
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $channels = Channel::whereIn('id', $this->channelIds)->get();

        foreach ($channels as $channel) {
            $stats = $this->withProviderThrottling(
                fn () => $channel->probeStreamStats($this->probeTimeout)
            );

            if (! empty($stats)) {
                $channel->updateQuietly([
                    'stream_stats' => $stats,
                    'stream_stats_probed_at' => now(),
                ]);
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("Stream probe chunk job failed: {$exception->getMessage()}");
    }
}
