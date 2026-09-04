<?php

namespace App\Jobs;

use App\Exceptions\XtreamRateLimitedException;
use App\Models\Playlist;
use App\Services\XtreamService;
use App\Traits\ProviderRequestDelay;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessVodChannelsChunk implements ShouldQueue
{
    use ProviderRequestDelay;
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    // Giving a timeout of 10 minutes per chunk (100 channels)
    public $timeout = 60 * 10;

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

        // Get the channels for this chunk
        $channels = $playlist->channels()
            ->whereIn('id', $this->channelIds);

        $totalChannels = count($this->channelIds);

        foreach ($channels->cursor() as $index => $channel) {
            try {
                // Use provider throttling to limit concurrent requests and apply delay
                // skipTmdb=true: TMDB IDs are fetched in bulk from ProcessVodChannelsComplete
                $this->withProviderThrottling(fn () => $channel->fetchMetadata($xtream, skipTmdb: true));
            } catch (XtreamRateLimitedException $e) {
                // Account-wide cooldown: every remaining channel in this chunk
                // (and every later chunk in the Bus::chain) would fail the same
                // way. Rethrow so the chain's own ->catch() in ProcessVodChannels
                // stops the chain and marks the playlist Failed, rather than
                // silently rolling through the rest and reporting 100% complete.
                Log::warning('ProcessVodChannelsChunk: aborting chunk, Xtream account is rate limited', [
                    'playlist_id' => $playlist->id,
                    'chunk_index' => $this->chunkIndex,
                    'retry_at' => $e->retryAt->toIso8601String(),
                ]);

                throw $e;
            } catch (\Exception $e) {
                // Log the error and continue processing other channels
                Log::error('Failed to process VOD data for channel ID '.$channel->id.' in chunk '.$this->chunkIndex.': '.$e->getMessage());

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
                $overallProgress = min(99, $overallProgress); // Never exceed 99% until complete

                $playlist->update(['vod_progress' => $overallProgress]);
            }

            // Note: Provider throttling is now handled by withProviderThrottling() above
        }

        // Update progress after this chunk is complete
        $chunkCompleteProgress = (($this->chunkIndex + 1) / $this->totalChunks) * 100;
        $chunkCompleteProgress = min(99, $chunkCompleteProgress); // Never exceed 99% until ProcessVodChannelsComplete

        $playlist->update(['vod_progress' => $chunkCompleteProgress]);

        Log::info('Completed VOD chunk '.($this->chunkIndex + 1).' of '.$this->totalChunks.' for playlist ID '.$playlist->id);
    }
}
