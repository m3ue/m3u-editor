<?php

namespace App\Sync\Phases;

use App\Jobs\SyncPlexDvrJob;
use App\Models\Playlist;
use App\Models\SyncRun;

/**
 * Re-sync the Plex DVR channel lineup since the playlist's channel list may
 * have changed during sync. The job is dispatched globally (not per-playlist)
 * because Plex DVR maps span all configured playlists.
 */
class PlexDvrSyncPhase extends AbstractPhase
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
        dispatch(new SyncPlexDvrJob(trigger: 'playlist_sync'));

        return null;
    }
}
