<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\User;
use App\Support\ProbeCircuitBreaker;
use App\Traits\ProviderRequestDelay;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProbeStreamsChunk implements ShouldQueue
{
    use Batchable, ProviderRequestDelay, Queueable;

    public $tries = 1;

    public $deleteWhenMissingModels = true;

    public $timeout = 60 * 60;

    /**
     * @param  ?string  $probeRunKey  Shared by every chunk of one automatic ProbeStreams run so
     *                                they feed a single ProbeCircuitBreaker. Null for manual probes.
     * @param  int  $failureThreshold  Failure percentage that trips the run's breaker (0 = off).
     * @param  array<int, array<int>>  $episodeSiblings  Sampled series probing: probed episode id
     *                                                   => sibling episode ids that inherit its
     *                                                   stream stats when the probe succeeds.
     */
    public function __construct(
        public array $channelIds = [],
        public array $episodeIds = [],
        public int $probeTimeout = 15,
        public ?int $notifyUserId = null,
        public ?string $notifyLabel = null,
        public ?int $notifyTotal = null,
        public ?string $probeRunKey = null,
        public int $failureThreshold = 0,
        public array $episodeSiblings = [],
    ) {}

    public function handle(): void
    {
        $breaker = ProbeCircuitBreaker::forRun($this->probeRunKey, $this->failureThreshold);

        // Chunks queued behind a tripped breaker exit before loading anything.
        if ($breaker?->isTripped()) {
            return;
        }

        // notAioManaged() only, not eligibleForProbe(): explicit IDs here can come from a manual
        // bulk "Probe Streams" action that intentionally bypasses probe_enabled — the automatic/
        // playlist-driven callers (ProbeStreams) already pre-filter by probe_enabled themselves
        // before building the ID list. AIOStreams content must never be probed either way.
        $channels = $this->channelIds ? Channel::whereIn('id', $this->channelIds)->notAioManaged()->get() : collect();
        $episodes = $this->episodeIds ? Episode::whereIn('id', $this->episodeIds)->notAioManaged()->get() : collect();

        $probedCount = 0;

        foreach ($channels as $channel) {
            if ($breaker?->isTripped()) {
                break;
            }

            if ($this->probe($channel, $breaker)) {
                $probedCount++;
            }
        }

        foreach ($episodes as $episode) {
            if ($breaker?->isTripped()) {
                break;
            }

            if ($this->probe($episode, $breaker)) {
                $probedCount++;
                $episode->shareStreamStatsWith($this->episodeSiblings[$episode->id] ?? []);
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

    /**
     * Probe one item and persist the outcome. A failure is stored as stream_stats_probed_at with
     * no stream_stats (the "Probe failed" state the tables already filter on), so incremental
     * runs skip it until its retry window passes. An item that still has stats of its own from
     * an earlier probe keeps them; only inferred (copied) stats are replaced by the failure.
     */
    private function probe(Channel|Episode $item, ?ProbeCircuitBreaker $breaker): bool
    {
        $isRetryOfFailure = $item->stream_stats_probed_at !== null && empty($item->stream_stats);
        $hasInferredStats = $item instanceof Episode && $item->stream_stats_inferred_from_id !== null;

        $stats = $this->withProviderThrottling(
            fn () => $item->probeStreamStats($this->probeTimeout)
        );
        $succeeded = ! empty($stats);

        if (! $isRetryOfFailure) {
            $breaker?->record($succeeded);
        }

        $clearInferred = $item instanceof Episode ? ['stream_stats_inferred_from_id' => null] : [];

        if ($succeeded) {
            $item->updateQuietly([
                'stream_stats' => $stats,
                'stream_stats_probed_at' => now(),
                ...$clearInferred,
            ]);
        } elseif (empty($item->stream_stats) || $hasInferredStats) {
            $item->updateQuietly([
                'stream_stats' => null,
                'stream_stats_probed_at' => now(),
                ...$clearInferred,
            ]);
        }

        return $succeeded;
    }

    public function failed(Throwable $exception): void
    {
        Log::error("Stream probe chunk job failed: {$exception->getMessage()}");

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
