<?php

namespace App\Sync\Phases;

use App\Jobs\FireStreamFilesSyncedEvent;
use App\Jobs\SyncSeriesStrmFiles;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\Contracts\ChainablePhase;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Chainable phase that fires the `series_stream_files_synced` post-processes
 * after {@see SeriesStrmSyncPhase} completes within an orchestrated sync.
 *
 * For ad-hoc Series STRM dispatches (e.g. Filament "Sync STRM files" actions
 * on series, categories, or single episodes) the post-processes still fire
 * inline from {@see SyncSeriesStrmFiles} because there is no
 * SyncRun/orchestrator to route through; the `suppressPostProcessEvents`
 * flag passed by {@see SeriesStrmSyncPhase} is what keeps the orchestrated
 * path from double-firing.
 *
 * Skipped when the playlist has no enabled `series_stream_files_synced`
 * post-processes — avoids contributing a no-op job to the chain.
 */
class SeriesStrmPostProcessPhase extends AbstractPhase implements ChainablePhase
{
    public const EVENT = 'series_stream_files_synced';

    public static function slug(): string
    {
        return 'series_strm_post_process';
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

        return ['series_strm_post_process_dispatched' => count($jobs)];
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
