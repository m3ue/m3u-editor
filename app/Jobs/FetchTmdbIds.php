<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Series;
use App\Models\User;
use App\Services\TmdbService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Job to fetch TMDB/TVDB/IMDB IDs for VOD channels and Series.
 * Can process a single item or a batch of items.
 */
class FetchTmdbIds implements ShouldQueue
{
    use Batchable, Queueable;

    public const BATCH_CHUNK_SIZE = 100;

    protected const BATCH_STATS_TTL_HOURS = 6;

    public int $batchChunkSize = self::BATCH_CHUNK_SIZE;

    public $tries = 1;

    public $timeout = 60 * 15; // 15 minutes per chunk

    protected int $foundCount = 0;

    protected int $notFoundCount = 0;

    protected int $skippedCount = 0;

    protected int $errorCount = 0;

    /**
     * Create a new job instance.
     *
     * @param  Collection|array|null  $vodChannelIds  VOD channel IDs to process (legacy support)
     * @param  Collection|array|null  $seriesIds  Series IDs to process (legacy support)
     * @param  int|null  $vodPlaylistId  Playlist ID for VOD channels
     * @param  int|null  $seriesPlaylistId  Playlist ID for series
     * @param  bool  $allVodPlaylists  Process all VOD from all user playlists
     * @param  bool  $allSeriesPlaylists  Process all series from all user playlists
     * @param  bool  $overwriteExisting  Whether to overwrite existing IDs
     * @param  User|null  $user  The user to notify upon completion
     */
    public function __construct(
        public Collection|array|null $vodChannelIds = null,
        public Collection|array|null $seriesIds = null,
        public ?int $vodPlaylistId = null,
        public ?int $seriesPlaylistId = null,
        public bool $allVodPlaylists = false,
        public bool $allSeriesPlaylists = false,
        public bool $overwriteExisting = false,
        public ?User $user = null,
        public bool $isChunkJob = false,
        public bool $sendCompletionNotification = true,
    ) {
        // Legacy support: convert Collections to arrays
        if ($this->vodChannelIds instanceof Collection) {
            $this->vodChannelIds = $this->vodChannelIds->toArray();
        }
        if ($this->seriesIds instanceof Collection) {
            $this->seriesIds = $this->seriesIds->toArray();
        }
    }

    /**
     * Execute the job.
     */
    public function handle(TmdbService $tmdb): void
    {
        if (! $tmdb->isConfigured()) {
            Log::warning('FetchTmdbIds: TMDB API key not configured');
            $this->notifyUser('TMDB Lookup Failed', 'TMDB API key is not configured. Please add your API key in Settings.', 'danger');

            return;
        }

        if (! $this->isChunkJob && $this->shouldRunAsBatchedChunks()) {
            $this->dispatchBatchedChunks();

            return;
        }

        // Process VOD channels (new playlist-based or legacy ID-based)
        if ($this->vodPlaylistId || $this->allVodPlaylists || ! empty($this->vodChannelIds)) {
            $this->processVodChannels($tmdb);
        }

        // Process Series (new playlist-based or legacy ID-based)
        if ($this->seriesPlaylistId || $this->allSeriesPlaylists || ! empty($this->seriesIds)) {
            $this->processSeries($tmdb);
        }

        if ($this->isChunkJob) {
            $this->recordBatchStats();
        }

        // Send completion notification
        if ($this->sendCompletionNotification) {
            $this->sendCompletionNotification();
        }
    }

    /**
     * Determine whether this run should be split into multiple chunk jobs.
     */
    protected function shouldRunAsBatchedChunks(): bool
    {
        return $this->getTargetCount() > $this->batchChunkSize;
    }

