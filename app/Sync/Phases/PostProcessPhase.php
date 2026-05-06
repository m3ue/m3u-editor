<?php

namespace App\Sync\Phases;

use App\Jobs\RunPostProcess;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\Contracts\BatchablePhase;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Dispatch user-defined post-process jobs that are wired to the playlist
 * 'synced' event (e.g. webhook calls, file exports). Each enabled
 * post-process produces its own queued {@see RunPostProcess} dispatch.
 *
 * Unlike most other phases this one runs on _both_ successful and failed
 * syncs (the original SyncListener calls dispatchPostProcessJobs outside the
 * Status::Completed guard). The orchestrator should pass the most recent
 * `PlaylistSyncStatus` row through `$context['last_sync']` if available.
 *
 * Implements {@see BatchablePhase} so the orchestrator can collect the jobs
 * across sibling parallel-group phases and dispatch them as a single
 * `Bus::batch([...])`. The standalone `execute()` path (used by direct
 * `$phase->run()` callers and tests) still dispatches each job inline.
 */
class PostProcessPhase extends AbstractPhase implements BatchablePhase
{
    public static function slug(): string
    {
        return 'post_process';
    }

    public function shouldRun(Playlist $playlist): bool
    {
        return $playlist->postProcesses()
            ->where('event', 'synced')
            ->where('enabled', true)
            ->exists();
    }

    protected function execute(SyncRun $run, Playlist $playlist, array $context): ?array
    {
        $jobs = $this->batchJobs($run, $playlist, $context);

        foreach ($jobs as $job) {
            dispatch($job);
        }

        return ['post_processes_dispatched' => count($jobs)];
    }

    /**
     * @return array<int, ShouldQueue>
     */
    public function batchJobs(SyncRun $run, Playlist $playlist, array $context = []): array
    {
        $lastSync = $context['last_sync'] ?? null;

        return $playlist->postProcesses()
            ->where('event', 'synced')
            ->where('enabled', true)
            ->get()
            ->map(fn ($postProcess) => new RunPostProcess($postProcess, $playlist, $lastSync))
            ->all();
    }
}
