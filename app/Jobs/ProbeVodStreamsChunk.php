<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Episode;
use App\Traits\ProviderRequestDelay;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProbeVodStreamsChunk implements ShouldQueue
{
    use Batchable, ProviderRequestDelay, Queueable;

    public $tries = 1;

    public $deleteWhenMissingModels = true;

    public $timeout = 60 * 60;

    public function __construct(
        public array $channelIds = [],
        public array $episodeIds = [],
        public int $probeTimeout = 15,
    ) {}

    public function __wakeup(): void
    {
        if (! isset($this->probeTimeout)) {
            $this->probeTimeout = 15;
        }
    }

    public function handle(): void
    {
        $channels = $this->channelIds ? Channel::whereIn('id', $this->channelIds)->get() : collect();
        $episodes = $this->episodeIds ? Episode::whereIn('id', $this->episodeIds)->get() : collect();

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

        foreach ($episodes as $episode) {
            $stats = $this->withProviderThrottling(
                fn () => $episode->probeStreamStats($this->probeTimeout)
            );

            if (! empty($stats)) {
                $episode->updateQuietly([
                    'stream_stats' => $stats,
                    'stream_stats_probed_at' => now(),
                ]);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error("VOD stream probe chunk job failed: {$exception->getMessage()}");
    }
}
