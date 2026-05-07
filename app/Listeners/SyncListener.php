<?php

namespace App\Listeners;

use App\Enums\Status;
use App\Enums\SyncRunStatus;
use App\Events\SyncCompleted;
use App\Jobs\GenerateEpgCache;
use App\Jobs\RunPostProcess;
use App\Jobs\SyncPlexDvrJob;
use App\Models\Epg;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Plugins\PluginHookDispatcher;
use App\Sync\Plans\PlaylistPostSyncPlan;
use App\Sync\SyncOrchestrator;

class SyncListener
{
    /**
     * Handle the event.
     *
     * For playlist sync events all dispatch logic now lives in the
     * {@see SyncOrchestrator} + {@see PlaylistPostSyncPlan} pipeline. This
     * listener's only remaining responsibility for playlists is to:
     *   1. Close the open `sync`-kind {@see SyncRun} for this playlist (the run
     *      was left Running by {@see RecordsSyncPhaseCompletion} so it stays
     *      alive until the true end-of-sync signal arrives here).
     *   2. Open a `post_sync` {@see SyncRun} ledger row and pick the right plan
     *      based on outcome.
     *
     * The EPG branch still runs inline because EPG-side dispatches are out of
     * scope for the current refactor and will move to the orchestrator in a
     * later step.
     */
    public function handle(SyncCompleted $event): void
    {
        if ($event->model instanceof Playlist) {
            $this->handlePlaylist($event->model);

            return;
        }

        if ($event->model instanceof Epg) {
            $this->handleEpg($event->model);
        }
    }

    /**
     * Close any in-flight sync-kind run, open a post_sync run, select the
     * appropriate plan based on the playlist's outcome status, and hand off
     * to the orchestrator.
     */
    private function handlePlaylist(Playlist $playlist): void
    {
        // Close the sync-kind run that was opened by PlaylistSyncDispatcher
        // and left Running by RecordsSyncPhaseCompletion. SyncCompleted is the
        // true end-of-import signal — it fires only after all downstream chains
        // (VOD chunks, series metadata, etc.) have finished. The import
        // duration (playlist.sync_time) is stored here on the sync-kind run so
        // the caller trigger carries the timing context rather than post_sync.
        $this->closeSyncKindRun($playlist);

        $lastSync = $playlist->syncStatuses()->first();

        $plan = $playlist->status === Status::Completed
            ? PlaylistPostSyncPlan::build()
            : PlaylistPostSyncPlan::buildPostProcessOnly();

        $run = SyncRun::openFor(
            $playlist,
            kind: 'post_sync',
            trigger: 'sync_completed',
            meta: [
                'playlist_status' => $playlist->status?->value,
            ],
        );

        app(SyncOrchestrator::class)->execute($run, $plan, [
            'last_sync' => $lastSync,
        ]);
    }

    /**
     * Find the most recent active (`pending` or `running`) `sync`-kind run
     * for this playlist and close it based on the playlist's final status.
     *
     * The `import_duration_seconds` is stored on this run (sourced from
     * `playlist.sync_time`, which `ProcessM3uImportComplete` sets) so the
     * trigger that initiated the sync carries the timing information.
     */
    private function closeSyncKindRun(Playlist $playlist): void
    {
        $activeStatuses = array_map(fn ($s) => $s->value, SyncRunStatus::active());

        /** @var SyncRun|null $syncRun */
        $syncRun = $playlist->syncRuns()
            ->where('kind', 'sync')
            ->whereIn('status', $activeStatuses)
            ->latest('id')
            ->first();

        if ($syncRun === null) {
            return;
        }

        $meta = array_merge($syncRun->meta ?? [], [
            'import_duration_seconds' => $playlist->sync_time,
        ]);

        if ($playlist->status === Status::Completed) {
            $syncRun->forceFill([
                'status' => SyncRunStatus::Completed,
                'finished_at' => now(),
                'meta' => $meta,
            ])->save();
        } else {
            $syncRun->forceFill([
                'meta' => $meta,
            ])->save();

            $syncRun->markFailed("Playlist sync ended with status: {$playlist->status?->value}");
        }
    }

    /**
     * EPG post-sync handling — unchanged from the pre-orchestrator listener.
     */
    private function handleEpg(Epg $epg): void
    {
        $this->dispatchPostProcessJobs($epg);

        if ($epg->status !== Status::Completed) {
            return;
        }

        app(PluginHookDispatcher::class)->dispatch('epg.synced', [
            'epg_id' => $epg->id,
            'user_id' => $epg->user_id,
        ], [
            'user_id' => $epg->user_id,
        ]);

        $this->postProcessEpg($epg);

        // Sync Plex DVR (EPG data changed, guide needs refresh)
        dispatch(new SyncPlexDvrJob(trigger: 'epg_sync'));
    }

    /**
     * Dispatch post-processes for an EPG after sync.
     */
    private function dispatchPostProcessJobs(Epg $model): void
    {
        $model->postProcesses()
            ->where('event', 'synced')
            ->where('enabled', true)
            ->get()
            ->each(fn ($postProcess) => dispatch(new RunPostProcess($postProcess, $model)));
    }

    /**
     * Post-process an EPG after a successful sync.
     */
    private function postProcessEpg(Epg $epg): void
    {
        // Update status to Processing (so UI components will continue to refresh) and dispatch cache job
        // IMPORTANT: Set is_cached to false to prevent race condition where users
        // try to read the EPG cache (JSON files) while it's being regenerated
        // Note: Playlist EPG cache files (XML) are NOT cleared here - they remain available
        // for users until the new cache is generated, preventing fallback to slow XML reader
        // Note: processing_started_at and processing_phase will be set by GenerateEpgCache job
        $epg->update([
            'status' => Status::Processing,
            'is_cached' => false,
            'cache_meta' => null,
            'processing_started_at' => null,
            'processing_phase' => null,
        ]);

        // Dispatch cache generation job
        dispatch(new GenerateEpgCache($epg->uuid, notify: true));
    }
}
