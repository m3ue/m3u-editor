<?php

namespace App\Traits;

use App\Settings\GeneralSettings;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait ProviderRequestDelay
{
    /**
     * Cache key for tracking concurrent requests.
     */
    private static string $concurrencyKey = 'provider_concurrent_requests';

    /**
     * Lock timeout in seconds.
     *
     * This determines how long a slot reservation persists if not explicitly released.
     * Set to 5 minutes to handle long-running requests while ensuring cleanup if a
     * process crashes. This should be longer than the longest expected request duration.
     */
    private static int $lockTtlSeconds = 300;

    /**
     * Apply delay before making a request to the provider.
     * This can help avoid rate limiting by providers.
     */
    protected function applyProviderRequestDelay(): void
    {
        $settings = app(GeneralSettings::class);

        if (! $settings->enable_provider_request_delay) {
            return;
        }

        // Apply request delay if configured (with null safety)
        $delayMs = $settings->provider_request_delay_ms ?? 0;
        if ($delayMs > 0) {
            Log::debug("Applying provider request delay: {$delayMs}ms");
            // Convert milliseconds to microseconds for usleep
            usleep($delayMs * 1000);
        }
    }

    /**
     * Acquire a slot for concurrent request limiting using atomic locking.
     * Will wait if max concurrent requests are reached.
     *
     * Uses Cache::lock() for thread-safe slot acquisition to prevent race conditions.
     *
     * @return string|null The lock key if acquired, null if concurrency limiting is disabled
     */
    protected function acquireProviderRequestSlot(): ?string
    {
        $settings = app(GeneralSettings::class);

        if (! $settings->enable_provider_request_delay) {
            return null;
        }

        $maxConcurrent = $settings->provider_max_concurrent_requests ?? 2;
        $slotKey = self::$concurrencyKey.':slot:'.uniqid('', true);
        $countKey = self::$concurrencyKey.':count';
        $lockKey = self::$concurrencyKey.':lock';
        $maxWaitTime = 60; // Maximum wait time in seconds
        $startTime = time();

        while (true) {
            // Use atomic lock to prevent race conditions
            $lock = Cache::lock($lockKey, 10); // 10 second lock timeout

            if ($lock->get()) {
                try {
                    $activeRequests = (int) Cache::get($countKey, 0);

                    if ($activeRequests < $maxConcurrent) {
                        // Safely increment within the lock
                        Cache::put($countKey, $activeRequests + 1, self::$lockTtlSeconds);
                        Log::debug('Provider request slot acquired. Active requests: '.($activeRequests + 1)."/{$maxConcurrent}");

                        return $slotKey;
                    }
                } finally {
                    $lock->release();
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
     * @param  string|null  $slotKey  The slot key returned by acquireProviderRequestSlot
     */
    protected function releaseProviderRequestSlot(?string $slotKey): void
    {
        if ($slotKey === null) {
            return;
        }

        $countKey = self::$concurrencyKey.':count';
        $lockKey = self::$concurrencyKey.':lock';

        // Use atomic lock for safe decrement
        $lock = Cache::lock($lockKey, 10);

        if ($lock->get()) {
            try {
                $currentCount = (int) Cache::get($countKey, 0);
                $newCount = max(0, $currentCount - 1); // Ensure count doesn't go negative
                Cache::put($countKey, $newCount, self::$lockTtlSeconds);
                Log::debug("Provider request slot released. Active requests: {$newCount}");
            } finally {
                $lock->release();
            }
        } else {
            // Fallback if lock acquisition fails - just try to decrement
            $currentCount = Cache::decrement($countKey);
            if ($currentCount < 0) {
                Cache::put($countKey, 0, self::$lockTtlSeconds);
            }
            Log::debug('Provider request slot released (fallback). Active requests: '.max(0, $currentCount));
        }
    }

    /**
     * Execute a callback with provider request throttling.
     * This combines delay and concurrency limiting for safe provider access.
     *
     * @param  callable  $callback  The callback to execute
     * @return mixed The result of the callback
     */
    protected function withProviderThrottling(callable $callback): mixed
    {
        // Acquire a slot (will wait if necessary)
        $slotKey = $this->acquireProviderRequestSlot();

        try {
            // Apply delay before the request
            $this->applyProviderRequestDelay();

            // Execute the callback with retry for transient HTTP errors
            return $this->callWithHttpRetry($callback);
        } finally {
            // Always release the slot
            $this->releaseProviderRequestSlot($slotKey);
        }
    }

    /**
     * Execute an HTTP callback with exponential backoff for transient 5xx / 429 errors.
     * Retries up to $maxAttempts times with delays of 1 s, 2 s, 4 s, …
     */
    private function callWithHttpRetry(callable $callback, int $maxAttempts = 3): mixed
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $callback();
            } catch (RequestException $e) {
                $lastException = $e;
                $status = $e->response?->status();

                if ($attempt < $maxAttempts && in_array($status, [429, 502, 503, 504])) {
                    $delayMs = 1000 * (2 ** ($attempt - 1)); // 1 000 ms, 2 000 ms
                    Log::info("HTTP {$status} on attempt {$attempt}/{$maxAttempts}, retrying in {$delayMs}ms");
                    usleep($delayMs * 1000);

                    continue;
                }

                throw $e;
            }
        }

        throw $lastException; // @phpstan-ignore-line — only reached if $maxAttempts < 1
    }
}
