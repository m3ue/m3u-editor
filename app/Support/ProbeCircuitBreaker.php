<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Run-scoped failure-rate breaker for automatic VOD/series probing. Every chunk of one
 * ProbeStreams run shares the same counters (atomic cache increments), so a run in batch
 * mode trips as one unit. Once at least MIN_SAMPLE first-time probes have run and the share
 * of failures exceeds the threshold, the breaker trips and the remaining chunks stop early,
 * which bounds how many items a provider outage can mark as failed.
 *
 * Retries of previously failed items are not counted: they are known failures and would
 * otherwise trip the breaker on a healthy provider.
 */
class ProbeCircuitBreaker
{
    public const MIN_SAMPLE = 20;

    private const TTL_DAYS = 7;

    public function __construct(
        public readonly string $runKey,
        public readonly int $thresholdPercent,
    ) {}

    /**
     * Breaker for a run, or null when the run has no key (manual probes) or the threshold is
     * disabled (0) or not below 100%.
     */
    public static function forRun(?string $runKey, int $thresholdPercent): ?self
    {
        if (! $runKey || $thresholdPercent <= 0 || $thresholdPercent >= 100) {
            return null;
        }

        return new self($runKey, $thresholdPercent);
    }

    public function record(bool $succeeded): void
    {
        $attempted = $this->increment('attempted');
        $failed = $succeeded ? $this->count('failed') : $this->increment('failed');

        if ($attempted >= self::MIN_SAMPLE && ($failed * 100) > ($attempted * $this->thresholdPercent)) {
            Cache::put($this->key('tripped'), true, now()->addDays(self::TTL_DAYS));
        }
    }

    public function isTripped(): bool
    {
        return (bool) Cache::get($this->key('tripped'), false);
    }

    /**
     * @return array{attempted: int, failed: int, tripped: bool}
     */
    public function summary(): array
    {
        return [
            'attempted' => $this->count('attempted'),
            'failed' => $this->count('failed'),
            'tripped' => $this->isTripped(),
        ];
    }

    public function forget(): void
    {
        foreach (['attempted', 'failed', 'tripped'] as $metric) {
            Cache::forget($this->key($metric));
        }
    }

    private function increment(string $metric): int
    {
        $key = $this->key($metric);

        Cache::add($key, 0, now()->addDays(self::TTL_DAYS));

        return (int) Cache::increment($key);
    }

    private function count(string $metric): int
    {
        return (int) Cache::get($this->key($metric), 0);
    }

    private function key(string $metric): string
    {
        return "probe-run:{$this->runKey}:{$metric}";
    }
}
