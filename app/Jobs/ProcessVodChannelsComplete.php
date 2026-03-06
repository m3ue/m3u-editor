<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\Playlist;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
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
    public function handle(GeneralSettings $settings): void
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

        Log::info('Completed processing VOD channels for playlist ID '.$this->playlist->id);

        Notification::make()
            ->success()
            ->title('VOD Sync Completed')
            ->body("VOD sync completed successfully for playlist \"{$this->playlist->name}\".")
            ->broadcast($this->playlist->user)
            ->sendToDatabase($this->playlist->user);

        // Now that all metadata chunks are done, dispatch TMDB fetch and/or STRM sync.
        // This avoids the race condition where SyncVodStrmFiles fired before chunks completed.
        $postJobs = [];

        if ($settings->tmdb_auto_lookup_on_import) {
            Log::info('VOD Complete: Queuing bulk TMDB fetch for playlist ID '.$this->playlist->id);
            $postJobs[] = new FetchTmdbIds(
                vodPlaylistId: $this->playlist->id,
                sendCompletionNotification: false,
            );
        }

        if ($this->playlist->auto_sync_vod_stream_files) {
            Log::info('VOD Complete: Queuing STRM sync for playlist ID '.$this->playlist->id);
            $postJobs[] = new SyncVodStrmFiles(
                playlist: $this->playlist,
            );
        }

        if (! empty($postJobs)) {
            Bus::chain($postJobs)->dispatch();
        }
    }
}
