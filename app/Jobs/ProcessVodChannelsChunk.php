<?php

namespace App\Jobs;

use App\Exceptions\XtreamRateLimitedException;
use App\Models\Playlist;
use App\Services\XtreamService;
use App\Traits\ProviderRequestDelay;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessVodChannelsChunk implements ShouldQueue
{
    use Batchable;
    use ProviderRequestDelay;
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    // Giving a timeout of 10 minutes per chunk (100 channels)
    public $timeout = 60 * 10;

    /**
     * Cache keys tracking per-channel metadata outcomes across every chunk of one
     * run, so the batch-finally callback can tell "every channel failed" apart
     * from "every chunk job ran" (Channel::fetchMetadata() swallows provider
     * failures and returns false instead of throwing, so the chunk job itself
     * never fails and Bus::batch()'s own failedJobs count stays at zero).
     */
    public static function attemptedCacheKey(int $playlistId): string
    {
        return "vod_metadata_sync:{$playlistId}:attempted";
    }

    public static function failedCacheKey(int $playlistId): string
    {
        return "vod_metadata_sync:{$playlistId}:failed";
    }

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
        public array $channelIds,
        public int $chunkIndex,
        public int $totalChunks,
        public bool $force = false,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(XtreamService $xtream): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $playlist = $this->playlist;

        // Refresh the playlist to get the latest state
        $playlist->refresh();

        $xtream = $xtream->init(
            playlist: $playlist,
            retryLimit: 5
        );
        if (! $xtream) {
            Log::error('Xtream service initialization failed for playlist ID '.$playlist->id.' in VOD chunk '.$this->chunkIndex);

            return;
        }

        // Get the channels for this chunk. Loaded eagerly (a chunk is only ever
        // CHUNK_SIZE rows) rather than via ->cursor(): a cursor keeps a read
        // statement open for the whole loop, and in SQLite WAL mode a connection
        // holding an open read is refused a write as soon as any other connection
        // commits in between - busy_timeout never kicks in, so the very next
        // progress write below throws "database is locked" instantly.
        $channels = $playlist->channels()
            ->whereIn('id', $this->channelIds)
            ->get();

        $totalChannels = count($this->channelIds);

        foreach ($channels as $index => $channel) {
            try {
                // Use provider throttling to limit concurrent requests and apply delay
                // skipTmdb=true: TMDB IDs are fetched in bulk from ProcessVodChannelsComplete
                $fetched = $this->withProviderThrottling(fn () => $channel->fetchMetadata($xtream, skipTmdb: true));

                Cache::increment(self::attemptedCacheKey($playlist->id));
                if (! $fetched) {
                    Cache::increment(self::failedCacheKey($playlist->id));
                }
            } catch (XtreamRateLimitedException $e) {
                // Account-wide cooldown: every remaining channel in this chunk (and
                // every later chunk in the batch) would fail the same way. Cancel the
                // batch so pending chunks aren't dispatched only to fail immediately,
                // then rethrow so this chunk is recorded as failed.
                Log::warning('ProcessVodChannelsChunk: aborting chunk, Xtream account is rate limited', [
                    'playlist_id' => $playlist->id,
                    'chunk_index' => $this->chunkIndex,
                    'retry_at' => $e->retryAt->toIso8601String(),
                ]);

                $this->batch()?->cancel();

                throw $e;
            } catch (\Exception $e) {
                // Log the error and continue processing other channels
                Log::error('Failed to process VOD data for channel ID '.$channel->id.' in chunk '.$this->chunkIndex.': '.$e->getMessage());

                Cache::increment(self::attemptedCacheKey($playlist->id));
                Cache::increment(self::failedCacheKey($playlist->id));

                // Notify user about the specific error but continue processing
                Notification::make()
                    ->title('VOD Processing Warning')
                    ->body('Failed to process VOD data for channel: '.$channel->name.'. Continuing with remaining channels.')
                    ->warning()
                    ->broadcast($playlist->user)
                    ->sendToDatabase($playlist->user);

                // Continue processing other channels instead of failing the entire chunk
                continue;
            }

            // Update progress every 10 channels processed
            if ($index % 10 === 0) {
                // Calculate overall progress: chunks already done + progress in current chunk
                $chunkProgress = ($index / max(1, $totalChannels));
                $overallProgress = (($this->chunkIndex + $chunkProgress) / $this->totalChunks) * 100;

                $this->updateVodProgress($playlist, $overallProgress);
            }

            // Note: Provider throttling is now handled by withProviderThrottling() above
        }

        // Update progress after this chunk is complete
        $chunkCompleteProgress = (($this->chunkIndex + 1) / $this->totalChunks) * 100;
        $this->updateVodProgress($playlist, $chunkCompleteProgress);

        Log::info('Completed VOD chunk '.($this->chunkIndex + 1).' of '.$this->totalChunks.' for playlist ID '.$playlist->id);
    }

    /**
     * Update the playlist's vod_progress. Best-effort - a transient write failure
     * here must not fail the whole chunk (and the channel metadata already fetched
     * with it), so failures are logged and swallowed rather than rethrown.
     */
    protected function updateVodProgress(Playlist $playlist, float $progress): void
    {
        try {
            $playlist->update(['vod_progress' => min(99, $progress)]); // Never exceed 99% until ProcessVodChannelsComplete
        } catch (\Exception $e) {
            Log::warning('ProcessVodChannelsChunk: failed to update vod_progress', [
                'playlist_id' => $playlist->id,
                'chunk_index' => $this->chunkIndex,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
