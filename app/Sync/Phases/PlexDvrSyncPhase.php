<?php

namespace App\Sync\Phases;

use App\Jobs\SyncPlexDvrJob;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\Contracts\BatchablePhase;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Re-sync the Plex DVR channel lineup since the playlist's channel list may
 * have changed during sync. The job is dispatched globally (not per-playlist)
 * because Plex DVR maps span all configured playlists.
 *
 * Implements {@see BatchablePhase} so the orchestrator can collect the job
 * across sibling parallel-group phases and dispatch them as a single
 * `Bus::batch([...])`. The standalone `execute()` path (used by direct
 * `$phase->run()` callers and tests) still dispatches the job inline.
 */
class PlexDvrSyncPhase extends AbstractPhase implements BatchablePhase
{
    public static function slug(): string
    {
        return 'plex_dvr_sync';
    }

    /**
     * Always run on a completed playlist sync — the job itself is a no-op when
     * Plex DVR isn't configured, so there's no value in checking config here.
     */
    public function shouldRun(Playlist $playlist): bool
    {
        return true;
    }

    protected function execute(SyncRun $run, Playlist $playlist, array $context): ?array
    {
        foreach ($this->batchJobs($run, $playlist, $context) as $job) {
            dispatch($job);
        }

        return null;
    }

    /**
     * @return array<int, ShouldQueue>
     */
    public function batchJobs(SyncRun $run, Playlist $playlist, array $context = []): array
    {
        return [
            new SyncPlexDvrJob(trigger: 'playlist_sync'),
        ];
    }
}
