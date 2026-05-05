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
        //
        // We always dispatch here regardless of auto_fetch_series_metadata. If episode
        // metadata sync is also enabled, CheckSeriesImportProgress will dispatch its own
        // FetchTmdbIds afterwards, but with overwriteExisting=false that second run is a
        // near no-op for series that already received IDs. Always dispatching here ensures
        // first-time imports are covered: on the very first sync, ProcessM3uImportComplete
        // runs before the series chunks populate the DB, so it skips dispatching
        // ProcessM3uImportSeries — meaning CheckSeriesImportProgress never fires and TMDB
        // IDs would never be assigned without this dispatch.
        if (! $settings->tmdb_auto_lookup_on_import) {
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
