<?php

namespace App\Sync\Phases;

use App\Jobs\CheckSeriesImportProgress;
use App\Jobs\SyncSeriesStrmFiles;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\Contracts\ChainablePhase;
use App\Sync\SyncPlan;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Chainable phase that dispatches Series STRM file sync for a playlist.
 *
 * Mirrors {@see StrmSyncPhase} for series. Must run AFTER
 * {@see FindReplaceAndSortAlphaPhase} so the `.strm` files are written using
 * processed channel/episode names.
 *
 * Previously this dispatch lived inside {@see CheckSeriesImportProgress},
 * inlined into the post-import chain. It now runs from the orchestrator's
 * chain block ({@see SyncPlan::chain()}), making the STRM ordering invariant
 * a property of the plan rather than buried in job-level Bus::chain calls.
 *
 * The dispatched {@see SyncSeriesStrmFiles} job is constructed with
 * `suppressPostProcessEvents: true`; the subsequent
 * {@see SeriesStrmPostProcessPhase} owns the `series_stream_files_synced`
 * post-process firing for an orchestrated sync. Standalone Series STRM
 * dispatches (Filament series/category actions, manual triggers) keep the
 * default behaviour and continue to fire post-processes inline.
 *
 * Skipped when:
 *   - `auto_sync_series_stream_files` is false on the playlist, OR
 *   - the playlist has no Series rows (avoids dispatching a no-op job).
 */
class SeriesStrmSyncPhase extends AbstractPhase implements ChainablePhase
{
    public static function slug(): string
    {
        return 'series_strm_sync';
    }

    public function shouldRun(Playlist $playlist): bool
    {
        if (! ($playlist->auto_sync_series_stream_files ?? false)) {
            return false;
        }

        // Cheap existence check — same relation SyncSeriesStrmFiles uses to
        // decide whether to do any work.
        return $playlist->series()->exists();
    }

    protected function execute(SyncRun $run, Playlist $playlist, array $context): ?array
    {
        $jobs = $this->chainJobs($run, $playlist, $context);

        foreach ($jobs as $job) {
            dispatch($job);
        }

        return ['series_strm_sync_dispatched' => count($jobs)];
    }

    /**
     * @return array<int, ShouldQueue>
     */
    public function chainJobs(SyncRun $run, Playlist $playlist, array $context = []): array
    {
        return [
            new SyncSeriesStrmFiles(
                series: null,
                notify: true,
                playlist_id: $playlist->getKey(),
                user_id: $playlist->user_id,
                suppressPostProcessEvents: true,
            ),
        ];
    }
}
