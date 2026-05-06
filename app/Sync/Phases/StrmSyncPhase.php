<?php

namespace App\Sync\Phases;

use App\Jobs\ProcessM3uImportVod;
use App\Jobs\ProcessVodChannelsComplete;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\Contracts\ChainablePhase;
use App\Sync\SyncPlan;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Chainable phase that dispatches VOD STRM file sync for a playlist.
 *
 * Must run AFTER {@see FindReplaceAndSortAlphaPhase} so the `.strm` files
 * are written using processed `title_custom` values, not stale ones. This is
 * the chain ordering invariant that previously required the F/R job to be
 * inlined in {@see ProcessVodChannelsComplete} and
 * {@see ProcessM3uImportVod}; now expressed declaratively via
 * {@see SyncPlan::chain()}.
 *
 * The dispatched {@see SyncVodStrmFiles} job is constructed with
 * `suppressPostProcessEvents: true` because the subsequent
 * {@see StrmPostProcessPhase} owns the post-process firing for an
 * orchestrated sync. Standalone STRM dispatches (Filament actions, manual
 * triggers) keep the default behaviour and continue to fire post-processes
 * inline.
 *
 * Skipped when:
 *   - `auto_sync_vod_stream_files` is false on the playlist, OR
 *   - the playlist has no VOD channels (avoids dispatching a no-op job).
 */
class StrmSyncPhase extends AbstractPhase implements ChainablePhase
{
    public static function slug(): string
    {
        return 'strm_sync';
    }

    public function shouldRun(Playlist $playlist): bool
    {
        if (! ($playlist->auto_sync_vod_stream_files ?? false)) {
            return false;
        }

        // Cheap existence check — mirror the relation used by
        // SyncVodStrmFiles to decide whether to do any work.
        return $playlist->channels()->where('is_vod', true)->exists();
    }

    protected function execute(SyncRun $run, Playlist $playlist, array $context): ?array
    {
        $jobs = $this->chainJobs($run, $playlist, $context);

        foreach ($jobs as $job) {
            dispatch($job);
        }

        return ['strm_sync_dispatched' => count($jobs)];
    }

    /**
     * @return array<int, ShouldQueue>
     */
    public function chainJobs(SyncRun $run, Playlist $playlist, array $context = []): array
    {
        return [
            new SyncVodStrmFiles(
                playlist: $playlist,
                suppressPostProcessEvents: true,
            ),
        ];
    }
}
