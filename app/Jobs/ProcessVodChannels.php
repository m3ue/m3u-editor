<?php

namespace App\Jobs;

use Exception;
use App\Models\Channel;
use App\Models\Playlist;
use App\Services\XtreamService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessVodChannels implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    // Giving a timeout of 15 minutes to the Job to process the file
    public $timeout = 60 * 15;

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

        $xtream = $xtream->init(
            playlist: $playlist,
            retryLimit: 5
        );
        if (!$xtream) {
            Log::error('Xtream service initialization failed for playlist ID ' . $playlist->id);
            return;
        }

        // Update the playlist status to processing
        if (!$this->channel) {
            $playlist->update([
                'processing' => true,
                'status' => 'processing',
                'errors' => null,
            ]);
        }

        if ($this->channel) {
            $total = 1; // Only one channel to process
            $channels = collect([$this->channel]);
        } else {
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
            $channels = $query->get([
                'id',
                'name',
                'source_id',
            ]);
        }
        foreach ($channels as $index => $channel) {
            try {
                $channel->fetchMetadata($xtream);
            } catch (\Exception $e) {
                // Log the error and continue processing other channels
                Log::error('Failed to process VOD data for channel ID ' . $channel->id . ': ' . $e->getMessage());
                Notification::make()
                    ->title('VOD Processing Error')
                    ->body('Failed to process VOD data for channel: ' . $channel->name . '. Error: ' . $e->getMessage())
                    ->danger()
                    ->broadcast($playlist->user)
                    ->sendToDatabase($playlist->user);

                // Update the playlist with the error
                if ($this->updateProgress) {
                    $playlist->update([
                        'processing' => false,
                        'status' => 'failed',
                        'errors' => 'Failed to process VOD data for channel ID ' . $channel->id . ': ' . $e->getMessage(),
                    ]);
                }
                return; // Exit the job if an error occurs
            }
            if ($index % 10 === 0) {
                if (!$this->channel) {
                    // Update progress every 10 channels processed
                    $progress = min(99, ($index / $total) * 100);
                    $playlist->update(['progress' => $progress]);
                }
            }
            usleep(100000); // Throttle processing to avoid overwhelming the Xtream API
        }

        // Update the playlist status after processing
        if (!$this->channel) {
            if ($this->updateProgress) {
                $playlist->update([
                    'processing' => false,
                    'progress' => 100,
                    'status' => 'completed',
                    'errors' => null,
                ]);
            }
            Log::info('Completed processing VOD channels for playlist ID ' . $playlist->id);
            Notification::make()
                ->title('VOD Channels Processed')
                ->body('Successfully processed VOD channels for playlist: ' . $playlist->name)
                ->success()
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
        } else {
            Log::info('Completed processing VOD data for channel ID ' . $this->channel->id);
            Notification::make()
                ->title('VOD Channel Processed')
                ->body('Successfully processed VOD data for channel: ' . $this->channel->name)
                ->success()
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
        }
    }
}
