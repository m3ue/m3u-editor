<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\User;
use App\Traits\ProviderRequestDelay;
use Filament\Notifications\Notification;
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
        public ?int $notifyUserId = null,
        public ?string $notifyLabel = null,
        public ?int $notifyTotal = null,
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

        $probedCount = 0;

        foreach ($channels as $channel) {
            $stats = $this->withProviderThrottling(
                fn () => $channel->probeStreamStats($this->probeTimeout)
            );

            if (! empty($stats)) {
                $channel->updateQuietly([
                    'stream_stats' => $stats,
                    'stream_stats_probed_at' => now(),
                ]);
                $probedCount++;
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
                $probedCount++;
            }
        }

        if ($this->notifyUserId) {
            $user = User::find($this->notifyUserId);
            if ($user) {
                $total = $this->notifyTotal ?? (count($this->channelIds) + count($this->episodeIds));
                $label = $this->notifyLabel ?: __('Stream probing');
                Notification::make()
                    ->success()
                    ->title($label.' '.__('complete'))
                    ->body(__(':probed of :total stream(s) probed successfully.', [
                        'probed' => $probedCount,
                        'total' => $total,
                    ]))
                    ->broadcast($user)
                    ->sendToDatabase($user);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error("VOD stream probe chunk job failed: {$exception->getMessage()}");

        if ($this->notifyUserId) {
            $user = User::find($this->notifyUserId);
            if ($user) {
                Notification::make()
                    ->danger()
                    ->title(__('Stream probing failed'))
                    ->body($exception->getMessage())
                    ->broadcast($user)
                    ->sendToDatabase($user);
            }
        }
    }
}
