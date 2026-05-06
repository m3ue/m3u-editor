<?php

namespace App\Sync\Plans;

use App\Listeners\SyncListener;
use App\Sync\Phases\AutoSyncToCustomPhase;
use App\Sync\Phases\ChannelScanPhase;
use App\Sync\Phases\FindReplaceAndSortAlphaPhase;
use App\Sync\Phases\PlexDvrSyncPhase;
use App\Sync\Phases\PluginDispatchPhase;
use App\Sync\Phases\PostProcessPhase;
use App\Sync\SyncPlan;

/**
 * Canonical post-sync plan for a Playlist. Mirrors the previous
 * {@see SyncListener} dispatch order:
 *
 *   1. Find/Replace + Sort Alpha (sequential pair, must run before merge so
 *      downstream operations see processed channel names).
 *   2. Channel Scan (merge -> scrubbers -> probe chain).
 *   3. Auto-sync to custom playlists.
 *   4. Plex DVR sync (lineup may have changed).
 *   5. Post-process jobs (user-defined).
 *   6. Plugin dispatch (`playlist.synced` hook).
 *
 * Phases 3-6 are independent of each other (no shared data flow), so they're
 * declared in a parallel group. The current orchestrator still runs them in
 * declaration order; the grouping is recorded for the future queue-batch
 * runner that will actually fan them out concurrently.
 *
 * All post-sync phases are marked `required: false` because a failure in
 * (e.g.) the Plex DVR sync should not prevent post-processes from running.
 * Phase-level failures still land on the SyncRun's error log and the
 * individual phase status is recorded as Failed.
 */
final class PlaylistPostSyncPlan
{
    public static function build(): SyncPlan
    {
        return SyncPlan::make('playlist.post_sync')
            ->phase(FindReplaceAndSortAlphaPhase::class, required: false)
            ->phase(ChannelScanPhase::class, required: false)
            ->parallel([
                AutoSyncToCustomPhase::class,
                PlexDvrSyncPhase::class,
                PostProcessPhase::class,
                PluginDispatchPhase::class,
            ]);
    }
}
