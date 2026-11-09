<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Enums\SyncRunPhase;
use App\Models\Playlist;
use App\Services\SyncPipelineService;
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
     * @param  int  $metadataAttempted  Channels whose metadata fetch was actually attempted
     *                                  this run (0 when no channels needed processing).
     * @param  int  $metadataFailed  Of those attempted, how many returned a failure. A chunk
     *                               job never fails outright on a per-channel provider error
     *                               (see ProcessVodChannelsChunk), so this is the only signal
     *                               that distinguishes "every fetch failed" from real success.
     */
    public function __construct(
        public Playlist $playlist,
        public ?ShouldQueue $completionJob = null,
        public ?int $syncRunId = null,
        public int $metadataAttempted = 0,
        public int $metadataFailed = 0,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(GeneralSettings $settings): void
    {
        $allFailed = $this->metadataAttempted > 0 && $this->metadataFailed >= $this->metadataAttempted;
        $partiallyFailed = ! $allFailed && $this->metadataFailed > 0;

        // Update the playlist status to completed. Successfully processed channels
        // are always kept, so vod_progress still reaches 100, but a run where every
        // attempted fetch failed must not clear `errors` to null and claim a clean
        // success - that hides the failure from the playlist's own status display.
        $this->playlist->refresh();
        $this->playlist->update([
            'processing' => [
                ...$this->playlist->processing ?? [],
                'vod_processing' => false,
            ],
            'status' => Status::Completed,
            'errors' => $allFailed
                ? "VOD metadata sync completed, but all {$this->metadataAttempted} attempted channel(s) failed to fetch metadata from the provider."
                : null,
            'vod_progress' => 100,
        ]);

        Log::info('Completed processing VOD channels for playlist ID '.$this->playlist->id);

        if ($allFailed) {
            Notification::make()
                ->danger()
                ->title(__('VOD sync completed with errors on ":playlist"', ['playlist' => $this->playlist->name]))
                ->body(__('All :count attempted VOD metadata fetch(es) failed. Previously fetched metadata was kept; nothing new was retrieved.', ['count' => $this->metadataAttempted]))
                ->broadcast($this->playlist->user)
                ->sendToDatabase($this->playlist->user);
        } elseif ($partiallyFailed) {
            Notification::make()
                ->warning()
                ->title(__('VOD sync completed with errors on ":playlist"', ['playlist' => $this->playlist->name]))
                ->body(__(':failed of :attempted VOD metadata fetch(es) failed. Successful channels were kept; failed ones will be retried on the next sync.', ['failed' => $this->metadataFailed, 'attempted' => $this->metadataAttempted]))
                ->broadcast($this->playlist->user)
                ->sendToDatabase($this->playlist->user);
        } else {
            Notification::make()
                ->success()
                ->title(__('VOD Sync Completed'))
                ->body(__('VOD sync completed successfully for playlist ":playlist".', ['playlist' => $this->playlist->name]))
                ->broadcast($this->playlist->user)
                ->sendToDatabase($this->playlist->user);
        }

        // Pipeline path: delegate next-phase dispatch to SyncPipelineService.
        if ($this->syncRunId) {
            Log::info('VOD Complete: Handing off to SyncPipeline. run='.$this->syncRunId);
            app(SyncPipelineService::class)->completePhase($this->syncRunId, SyncRunPhase::VodMetadata);

            return;
        }

        // Legacy path — build and dispatch post-metadata chain manually.
        $postTmdbJobs = [];

        if ($this->playlist->auto_sync_vod_stream_files) {
            Log::info('VOD Complete: Queuing STRM sync for playlist ID '.$this->playlist->id);
            $hasFindReplaceRules = collect($this->playlist->find_replace_rules ?? [])
                ->contains(fn (array $rule): bool => $rule['enabled'] ?? false);
            if ($hasFindReplaceRules) {
                $postTmdbJobs[] = new RunPlaylistFindReplaceRules($this->playlist);
            }
            $postTmdbJobs[] = new SyncVodStrmFiles(playlist: $this->playlist);
        }

        if ($this->completionJob) {
            $postTmdbJobs[] = $this->completionJob;
        }

        if ($settings->tmdb_auto_lookup_on_import) {
            Log::info('VOD Complete: Queuing bulk TMDB fetch for playlist ID '.$this->playlist->id);
            FetchTmdbIds::dispatch(
                vodPlaylistId: $this->playlist->id,
                user: $this->playlist->user,
                sendCompletionNotification: false,
                postCompletionJobs: $postTmdbJobs,
            );
        } elseif (! empty($postTmdbJobs)) {
            Bus::chain($postTmdbJobs)->dispatch();
        }
    }
}
