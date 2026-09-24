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

        // 2. An alias with no provider account to query (no credentials of its own and no
        // Xtream source) drops any status left from credentials that were since removed.
        if ($playlist instanceof PlaylistAlias && ! $this->resolveConfig($playlist, $type)) {
            if ($playlist->getRawOriginal('xtream_status') !== null) {
                $playlist->update(['xtream_status' => null]);
            }
            Cache::put($this->cacheKey, [], 60);

            return;
        }

        // 3. Fetch fresh data
        $results = $this->fetchXtreamData($playlist, $type);

        // 4. Update DB and Cache
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
            $config = $this->resolveConfig($playlist, $type);
            if (! $config) {
                return [];
            }

            $xtream = XtreamService::make(xtream_config: $config);

            return $xtream ? ($xtream->userInfo(timeout: 3) ?: []) : [];
        } catch (\Exception $e) {
            Cache::delete($this->cacheKey); // Allow retry on next job run
            Log::error("Failed Xtream fetch for {$type} {$playlist->id}", ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * The provider account to query. An alias without credentials of its own streams
     * with its source playlist's account, so that account's status is the one to show.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveConfig(Playlist|PlaylistAlias $playlist, string $type): ?array
    {
        if ($type === 'playlist') {
            return $playlist->xtream_config;
        }

        $sourcePlaylist = $playlist->getEffectivePlaylist();

        return $playlist->getPrimaryCredentialConfig()
            ?? ($sourcePlaylist instanceof Playlist ? $sourcePlaylist->xtream_config : null);
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
