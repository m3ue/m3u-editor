<?php

namespace App\Traits;

use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait ProviderRequestDelay
{
    /**
     * Cache key for tracking concurrent requests.
     */
    private static string $concurrencyKey = 'provider_concurrent_requests';

    /**
     * Apply delay before making a request to the provider.
     * This can help avoid rate limiting by providers.
     */
    protected function applyProviderRequestDelay(): void
    {
        $settings = app(GeneralSettings::class);

        if (!$settings->enable_provider_request_delay) {
            return;
        }

        // Apply request delay if configured
        if ($settings->provider_request_delay_ms > 0) {
            $delayMs = $settings->provider_request_delay_ms;
            Log::debug("Applying provider request delay: {$delayMs}ms");
            // Convert milliseconds to microseconds for usleep
            usleep($delayMs * 1000);
        }
    }

    /**
     * Acquire a slot for concurrent request limiting.
     * Will wait if max concurrent requests are reached.
     *
     * @return string|null The lock key if acquired, null if concurrency limiting is disabled
     */
    protected function acquireProviderRequestSlot(): ?string
    {
        $settings = app(GeneralSettings::class);

        if (!$settings->enable_provider_request_delay) {
            return null;
        }

        $maxConcurrent = $settings->provider_max_concurrent_requests ?? 2;
        $lockKey = self::$concurrencyKey . ':' . uniqid('', true);
        $maxWaitTime = 60; // Maximum wait time in seconds
        $startTime = time();

        while (true) {
            // Get current count of active requests
            $activeRequests = Cache::get(self::$concurrencyKey . ':count', 0);

            if ($activeRequests < $maxConcurrent) {
                // Try to increment atomically
                $newCount = Cache::increment(self::$concurrencyKey . ':count');

                // Double-check we didn't exceed the limit due to race condition
                if ($newCount <= $maxConcurrent) {
                    Log::debug("Provider request slot acquired. Active requests: {$newCount}/{$maxConcurrent}");
                    return $lockKey;
                } else {
                    // We exceeded, decrement and wait
                    Cache::decrement(self::$concurrencyKey . ':count');
                }
            }

            // Check if we've waited too long
            if ((time() - $startTime) >= $maxWaitTime) {
                Log::warning("Provider request slot acquisition timed out after {$maxWaitTime}s. Proceeding anyway.");
                return null;
            }

            // Wait a bit before trying again (100ms)
            usleep(100000);
        }
    }

    /**
     * Release a slot for concurrent request limiting.
     *
     * @param string|null $lockKey The lock key returned by acquireProviderRequestSlot
     */
    protected function releaseProviderRequestSlot(?string $lockKey): void
    {
        if ($lockKey === null) {
            return;
        }

        $currentCount = Cache::decrement(self::$concurrencyKey . ':count');

        // Ensure count doesn't go negative
        if ($currentCount < 0) {
            Cache::put(self::$concurrencyKey . ':count', 0, 300);
            $currentCount = 0;
        }

        Log::debug("Provider request slot released. Active requests: {$currentCount}");
    }

    /**
     * Execute a callback with provider request throttling.
     * This combines delay and concurrency limiting.
     *
     * @param callable $callback The callback to execute
     * @return mixed The result of the callback
     */
    protected function withProviderThrottling(callable $callback): mixed
    {
        // Acquire a slot (will wait if necessary)
        $lockKey = $this->acquireProviderRequestSlot();

        try {
            // Apply delay before the request
            $this->applyProviderRequestDelay();

            // Execute the callback
            return $callback();
        } finally {
            // Always release the slot
            $this->releaseProviderRequestSlot($lockKey);
        }
    }
}
