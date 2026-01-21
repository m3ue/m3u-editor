<?php

namespace App\Jobs;

use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Services\XtreamService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UpdateXtreamStats implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public $cacheKey = '';

    public function __construct(
        public Playlist|PlaylistAlias $playlist
    ) {
        $this->cacheKey = ($playlist instanceof Playlist ? 'p' : 'a').":{$playlist->id}:xtream_status";
    }

    // Use a time-based retry instead of "tries" to avoid MaxAttemptsExceededException
    public function retryUntil()
    {
        return now()->addSeconds(15); // Retry for up to 15 seconds
    }

    /**
     * Prevents multiple jobs for this playlist from entering the queue.
     */
    public function uniqueId(): string
    {
        return ($this->playlist instanceof Playlist ? 'p_' : 'a_').$this->playlist->id;
    }

    public function handle(): void
    {
        $playlist = $this->playlist;
        $type = $playlist instanceof Playlist ? 'playlist' : 'playlist_alias';

        // 1. Check cache first - if recently updated, bail immediately
        if (Cache::has($this->cacheKey)) {
            return;
        }

        // 2. Fetch fresh data
        $results = $this->fetchXtreamData($playlist, $type);

        // 3. Update DB and Cache
        if (! empty($results)) {
            $playlist->update(['xtream_status' => $results]);
            Cache::put($this->cacheKey, $results, 5); // 5 second cache
        }
    }

    /**
     * Summary of fetchXtreamData
     *
     * @param  mixed  $playlist
     * @param  mixed  $type
     */
    protected function fetchXtreamData($playlist, $type): array
    {
        try {
            $config = ($type === 'playlist') ? $playlist->xtream_config : $playlist->getPrimaryXtreamConfig();
            if (! $config) {
                return [];
            }

            $xtream = XtreamService::make(xtream_config: $config);

            return $xtream ? ($xtream->userInfo(timeout: 3) ?: []) : [];
        } catch (\Exception $e) {
            Cache::delete($this->cacheKey); // Allow retry on next job run
            Log::error("Failed Xtream fetch for {$type} {$playlist->id}", ['exception' => $e]);

            return [];
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Cache::delete($this->cacheKey); // Allow retry on next job run
        Log::error("Xtream sync failed: {$exception->getMessage()}");
    }
}
