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
     */
    public function __construct(
        public Playlist $playlist,
        public ?ShouldQueue $completionJob = null,
        public ?int $syncRunId = null,
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
