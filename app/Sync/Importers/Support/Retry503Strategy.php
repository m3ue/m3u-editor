<?php

namespace App\Sync\Importers\Support;

use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Detects upstream HTTP 503 failures and (when enabled) schedules a delayed
 * ProcessM3uImport retry with bounded attempt count and cooldown.
 *
 * Stateless: both methods are static and reach for config values at call time.
 */
final class Retry503Strategy
{
    /**
     * Heuristic 503 detection: prefers an attached Response status, falls back
     * to scanning the exception message for known 503 substrings.
     */
    public static function isHttp503(Throwable $e): bool
    {
        if ($e instanceof RequestException) {
            $response = $e->response;
            if ($response) {
                return $response->status() === 503;
            }
        }

        return Str::contains($e->getMessage(), [
            'status code 503',
            'HTTP request returned status code 503',
            '503 Service Temporarily Unavailable',
            ' 503:',
        ]);
    }

    /**
     * Schedule a delayed ProcessM3uImport retry honoring per-playlist attempt
     * count and cooldown gates plus a randomized backoff window. No-ops if
     * the feature is disabled in config or any gate trips.
     */
    public static function scheduleRetry(Playlist $playlist): void
    {
        if (! (bool) config('dev.auto_retry_503_enabled', true)) {
            return;
        }

        $maxAttempts = (int) config('dev.auto_retry_503_max', 3);
        $cooldown = (int) config('dev.auto_retry_503_cooldown_minutes', 10);

        if (($playlist->auto_retry_503_count ?? 0) >= $maxAttempts) {
            return;
        }

        if ($playlist->auto_retry_503_last_at && $playlist->auto_retry_503_last_at->diffInMinutes(now()) < $cooldown) {
            return;
        }

        $playlist->update([
            'auto_retry_503_count' => ($playlist->auto_retry_503_count ?? 0) + 1,
            'auto_retry_503_last_at' => now(),
        ]);

        $minSeconds = (int) config('dev.auto_retry_503_delay_min_seconds', 300);
        $maxSeconds = (int) config('dev.auto_retry_503_delay_max_seconds', 900);
        $delay = random_int($minSeconds, $maxSeconds);

        dispatch(new ProcessM3uImport($playlist))
            ->delay(now()->addSeconds($delay));
    }
}