    /**
     * Dispatch child chunk jobs in a Laravel batch to avoid long-running single jobs.
     */
    protected function dispatchBatchedChunks(): void
    {
        $jobs = collect();

        $this->queueVodChunkJobs($jobs);
        $this->queueSeriesChunkJobs($jobs);

        if ($jobs->isEmpty()) {
            if ($this->sendCompletionNotification) {
                $this->sendCompletionNotification();
            }

            return;
        }

        $chunkCount = $jobs->count();
        $userId = $this->user?->id;

        Bus::batch($jobs->all())
            ->onConnection('redis') // force to use redis connection
            ->onQueue('import')
            ->allowFailures()
            ->finally(function (Batch $batch) use ($userId): void {
                if (! $userId) {
                    return;
                }

                $user = User::find($userId);

                if (! $user) {
                    return;
                }

                $stats = self::readBatchStats($batch->id);
                $failedJobs = $batch->failedJobs;
                $total = $stats['found'] + $stats['not_found'] + $stats['skipped'] + $stats['errors'];

                $body = sprintf(
                    'Found: %d | Not found: %d | Skipped (already had IDs): %d | Errors: %d',
                    $stats['found'],
                    $stats['not_found'],
                    $stats['skipped'],
                    $stats['errors']
                );

                if ($failedJobs > 0) {
                    $body .= " | Failed chunks: {$failedJobs}";
                }

                $notification = Notification::make()
                    ->title("TMDB ID Lookup Complete ({$total} processed)")
                    ->body($body);

                if ($failedJobs > 0 || $stats['errors'] > 0) {
                    $notification->warning();
                } else {
                    $notification->success();
                }

                $notification
                    ->broadcast($user)
                    ->sendToDatabase($user);

                self::clearBatchStats($batch->id);
            })
            ->dispatch();

        $this->notifyUser(
            'TMDB ID Lookup Started',
            "Large lookup split into {$chunkCount} jobs and queued for processing. You will receive a notification when the batch is complete.",
            'info'
        );
    }

    /**
     * Queue chunk jobs for VOD channels.
     */
    protected function queueVodChunkJobs(Collection $jobs): void
    {
        $query = $this->buildVodQuery();

        if (! $query) {
            return;
        }

        $query->select('id')
            ->orderBy('id')
            ->chunkById($this->batchChunkSize, function (Collection $channels) use ($jobs): void {
                $ids = $channels->pluck('id')->all();

                if (empty($ids)) {
                    return;
                }

                $jobs->push(new self(
                    vodChannelIds: $ids,
                    seriesIds: null,
                    overwriteExisting: $this->overwriteExisting,
                    user: $this->user,
                    isChunkJob: true,
                    sendCompletionNotification: false,
                ));
            });
    }

    /**
     * Queue chunk jobs for series.
     */
    protected function queueSeriesChunkJobs(Collection $jobs): void
    {
        $query = $this->buildSeriesQuery();

        if (! $query) {
            return;
        }

        $query->select('id')
            ->orderBy('id')
            ->chunkById($this->batchChunkSize, function (Collection $seriesChunk) use ($jobs): void {
                $ids = $seriesChunk->pluck('id')->all();

                if (empty($ids)) {
                    return;
                }

                $jobs->push(new self(
                    vodChannelIds: null,
                    seriesIds: $ids,
                    overwriteExisting: $this->overwriteExisting,
                    user: $this->user,
                    isChunkJob: true,
                    sendCompletionNotification: false,
                ));
            });
    }

    /**
     * Build filtered VOD query based on job arguments.
     */
    protected function buildVodQuery()
    {
        $query = Channel::where('is_vod', true);

        // Use playlist-based filtering if provided
        if ($this->vodPlaylistId) {
            $query->where('playlist_id', $this->vodPlaylistId)
                ->where('user_id', $this->user?->id)
                ->where('enabled', true);
        } elseif ($this->allVodPlaylists && $this->user) {
            $query->whereHas('playlist', function ($q) {
                $q->where('user_id', $this->user->id);
            })->where('enabled', true);
        } elseif (! empty($this->vodChannelIds)) {
            // Legacy: direct ID array support
            $query->whereIn('id', $this->vodChannelIds)
                ->where('user_id', $this->user?->id);
        } else {
            return null; // No criteria specified
        }

        return $query;
    }

