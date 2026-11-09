<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\Channel;
use App\Models\Playlist;
use App\Services\XtreamService;
use App\Traits\ProviderRequestDelay;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessVodChannels implements ShouldQueue
{
    use ProviderRequestDelay;
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    // Timeout for initial setup (not for processing all channels)
    public $timeout = 60 * 5;

    // Number of channels to process per chunk
    public const CHUNK_SIZE = 100;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ?Playlist $playlist = null,
        public ?Channel $channel = null,
        public bool $force = false,
        public bool $updateProgress = true,
        public ?ShouldQueue $completionJob = null,
        public ?int $syncRunId = null,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(XtreamService $xtream): void
    {
        $playlist = $this->playlist;
        if ($playlist === null) {
            $playlist = $this->channel?->playlist;
        }
        if ($playlist === null) {
            Log::error('Unable to process VOD channels: Playlist is null');

            return;
        }

        // If processing a single channel, use direct processing
        if ($this->channel) {
            $this->processSingleChannel($xtream, $playlist);

            return;
        }

        // For bulk processing, use chunked approach
        $this->processVodChannelsInChunks($playlist);
    }

    /**
     * Process a single VOD channel directly.
     */
    protected function processSingleChannel(XtreamService $xtream, Playlist $playlist): void
    {
        $xtream = $xtream->init(
            playlist: $playlist,
            retryLimit: 5
        );
        if (! $xtream) {
            Log::error('Xtream service initialization failed for playlist ID '.$playlist->id);

            return;
        }

        try {
            // Use provider throttling to limit concurrent requests and apply delay
            $this->withProviderThrottling(fn () => $this->channel->fetchMetadata($xtream));
            Log::info('Completed processing VOD data for channel ID '.$this->channel->id);
            Notification::make()
                ->title('VOD Channel Processed')
                ->body('Successfully processed VOD data for channel: '.$this->channel->name)
                ->success()
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
        } catch (\Exception $e) {
            Log::error('Failed to process VOD data for channel ID '.$this->channel->id.': '.$e->getMessage());
            Notification::make()
                ->title('VOD Processing Error')
                ->body('Failed to process VOD data for channel: '.$this->channel->name.'. Error: '.$e->getMessage())
                ->danger()
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
        }
    }

    /**
     * Process VOD channels in chunks using a job batch.
     */
    protected function processVodChannelsInChunks(Playlist $playlist): void
    {
        // Get all VOD channel IDs that need processing
        $query = $playlist->channels()
            ->where([
                ['is_vod', true],
                ['enabled', true],
                ['source_id', '!=', null],
            ])
            ->when(! $this->force, function ($query) {
                return $query->whereNull('last_metadata_fetch');
            });

        $total = $query->count();

        if ($total === 0) {
            Log::info('No VOD channels to process for playlist ID '.$playlist->id);
            $playlist->update([
                'processing' => [
                    ...$playlist->processing ?? [],
                    'vod_processing' => false,
                ],
                'status' => Status::Completed,
                'vod_progress' => 100,
            ]);

            // Still dispatch the completion job so TMDB fetch and stream file sync run
            // even when there are no new channels to fetch metadata for.
            dispatch(new ProcessVodChannelsComplete(playlist: $playlist, completionJob: $this->completionJob, syncRunId: $this->syncRunId));

            return;
        }

        // Update the playlist status to processing
        $playlist->update([
            'processing' => [
                ...$playlist->processing ?? [],
                'vod_processing' => true,
            ],
            'status' => Status::Processing,
            'errors' => null,
            'vod_progress' => 0,
        ]);

        // Notify user that VOD processing is starting
        Notification::make()
            ->info()
            ->title('VOD Sync Started')
            ->body("Processing {$total} VOD channels for playlist: {$playlist->name}. This may take a while.")
            ->broadcast($playlist->user)
            ->sendToDatabase($playlist->user);

        // Calculate total chunks without loading all IDs into memory
        $totalChunks = (int) ceil($total / self::CHUNK_SIZE);

        Log::info("Starting chunked VOD processing for playlist ID {$playlist->id}: {$total} channels in {$totalChunks} chunks");

        // Build the chunk jobs to batch. Use chunk() on the query builder - only
        // CHUNK_SIZE IDs are ever loaded into memory at a time.
        $jobs = [];
        $chunkIndex = 0;

        // Use chunk() on the query builder which processes in batches without loading all into memory
        $query->select('id')->orderBy('id')->chunk(self::CHUNK_SIZE, function ($channels) use (&$jobs, &$chunkIndex, $playlist, $totalChunks) {
            $chunkIds = $channels->pluck('id')->toArray();
            $jobs[] = new ProcessVodChannelsChunk(
                playlist: $playlist,
                channelIds: $chunkIds,
                chunkIndex: $chunkIndex,
                totalChunks: $totalChunks,
                force: $this->force,
            );
            $chunkIndex++;
        });

        $completionJob = $this->completionJob;
        $syncRunId = $this->syncRunId;

        // Reset this run's per-channel outcome counters. Channel::fetchMetadata()
        // swallows provider failures and returns false rather than throwing, so
        // Bus::batch()'s own failedJobs count never reflects per-channel failures -
        // these counters are how the finally callback below tells them apart from
        // a fully successful run.
        Cache::forever(ProcessVodChannelsChunk::attemptedCacheKey($playlist->id), 0);
        Cache::forever(ProcessVodChannelsChunk::failedCacheKey($playlist->id), 0);

        // Dispatch the chunks as a batch rather than a chain: chunks are independent
        // and idempotent (whereNull('last_metadata_fetch')), so one chunk failing
        // (e.g. a transient SQLite lock) must not discard every other chunk still
        // in flight. allowFailures() lets the rest keep running; ->finally() always
        // dispatches the completion job once every chunk has settled, regardless of
        // how many failed - matching the pattern used by FetchTmdbIds' own batches.
        Bus::batch($jobs)
            ->name("VOD Metadata: {$playlist->name}")
            ->onConnection('redis')
            ->onQueue('import')
            ->allowFailures()
            ->finally(function (Batch $batch) use ($playlist, $completionJob, $syncRunId): void {
                if ($batch->failedJobs > 0) {
                    $error = __(':failed of :total VOD chunk(s) failed while processing ":playlist". Successfully processed channels were kept; failed ones will be retried on the next sync.', ['failed' => $batch->failedJobs, 'total' => $batch->totalJobs, 'playlist' => $playlist->name]);
                    Log::warning($error);
                    Notification::make()
                        ->warning()
                        ->title(__('VOD sync completed with errors on ":playlist"', ['playlist' => $playlist->name]))
                        ->body($error)
                        ->broadcast($playlist->user)
                        ->sendToDatabase($playlist->user);
                }

                $metadataAttempted = (int) Cache::get(ProcessVodChannelsChunk::attemptedCacheKey($playlist->id), 0);
                $metadataFailed = (int) Cache::get(ProcessVodChannelsChunk::failedCacheKey($playlist->id), 0);
                Cache::forget(ProcessVodChannelsChunk::attemptedCacheKey($playlist->id));
                Cache::forget(ProcessVodChannelsChunk::failedCacheKey($playlist->id));

                dispatch(new ProcessVodChannelsComplete(
                    playlist: $playlist,
                    completionJob: $completionJob,
                    syncRunId: $syncRunId,
                    metadataAttempted: $metadataAttempted,
                    metadataFailed: $metadataFailed,
                ));
            })
            ->dispatch();
    }
}
