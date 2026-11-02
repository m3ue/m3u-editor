<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Drops a sync job, and every job chained after it, once the user has reset
 * the sync it belongs to ("Reset status", "Restart Now"). A reset only cleared
 * the status columns, so jobs already queued kept running beside the sync the
 * user started next and called the provider twice (#1806).
 *
 * Only a reset recorded after the sync was queued counts. With no reset on
 * record, including after a cache clear, the job runs as before.
 */
class SkipIfSyncReset
{
    /**
     * How long a reset is remembered: longer than any sync takes to drain.
     */
    private const RESET_TTL_HOURS = 24;

    /**
     * @param  class-string<Model>  $modelClass  the playlist, EPG or EPG map being synced
     * @param  float  $queuedAt  when the sync was queued, as microtime(true)
     */
    public function __construct(
        public string $modelClass,
        public int $modelId,
        public float $queuedAt,
    ) {}

    /**
     * Record that the user reset $model: its syncs queued before now stop at
     * their next job.
     */
    public static function recordReset(Model $model): void
    {
        Cache::put(
            self::cacheKey($model::class, $model->getKey()),
            microtime(true),
            now()->addHours(self::RESET_TTL_HOURS),
        );
    }

    /**
     * Add this guard to every job of a chain.
     *
     * @template TJob of object
     *
     * @param  array<int, TJob>  $jobs
     * @return array<int, TJob>
     */
    public function guard(array $jobs): array
    {
        foreach ($jobs as $job) {
            $job->middleware[] = $this;
        }

        return $jobs;
    }

    public function handle(object $job, Closure $next): mixed
    {
        $resetAt = Cache::get(self::cacheKey($this->modelClass, $this->modelId));

        if (is_numeric($resetAt) && (float) $resetAt > $this->queuedAt) {
            Log::info('Skipping sync job: its sync was reset after it was queued', [
                'job' => $job::class,
                'model' => class_basename($this->modelClass),
                'model_id' => $this->modelId,
            ]);

            // The worker dispatches the next chained job once this one returns
            $job->chained = [];

            return null;
        }

        return $next($job);
    }

    private static function cacheKey(string $modelClass, int|string $modelId): string
    {
        return 'sync-reset:'.class_basename($modelClass).':'.$modelId;
    }
}