    /**
     * Build filtered series query based on job arguments.
     */
    protected function buildSeriesQuery()
    {
        $query = Series::query();

        // Use playlist-based filtering if provided
        if ($this->seriesPlaylistId) {
            $query->where('playlist_id', $this->seriesPlaylistId)
                ->where('user_id', $this->user?->id)
                ->where('enabled', true);
        } elseif ($this->allSeriesPlaylists && $this->user) {
            $query->where('user_id', $this->user->id)
                ->where('enabled', true);
        } elseif (! empty($this->seriesIds)) {
            // Legacy: direct ID array support
            $query->whereIn('id', $this->seriesIds)
                ->where('user_id', $this->user?->id);
        } else {
            return null; // No criteria specified
        }

        return $query;
    }

    /**
     * Get count of records targeted by this lookup request.
     */
    protected function getTargetCount(): int
    {
        $count = 0;

        $vodQuery = $this->buildVodQuery();
        if ($vodQuery) {
            $count += (clone $vodQuery)->count();
        }

        $seriesQuery = $this->buildSeriesQuery();
        if ($seriesQuery) {
            $count += (clone $seriesQuery)->count();
        }

        return $count;
    }

    /**
     * Process VOD channels to fetch TMDB IDs.
     */
    protected function processVodChannels(TmdbService $tmdb): void
    {
        $query = $this->buildVodQuery();

        if (! $query) {
            return;
        }

        // Use cursor for memory-efficient iteration
        foreach ($query->cursor() as $channel) {
            if (! $channel instanceof Channel) {
                continue;
            }

            try {
                $this->processVodChannel($tmdb, $channel);
            } catch (\Exception $e) {
                $this->errorCount++;
                Log::error('FetchTmdbIds: Error processing VOD channel', [
                    'channel_id' => $channel->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Process a single VOD channel.
     */
    protected function processVodChannel(TmdbService $tmdb, Channel $channel): void
    {
        // Check if already has TMDB ID in the dedicated column
        if ($channel->tmdb_id && ! $this->overwriteExisting) {
            $this->skippedCount++;

            return;
        }

        // Check legacy info/movie_data fields for existing ID
        $legacyTmdbId = $channel->info['tmdb_id']
            ?? $channel->movie_data['tmdb_id']
            ?? null;

        // If legacy ID exists and we're not overwriting, migrate it to the column and skip
        if ($legacyTmdbId && ! $this->overwriteExisting) {
            $channel->update(['tmdb_id' => $legacyTmdbId]);
            $this->skippedCount++;

            return;
        }

        // Get title and year for search
        $title = $channel->title_custom ?? $channel->title ?? $channel->name;
        $year = $channel->year
            ?? $channel->info['year']
            ?? TmdbService::extractYearFromTitle($title);

        if (empty($title)) {
            $this->skippedCount++;

            return;
        }

        // Search TMDB
        $result = $tmdb->searchMovie($title, $year);

        if ($result && isset($result['tmdb_id'])) {
            // Update channel with found IDs - use dedicated columns
            $updateData = [
                'tmdb_id' => $result['tmdb_id'],
            ];

            if (! empty($result['imdb_id'])) {
                $updateData['imdb_id'] = $result['imdb_id'];
            }

            // Also update legacy info field for backward compatibility
            $info = $channel->info ?? [];
            $info['tmdb_id'] = $result['tmdb_id'];
            if (! empty($result['imdb_id'])) {
                $info['imdb_id'] = $result['imdb_id'];
            }
            $updateData['info'] = $info;

            $channel->update($updateData);

            Log::info('FetchTmdbIds: Found TMDB ID for VOD channel', [
                'channel_id' => $channel->id,
                'title' => $title,
                'tmdb_id' => $result['tmdb_id'],
                'confidence' => $result['confidence'] ?? 'N/A',
            ]);

            $this->foundCount++;
        } else {
            Log::debug('FetchTmdbIds: No TMDB match found for VOD channel', [
                'channel_id' => $channel->id,
                'title' => $title,
                'year' => $year,
            ]);
            $this->notFoundCount++;
        }
    }

    /**
     * Process Series to fetch TMDB/TVDB IDs.
     */
    protected function processSeries(TmdbService $tmdb): void
    {
        $query = $this->buildSeriesQuery();

        if (! $query) {
            return;
        }

        // Use cursor for memory-efficient iteration
        foreach ($query->cursor() as $series) {
            if (! $series instanceof Series) {
                continue;
            }

            try {
                $this->processSingleSeries($tmdb, $series);
            } catch (\Exception $e) {
                $this->errorCount++;
                Log::error('FetchTmdbIds: Error processing series', [
                    'series_id' => $series->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Process a single series.
     */
    protected function processSingleSeries(TmdbService $tmdb, Series $series): void
    {
        // Check if already has IDs and we're not overwriting
        // Check both the dedicated columns and the legacy metadata field
        $existingTvdbId = $series->tvdb_id ?? $series->metadata['tvdb_id'] ?? null;
        $existingTmdbId = $series->tmdb_id ?? $series->metadata['tmdb_id'] ?? null;

        if (($existingTvdbId || $existingTmdbId) && ! $this->overwriteExisting) {
            Log::debug('FetchTmdbIds: Skipping series (already has IDs)', [
                'series_id' => $series->id,
                'name' => $series->name,
                'existing_tmdb_id' => $existingTmdbId,
                'existing_tvdb_id' => $existingTvdbId,
                'overwrite_existing' => $this->overwriteExisting,
            ]);
            $this->skippedCount++;

            return;
        }

        // Get name and year for search
        $name = $series->name;
        $year = null;

        if ($series->release_date) {
            $year = (int) substr($series->release_date, 0, 4);
        }

        if (! $year) {
            $year = TmdbService::extractYearFromTitle($name);
        }

        if (empty($name)) {
            Log::debug('FetchTmdbIds: Skipping series (empty name)', [
                'series_id' => $series->id,
            ]);
            $this->skippedCount++;

            return;
        }

        // Log search attempt
        Log::info('FetchTmdbIds: Searching TMDB for series', [
            'series_id' => $series->id,
            'name' => $name,
            'year' => $year,
            'release_date' => $series->release_date,
        ]);

        // Search TMDB
        $result = $tmdb->searchTvSeries($name, $year);

        if ($result && isset($result['tmdb_id'])) {
            // Update series with found IDs - use dedicated columns
            $updateData = [
                'tmdb_id' => $result['tmdb_id'],
            ];

            if (! empty($result['tvdb_id'])) {
                $updateData['tvdb_id'] = $result['tvdb_id'];
            }
            if (! empty($result['imdb_id'])) {
                $updateData['imdb_id'] = $result['imdb_id'];
            }

            // Also update legacy metadata field for backward compatibility
            $metadata = $series->metadata ?? [];
            $metadata['tmdb_id'] = $result['tmdb_id'];
            if (! empty($result['tvdb_id'])) {
                $metadata['tvdb_id'] = $result['tvdb_id'];
            }
            if (! empty($result['imdb_id'])) {
                $metadata['imdb_id'] = $result['imdb_id'];
            }
            $updateData['metadata'] = $metadata;

            $series->update($updateData);

            Log::info('FetchTmdbIds: Successfully found and saved IDs for series', [
                'series_id' => $series->id,
                'name' => $name,
                'tmdb_id' => $result['tmdb_id'],
                'tvdb_id' => $result['tvdb_id'] ?? null,
                'imdb_id' => $result['imdb_id'] ?? null,
                'confidence' => $result['confidence'] ?? 'N/A',
                'matched_name' => $result['name'] ?? null,
            ]);

            $this->foundCount++;
        } else {
            Log::warning('FetchTmdbIds: No TMDB match found for series', [
                'series_id' => $series->id,
                'name' => $name,
                'year' => $year,
                'release_date' => $series->release_date,
                'search_result' => $result,
            ]);
            $this->notFoundCount++;
        }
    }

    /**
     * Send completion notification to user.
     */
    protected function sendCompletionNotification(): void
    {
        $total = $this->foundCount + $this->notFoundCount + $this->skippedCount + $this->errorCount;

        $body = sprintf(
            'Found: %d | Not found: %d | Skipped (already had IDs): %d | Errors: %d',
            $this->foundCount,
            $this->notFoundCount,
            $this->skippedCount,
            $this->errorCount
        );

        $title = "TMDB ID Lookup Complete ({$total} processed)";

        $this->notifyUser($title, $body, $this->errorCount > 0 ? 'warning' : 'success');
    }

    /**
     * Notify the user.
     */
    protected function notifyUser(string $title, string $body, string $type = 'success'): void
    {
        if (! $this->user) {
            return;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body);

        match ($type) {
            'success' => $notification->success(),
            'warning' => $notification->warning(),
            'danger' => $notification->danger(),
            default => $notification->info(),
        };

        $notification
            ->broadcast($this->user)
            ->sendToDatabase($this->user);
    }

    /**
     * Record chunk counters to cache for batch-level completion summary.
     */
    protected function recordBatchStats(): void
    {
        $batchId = $this->batch()?->id;

        if (! $batchId) {
            return;
        }

        self::incrementBatchStat($batchId, 'found', $this->foundCount);
        self::incrementBatchStat($batchId, 'not_found', $this->notFoundCount);
        self::incrementBatchStat($batchId, 'skipped', $this->skippedCount);
        self::incrementBatchStat($batchId, 'errors', $this->errorCount);
    }

    /**
     * Read aggregated chunk counters for the provided batch.
     *
     * @return array{found:int,not_found:int,skipped:int,errors:int}
     */
    protected static function readBatchStats(string $batchId): array
    {
        return [
            'found' => (int) Cache::get(self::batchStatKey($batchId, 'found'), 0),
            'not_found' => (int) Cache::get(self::batchStatKey($batchId, 'not_found'), 0),
            'skipped' => (int) Cache::get(self::batchStatKey($batchId, 'skipped'), 0),
            'errors' => (int) Cache::get(self::batchStatKey($batchId, 'errors'), 0),
        ];
    }

    /**
     * Increment a cached batch counter.
     */
    protected static function incrementBatchStat(string $batchId, string $metric, int $value): void
    {
        if ($value <= 0) {
            return;
        }

        $key = self::batchStatKey($batchId, $metric);
        $ttl = now()->addHours(self::BATCH_STATS_TTL_HOURS);

        Cache::add($key, 0, $ttl);
        Cache::increment($key, $value);
        Cache::put($key, Cache::get($key, 0), $ttl);
    }

    /**
     * Remove cached counters for a completed batch.
     */
    protected static function clearBatchStats(string $batchId): void
    {
        Cache::forget(self::batchStatKey($batchId, 'found'));
        Cache::forget(self::batchStatKey($batchId, 'not_found'));
        Cache::forget(self::batchStatKey($batchId, 'skipped'));
        Cache::forget(self::batchStatKey($batchId, 'errors'));
    }

    /**
     * Build cache key for batch stats metric.
     */
    protected static function batchStatKey(string $batchId, string $metric): string
    {
        return "tmdb-lookup:batch:{$batchId}:{$metric}";
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('FetchTmdbIds job failed', [
            'error' => $exception->getMessage(),
            'vod_playlist_id' => $this->vodPlaylistId,
            'series_playlist_id' => $this->seriesPlaylistId,
            'all_vod_playlists' => $this->allVodPlaylists,
            'all_series_playlists' => $this->allSeriesPlaylists,
        ]);

        $this->notifyUser(
            'TMDB ID Lookup Failed',
            'An error occurred while fetching TMDB IDs: '.$exception->getMessage(),
            'danger'
        );
    }
}
