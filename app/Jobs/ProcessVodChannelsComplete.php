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
            Log::info('VOD Complete: Queuing bulk TMDB fetch for playlist ID '.$this->playlist->id);
            $postJobs[] = new FetchTmdbIds(
                vodPlaylistId: $this->playlist->id,
                user: $this->playlist->user,
                sendCompletionNotification: false,
            );
        }

        if ($this->playlist->auto_sync_vod_stream_files) {
            Log::info('VOD Complete: Queuing STRM sync for playlist ID '.$this->playlist->id);
            $hasFindReplaceRules = collect($this->playlist->find_replace_rules ?? [])
                ->contains(fn (array $rule): bool => $rule['enabled'] ?? false);
            if ($hasFindReplaceRules) {
                // Find & Replace runs concurrently with VOD metadata fetch (dispatched by
                // SyncListener). Chain it here too so STRM sync is guaranteed to use
                // the processed title_custom values, not stale ones.
                $postJobs[] = new RunPlaylistFindReplaceRules($this->playlist);
            }
            $postJobs[] = new SyncVodStrmFiles(
                playlist: $this->playlist,
            );
        }

        // Append the completion job (FireSyncCompletedEvent or TriggerSeriesImport) as the
        // very last step so post-processing and series import never start before STRM sync finishes.
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
