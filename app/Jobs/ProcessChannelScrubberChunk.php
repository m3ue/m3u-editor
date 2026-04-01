<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\Channel;
use App\Models\ChannelScrubber;
use App\Models\ChannelScrubberLogChannel;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessChannelScrubberChunk implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $deleteWhenMissingModels = true;

    public $timeout = 60 * 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $channelIds,
        public int $scrubberId,
        public int $logId,
        public string $checkMethod,
        public string $batchNo,
        public int $totalChannels,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $scrubber = ChannelScrubber::find($this->scrubberId);
        if (! $scrubber || $scrubber->uuid !== $this->batchNo || $scrubber->status === Status::Cancelled) {
            return;
        }

        $channels = Channel::whereIn('id', $this->channelIds)->get();
        $deadCount = 0;

        foreach ($channels as $channel) {
            try {
                $isDead = $this->checkMethod === 'ffprobe'
                    ? $this->checkViaFfprobe($channel)
                    : $this->checkViaHttp($channel);

                if ($isDead) {
                    ChannelScrubberLogChannel::create([
                        'channel_scrubber_log_id' => $this->logId,
                        'channel_id' => $channel->id,
                        'title' => $channel->title ?? $channel->name_custom ?? $channel->name ?? '',
                        'url' => ($channel->url_custom ?? $channel->url) ?? '',
                    ]);

                    $channel->update(['enabled' => false]);
                    $deadCount++;
                }
            } catch (Exception $e) {
                Log::warning("Channel scrubber: error checking channel #{$channel->id} \"{$channel->title}\": {$e->getMessage()}");
            }
        }

        if ($deadCount > 0) {
            ChannelScrubber::where('id', $this->scrubberId)->increment('dead_count', $deadCount);
        }

        // Increment progress
        $processed = \count($this->channelIds);
        if ($this->totalChannels > 0) {
            $increment = ($processed / $this->totalChannels) * 90;
            $scrubber->refresh();
            $newProgress = min(95, $scrubber->progress + $increment);
            $scrubber->update(['progress' => $newProgress]);
        }
    }

    /**
     * Check a channel URL via HTTP HEAD request.
     */
    private function checkViaHttp(Channel $channel): bool
    {
        $url = $channel->url_custom ?? $channel->url;
        if (empty($url)) {
            return true;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'])
                ->head($url);

            return $response->failed();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Check a channel URL via ffprobe, using ensureStreamStats() so results are persisted.
     */
    private function checkViaFfprobe(Channel $channel): bool
    {
        try {
            return empty($channel->ensureStreamStats());
        } catch (Throwable) {
            return true;
        }
    }
}
