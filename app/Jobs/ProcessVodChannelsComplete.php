<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessVodChannelsComplete implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Update the playlist status to completed
        $this->playlist->refresh();
        $this->playlist->update([
            'processing' => [
                ...$this->playlist->processing ?? [],
                'vod_processing' => false,
            ],
            'status' => Status::Completed,
            'errors' => null,
            'vod_progress' => 100,
        ]);

        $message = "VOD sync completed successfully for playlist \"{$this->playlist->name}\".";

        Log::info('Completed processing VOD channels for playlist ID '.$this->playlist->id);

        Notification::make()
            ->success()
            ->title('VOD Sync Completed')
            ->body($message)
            ->broadcast($this->playlist->user)
            ->sendToDatabase($this->playlist->user);
    }
}
