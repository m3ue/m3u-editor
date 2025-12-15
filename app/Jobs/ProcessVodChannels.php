<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\Channel;
use App\Models\Playlist;
use App\Services\XtreamService;
use App\Traits\ProviderRequestDelay;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessVodChannels implements ShouldQueue
{
    use Queueable;
    use ProviderRequestDelay;

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
        public ?bool $force = false,
        public ?bool $updateProgress = true
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
        if (!$xtream) {
            Log::error('Xtream service initialization failed for playlist ID ' . $playlist->id);
            return;
        }

        try {
            // Use provider throttling to limit concurrent requests and apply delay
            $this->withProviderThrottling(fn () => $this->channel->fetchMetadata($xtream));
            Log::info('Completed processing VOD data for channel ID ' . $this->channel->id);
            Notification::make()
                ->title('VOD Channel Processed')
                ->body('Successfully processed VOD data for channel: ' . $this->channel->name)
                ->success()
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
        } catch (\Exception $e) {
            Log::error('Failed to process VOD data for channel ID ' . $this->channel->id . ': ' . $e->getMessage());
            Notification::make()
                ->title('VOD Processing Error')
                ->body('Failed to process VOD data for channel: ' . $this->channel->name . '. Error: ' . $e->getMessage())
                ->danger()
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
        }
    }

    /**
     * Process VOD channels in chunks using a job chain.
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
            ->when(!$this->force, function ($query) {
                return $query->where(function ($query) {
                    $query->whereNull('info')
                        ->orWhereNull('movie_data');
                });
            });

        $total = $query->count();

        if ($total === 0) {
            Log::info('No VOD channels to process for playlist ID ' . $playlist->id);
            $playlist->update([
                'processing' => [
                    ...$playlist->processing ?? [],
                    'vod_processing' => false,
                ],
                'status' => Status::Completed,
                'vod_progress' => 100,
            ]);
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

        // Build the job chain using lazy collection to avoid memory issues
        // Use cursor/generator approach - only load CHUNK_SIZE IDs at a time
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

        // Add the completion job at the end
        $jobs[] = new ProcessVodChannelsComplete(
            playlist: $playlist,
        );

        // Dispatch the job chain
        Bus::chain($jobs)
            ->onConnection('redis')
            ->onQueue('import')
            ->catch(function (Throwable $e) use ($playlist) {
                $error = "Error processing VOD sync on \"{$playlist->name}\": {$e->getMessage()}";
                Log::error($error);
                Notification::make()
                    ->danger()
                    ->title("Error processing VOD sync on \"{$playlist->name}\"")
                    ->body('Please view your notifications for details.')
                    ->broadcast($playlist->user);
                Notification::make()
                    ->danger()
                    ->title("Error processing VOD sync on \"{$playlist->name}\"")
                    ->body($error)
                    ->sendToDatabase($playlist->user);
                $playlist->update([
                    'status' => Status::Failed,
                    'errors' => $error,
                    'vod_progress' => 100,
                    'processing' => [
                        ...$playlist->processing ?? [],
                        'vod_processing' => false,
                    ],
                ]);
            })->dispatch();
    }
}
