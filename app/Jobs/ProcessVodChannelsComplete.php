<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\Playlist;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessVodChannelsComplete implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    /**
     * Create a new job instance.
     *
     * @param  ShouldQueue|null  $completionJob  Job to dispatch at the end of the full VOD
     *                                           pipeline (after STRM sync). Null for UI-triggered
     *                                           refreshes that don't need a downstream event.
     */
    public function __construct(
        public Playlist $playlist,
        public ?ShouldQueue $completionJob = null,
    ) {}

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
            if (Cache::add("playlist:{$this->playlist->id}:tmdb_fetch_vod", 1, 3600)) {
                Log::info('VOD Complete: Queuing bulk TMDB fetch for playlist ID '.$this->playlist->id);
                $postJobs[] = new FetchTmdbIds(
                    vodPlaylistId: $this->playlist->id,
                    user: $this->playlist->user,
                    sendCompletionNotification: false,
                );
            } else {
                Log::info('VOD Complete: Skipping bulk TMDB fetch (already dispatched in this sync window)', [
                    'playlist_id' => $this->playlist->id,
                ]);
            }
        }

        if ($this->playlist->auto_sync_vod_stream_files) {
            // STRM sync is now orchestrated post-sync via StrmSyncPhase, which
            // chains it after FindReplaceAndSortAlphaPhase so `.strm` files
            // observe processed `title_custom` values. Nothing to dispatch here.
            Log::info('VOD Complete: STRM sync deferred to orchestrator (StrmSyncPhase)', [
                'playlist_id' => $this->playlist->id,
            ]);
        }

        // Append the completion job (FireSyncCompletedEvent or TriggerSeriesImport)
        // as the final chain step so SyncListener / TriggerSeriesImport only run
        // after the TMDB fetch (if any) finishes. STRM sync no longer participates
        // in this chain — it is dispatched by StrmSyncPhase after SyncListener
        // hands off to the orchestrator post-completion.
        if (! empty($postJobs)) {
            if ($this->completionJob) {
                $postJobs[] = $this->completionJob;
            }
            Bus::chain($postJobs)->dispatch();
        } elseif ($this->completionJob) {
            dispatch($this->completionJob);
        }
    }
}
