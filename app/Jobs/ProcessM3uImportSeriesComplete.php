<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Enums\SyncRunPhase;
use App\Enums\SyncRunStatus;
use App\Events\SyncCompleted;
use App\Models\Playlist;
use App\Services\SyncPipelineService;
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

        // Build a standalone mini-pipeline for the post-series phases.
        // This avoids any SyncRun lookup and works safely even if two syncs somehow overlap,
        // because each call creates its own SyncRun with a fresh ID that flows through
        // ProcessM3uImportSeries → CheckSeriesImportProgress → completePhase().
        $this->dispatchSeriesPostProcessingPipeline($settings);
    }

    /**
     * Build a mini standalone pipeline for post-series-discovery phases and dispatch
     * episode metadata sync (if enabled) with the new run's ID baked in.
     *
     * By creating a fresh SyncRun here — rather than looking up the existing one — we
     * avoid any race condition when two syncs overlap, and the syncRunId flows naturally
     * through ProcessM3uImportSeries → CheckSeriesImportProgress → completePhase().
     */
    private function dispatchSeriesPostProcessingPipeline(GeneralSettings $settings): void
    {
        $playlist = $this->playlist;

        if (! $playlist->series()->where('enabled', true)->exists()) {
            return;
        }

        $pipeline = app(SyncPipelineService::class);
        $phases = $pipeline->resolveSeriesPhases($playlist, $settings);

        if (empty($phases)) {
            return;
        }

        $run = $pipeline->buildStandalonePipeline($playlist, $phases, 'series_discovery_complete');

        // When episode metadata sync is enabled, SeriesMetadata is the first phase.
        // Dispatch ProcessM3uImportSeries now with the run ID so CheckSeriesImportProgress
        // can hand off to the pipeline (via completePhase) when discovery finishes.
        // The pipeline will then dispatch the remaining phases (SeriesTmdb, SeriesStrm, etc.).
        if ($playlist->auto_fetch_series_metadata) {
            Log::info("Series Complete: Queuing episode metadata sync for playlist ID {$playlist->id}, syncRunId={$run->id}");
            dispatch(new ProcessM3uImportSeries(
                playlist: $playlist,
                force: true,
                isNew: false,
                batchNo: $this->batchNo,
                syncRunId: $run->id,
            ));

            $run->update([
                'status' => SyncRunStatus::Running->value,
                'current_phase' => SyncRunPhase::SeriesMetadata->value,
            ]);

            return;
        }

        // No metadata sync — let the pipeline dispatch from the first phase normally.
        $pipeline->startRun($run);
    }
}
