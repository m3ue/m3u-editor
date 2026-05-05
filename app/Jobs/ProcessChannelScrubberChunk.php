<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\Channel;
use App\Models\ChannelScrubber;
use App\Models\ChannelScrubberLog;
use App\Models\ChannelScrubberLogChannel;
use App\Settings\GeneralSettings;
use App\Traits\ProviderRequestDelay;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;

class ProcessChannelScrubberChunk implements ShouldQueue
{
    use Batchable, ProviderRequestDelay, Queueable;

    public $tries = 1;

    public $deleteWhenMissingModels = true;

    public $timeout = 60 * 60;

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

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
        public int $probeTimeout = 10,
        public bool $disableDead = true,
        public bool $enableLive = false,
    ) {}

    /**
     * Backfill properties that did not exist when older jobs were serialized into the queue.
     */
    public function __wakeup(): void
    {
        if (! isset($this->probeTimeout)) {
            $this->probeTimeout = 10;
        }
        if (! isset($this->disableDead)) {
            $this->disableDead = true;
        }
        if (! isset($this->enableLive)) {
            $this->enableLive = false;
        }
    }

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

        // Snapshot enabled state before probing so we know which were disabled going in
        $wasEnabled = $channels->pluck('enabled', 'id')->map(fn ($v) => (bool) $v)->all();

        $deadIds = $this->checkMethod === 'ffprobe'
            ? $this->probeFfprobeBatch($channels)
            : $this->probeHttpBatch($channels);

        $deadSet = array_flip($deadIds);
        $deadCount = count($deadIds);
        $disabledCount = 0;

        // Process dead channels
        foreach ($deadIds as $channelId) {
            $channel = $channels->firstWhere('id', $channelId);
            if (! $channel) {
                continue;
            }

            try {
                ChannelScrubberLogChannel::create([
                    'channel_scrubber_log_id' => $this->logId,
                    'channel_id' => $channel->id,
                    'title' => $channel->title ?? $channel->name_custom ?? $channel->name ?? '',
                    'url' => ($channel->url_custom ?? $channel->url) ?? '',
                ]);

                if ($this->disableDead && $channel->enabled) {
                    $channel->update(['enabled' => false]);
                    $disabledCount++;
                }
            } catch (Exception $e) {
                Log::warning("Channel scrubber: error processing dead channel #{$channel->id}: {$e->getMessage()}");
            }
        }

        // Count live channels (all non-dead) — always tracked regardless of enableLive
        $liveCount = 0;

        foreach ($channels as $channel) {
            if (isset($deadSet[$channel->id])) {
                continue; // skip dead channels
            }

            $liveCount++;

            // Re-enable disabled channels that are now live (only when enableLive is set)
            if ($this->enableLive && ! ($wasEnabled[$channel->id] ?? true)) {
                try {
                    $channel->update(['enabled' => true]);
                } catch (Exception $e) {
                    Log::warning("Channel scrubber: error re-enabling channel #{$channel->id}: {$e->getMessage()}");
                }
            }
        }

        if ($deadCount > 0) {
            ChannelScrubber::where('id', $this->scrubberId)->increment('dead_count', $deadCount);
        }
        if ($disabledCount > 0) {
            ChannelScrubberLog::where('id', $this->logId)->increment('disabled_count', $disabledCount);
        }
        if ($liveCount > 0) {
            ChannelScrubberLog::where('id', $this->logId)->increment('live_count', $liveCount);
        }

        // Increment progress
        $processed = count($this->channelIds);
        if ($this->totalChannels > 0) {
            $increment = ($processed / $this->totalChannels) * 90;
            $scrubber->refresh();
            $newProgress = min(95, $scrubber->progress + $increment);
            $scrubber->update(['progress' => $newProgress]);
        }
    }

    /**
     * Check all channels via HTTP HEAD requests.
     *
     * When provider throttling is enabled in settings, channels are checked serially
     * and each request is wrapped in withProviderThrottling() so the global slot limit
     * and configured delay are honoured across all concurrent chunk jobs.
     *
     * When throttling is disabled, requests are batched using Http::pool() at a
     * default concurrency of 3 (or provider_max_concurrent_requests if set).
     *
     * @param  Collection<int, Channel>  $channels
     * @return array<int> Dead channel IDs
     */
    private function probeHttpBatch(Collection $channels): array
    {
        $settings = app(GeneralSettings::class);

        if ($settings->enable_provider_request_delay) {
            return $this->probeHttpThrottled($channels);
        }

        return $this->probeHttpParallel($channels, $settings->provider_max_concurrent_requests ?? 3);
    }

    /**
     * Check all channels via ffprobe.
     *
     * When provider throttling is enabled in settings, channels are checked serially
     * and each probe is wrapped in withProviderThrottling() so the global slot limit
     * and configured delay are honoured across all concurrent chunk jobs.
     *
     * When throttling is disabled, ffprobe processes are started concurrently at a
     * default concurrency of 3 (or provider_max_concurrent_requests if set).
     *
     * Uses lightweight probe args (no -show_streams) so ffprobe exits as soon as it
     * identifies the stream format rather than performing a full codec analysis.
     *
     * @param  Collection<int, Channel>  $channels
     * @return array<int> Dead channel IDs
     */
    private function probeFfprobeBatch(Collection $channels): array
    {
        $settings = app(GeneralSettings::class);

        if ($settings->enable_provider_request_delay) {
            return $this->probeFfprobeThrottled($channels);
        }

        return $this->probeFfprobeParallel($channels, $settings->provider_max_concurrent_requests ?? 3);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // HTTP — throttled (serial, withProviderThrottling per request)
    // ──────────────────────────────────────────────────────────────────────────

    /** @return array<int> */
    private function probeHttpThrottled(Collection $channels): array
    {
        $deadIds = [];

        foreach ($channels as $channel) {
            $name = $this->resolveChannelName($channel);
            $url = $this->resolveChannelUrl($channel);

            if ($url === null) {
                $this->logResult('OFF', $name);
                $deadIds[] = $channel->id;

                continue;
            }

            $isDead = $this->withProviderThrottling(function () use ($url) {
                try {
                    $response = Http::timeout($this->probeTimeout)
                        ->withHeaders(['User-Agent' => self::USER_AGENT])
                        ->head($url);

                    return $response->failed();
                } catch (ConnectionException) {
                    return true;
                } catch (Throwable) {
                    return true;
                }
            });

            $this->logResult($isDead ? 'OFF' : 'ON', $name);
            if ($isDead) {
                $deadIds[] = $channel->id;
            }
        }

        return $deadIds;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // HTTP — parallel (Http::pool per batch, no global throttling)
    // ──────────────────────────────────────────────────────────────────────────

    /** @return array<int> */
    private function probeHttpParallel(Collection $channels, int $concurrency): array
    {
        $deadIds = [];

        foreach ($channels->chunk($concurrency) as $batch) {
            $urlMap = $batch->mapWithKeys(fn (Channel $ch) => [
                (string) $ch->id => $this->resolveChannelUrl($ch),
            ])->filter()->all();

            if (empty($urlMap)) {
                $deadIds = array_merge($deadIds, $batch->pluck('id')->all());

                continue;
            }

            try {
                $responses = Http::pool(function (Pool $pool) use ($urlMap) {
                    return collect($urlMap)->map(
                        fn (string $url, string $id) => $pool->as($id)
                            ->timeout($this->probeTimeout)
                            ->withHeaders(['User-Agent' => self::USER_AGENT])
                            ->head($url)
                    )->all();
                });
            } catch (Throwable $e) {
                Log::warning("Channel scrubber HTTP pool error: {$e->getMessage()}");

                continue;
            }

            foreach ($batch as $channel) {
                $name = $this->resolveChannelName($channel);
                $response = $responses[(string) $channel->id] ?? null;

                if ($response instanceof ConnectionException) {
                    $this->logResult('OFF (Timeout)', $name);
                    $deadIds[] = $channel->id;
                } elseif ($response instanceof Throwable || ! $response || $response->failed()) {
                    $this->logResult('OFF', $name);
                    $deadIds[] = $channel->id;
                } else {
                    $this->logResult('ON', $name);
                }
            }
        }

        return $deadIds;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ffprobe — throttled (serial, withProviderThrottling per probe)
    // ──────────────────────────────────────────────────────────────────────────

    /** @return array<int> */
    private function probeFfprobeThrottled(Collection $channels): array
    {
        $deadIds = [];

        foreach ($channels as $channel) {
            $name = $this->resolveChannelName($channel);
            $url = $this->resolveChannelUrl($channel);

            if ($url === null) {
                $this->logResult('OFF', $name);
                $deadIds[] = $channel->id;

                continue;
            }

            [$isDead, $status] = $this->withProviderThrottling(function () use ($url) {
                $process = new SymfonyProcess([
                    'ffprobe', '-v', 'quiet',
                    '-analyzeduration', '1000000',
                    '-probesize', '1000000',
                    '-i', $url,
                ]);
                $process->setTimeout($this->probeTimeout);

                try {
                    $process->run();

                    return $process->getExitCode() !== 0
                        ? [true, 'OFF']
                        : [false, 'ON'];
                } catch (ProcessTimedOutException) {
                    return [true, 'OFF (Timeout)'];
                } catch (Throwable) {
                    return [true, 'OFF (Error)'];
                }
            });

            $this->logResult($status, $name);
            if ($isDead) {
                $deadIds[] = $channel->id;
            }
        }

        return $deadIds;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ffprobe — parallel (async start/wait per batch, no global throttling)
    // ──────────────────────────────────────────────────────────────────────────

    /** @return array<int> */
    private function probeFfprobeParallel(Collection $channels, int $concurrency): array
    {
        $deadIds = [];

        foreach ($channels->chunk($concurrency) as $batch) {
            /** @var array<int, SymfonyProcess|null> $processes */
            $processes = [];

            foreach ($batch as $channel) {
                $url = $this->resolveChannelUrl($channel);
                if ($url === null) {
                    $processes[$channel->id] = null;

                    continue;
                }

                $process = new SymfonyProcess([
                    'ffprobe', '-v', 'quiet',
                    '-analyzeduration', '1000000',
                    '-probesize', '1000000',
                    '-i', $url,
                ]);
                $process->setTimeout($this->probeTimeout);

                try {
                    $process->start(); // non-blocking — all in this batch run in parallel
                } catch (Throwable) {
                    $process = null;
                }

                $processes[$channel->id] = $process;
            }

            foreach ($processes as $channelId => $process) {
                $channel = $channels->firstWhere('id', $channelId);
                $name = $channel ? $this->resolveChannelName($channel) : "#{$channelId}";

                if ($process === null) {
                    $this->logResult('OFF', $name);
                    $deadIds[] = $channelId;

                    continue;
                }

                try {
                    $process->wait();
                    if ($process->getExitCode() !== 0) {
                        $this->logResult('OFF', $name);
                        $deadIds[] = $channelId;
                    } else {
                        $this->logResult('ON', $name);
                    }
                } catch (ProcessTimedOutException) {
                    $this->logResult('OFF (Timeout)', $name);
                    $deadIds[] = $channelId;
                } catch (Throwable) {
                    $this->logResult('OFF (Error)', $name);
                    $deadIds[] = $channelId;
                }
            }
        }

        return $deadIds;
    }

    /**
     * Log a channel probe result in IPTV-CHECK-style format:
     * [      ON       ] Channel Name
     * [      OFF      ] Channel Name
     * [ OFF (Timeout) ] Channel Name
     */
    private function logResult(string $status, string $channelName): void
    {
        $padded = str_pad($status, 15, ' ', STR_PAD_BOTH);
        Log::info("[{$padded}] {$channelName}");
    }

    private function resolveChannelName(Channel $channel): string
    {
        return $channel->title ?? $channel->name_custom ?? $channel->name ?? "#{$channel->id}";
    }

    private function resolveChannelUrl(Channel $channel): ?string
    {
        $url = $channel->url_custom ?? $channel->url;

        return empty($url) ? null : $url;
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("Channel scrubber chunk job failed: {$exception->getMessage()}");
    }
}
