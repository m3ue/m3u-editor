<?php

namespace App\Sync\Phases;

use App\Jobs\FireStreamFilesSyncedEvent;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\Contracts\ChainablePhase;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Chainable phase that fires the `vod_stream_files_synced` post-processes
 * after {@see StrmSyncPhase} completes within an orchestrated sync.
 *
 * For ad-hoc STRM dispatches (e.g. Filament "Sync STRM files" actions) the
 * post-processes still fire inline from {@see SyncVodStrmFiles}
 * because there is no SyncRun/orchestrator to route through; the
 * `suppressPostProcessEvents` flag passed by {@see StrmSyncPhase} is what
 * keeps the orchestrated path from double-firing.
 *
 * Skipped when the playlist has no enabled `vod_stream_files_synced`
 * post-processes — avoids contributing a no-op job to the chain.
 */
class StrmPostProcessPhase extends AbstractPhase implements ChainablePhase
{
    public const EVENT = 'vod_stream_files_synced';

    public static function slug(): string
    {
        return 'strm_post_process';
    }

    public function shouldRun(Playlist $playlist): bool
    {
        return $playlist->postProcesses()
            ->where('event', self::EVENT)
            ->where('enabled', true)
            ->exists();
    }

    protected function execute(SyncRun $run, Playlist $playlist, array $context): ?array
    {
        $jobs = $this->chainJobs($run, $playlist, $context);

        foreach ($jobs as $job) {
            dispatch($job);
        }

        return ['strm_post_process_dispatched' => count($jobs)];
    }

    /**
     * @return array<int, ShouldQueue>
     */
    public function chainJobs(SyncRun $run, Playlist $playlist, array $context = []): array
    {
        return [
            new FireStreamFilesSyncedEvent($playlist, self::EVENT),
        ];
    }
}
