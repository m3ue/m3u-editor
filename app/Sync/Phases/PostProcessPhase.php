<?php

namespace App\Sync\Phases;

use App\Jobs\RunPostProcess;
use App\Models\Playlist;
use App\Models\SyncRun;

/**
 * Dispatch user-defined post-process jobs that are wired to the playlist
 * 'synced' event (e.g. webhook calls, file exports). Each enabled
 * post-process produces its own queued {@see RunPostProcess} dispatch.
 *
 * Unlike most other phases this one runs on _both_ successful and failed
 * syncs (the original SyncListener calls dispatchPostProcessJobs outside the
 * Status::Completed guard). The orchestrator should pass the most recent
 * `PlaylistSyncStatus` row through `$context['last_sync']` if available.
 */
class PostProcessPhase extends AbstractPhase
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
        $lastSync = $context['last_sync'] ?? null;

        $count = 0;
        $playlist->postProcesses()
            ->where('event', 'synced')
            ->where('enabled', true)
            ->get()
            ->each(function ($postProcess) use ($playlist, $lastSync, &$count): void {
                dispatch(new RunPostProcess($postProcess, $playlist, $lastSync));
                $count++;
            });

        return ['post_processes_dispatched' => $count];
    }
}
