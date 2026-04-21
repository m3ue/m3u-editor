<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Job;
use App\Models\Playlist;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessM3uImportSeriesComplete implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
        public string $batchNo,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(GeneralSettings $settings): void
    {
        // Update the playlist status to synced
        $this->playlist->refresh();
        $this->playlist->update([
            'processing' => [
                ...$this->playlist->processing ?? [],
                'series_processing' => false,
            ],
            'status' => Status::Completed,
            'errors' => null,
            'series_progress' => 100,
            'auto_retry_503_count' => 0,
            'auto_retry_503_last_at' => null,
        ]);
        $message = "Series sync completed successfully for playlist \"{$this->playlist->name}\".";
        Notification::make()
            ->success()
            ->title('Series Sync Completed')
            ->body($message)
            ->broadcast($this->playlist->user)
            ->sendToDatabase($this->playlist->user);

        // Fire the playlist synced event
        event(new SyncCompleted($this->playlist));

        // Mirror the VOD pipeline (ProcessVodChannelsComplete) by auto-fetching
        // TMDB IDs for newly imported series when the global setting is enabled.
        // Skip when the follow-up episode metadata sync will run afterwards —
        // CheckSeriesImportProgress dispatches its own FetchTmdbIds at the end
        // of that flow, and running both would duplicate work.
        if (! $settings->tmdb_auto_lookup_on_import) {
            return;
        }

        $willRunMetadataFetch = $this->playlist->auto_fetch_series_metadata
            && $this->playlist->series()->where('enabled', true)->exists();

        if ($willRunMetadataFetch) {
            return;
        }

        Log::info('Series Complete: Queuing bulk TMDB fetch for playlist ID '.$this->playlist->id);
        FetchTmdbIds::dispatch(
            seriesPlaylistId: $this->playlist->id,
            user: $this->playlist->user,
            sendCompletionNotification: false,
        );
    }
}
