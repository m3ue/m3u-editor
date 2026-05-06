<?php

namespace App\Sync\Plans;

use App\Jobs\ProcessM3uImportVod;
use App\Jobs\ProcessVodChannelsComplete;
use App\Listeners\SyncListener;
use App\Sync\Phases\AutoSyncToCustomPhase;
use App\Sync\Phases\ChannelScanPhase;
use App\Sync\Phases\FindReplaceAndSortAlphaPhase;
use App\Sync\Phases\PlexDvrSyncPhase;
use App\Sync\Phases\PluginDispatchPhase;
use App\Sync\Phases\PostProcessPhase;
use App\Sync\Phases\StrmPostProcessPhase;
use App\Sync\Phases\StrmSyncPhase;
use App\Sync\SyncPlan;

/**
 * Canonical post-sync plan for a Playlist. Mirrors the previous
 * {@see SyncListener} dispatch order:
 *
 *   1. **Chain block** (strict ordering across queue workers):
 *      a. Find/Replace + Sort Alpha — must run before any work that depends
 *         on processed channel names.
 *      b. STRM sync — writes `.strm` files using the processed
 *         `title_custom` values from F/R.
 *      c. STRM post-process — fires `vod_stream_files_synced` post-processes
 *         only after STRM finishes.
 *   2. Channel Scan (merge -> scrubbers -> probe chain).
 *   3. Auto-sync to custom playlists.
 *   4. Plex DVR sync (lineup may have changed).
 *   5. Post-process jobs (user-defined `synced` event).
 *   6. Plugin dispatch (`playlist.synced` hook).
 *
 * The chain block replaces the previous eager F/R + STRM dispatches inside
 * {@see ProcessVodChannelsComplete} and
 * {@see ProcessM3uImportVod}: ordering is now a property of the
 * plan, not buried in job-level `Bus::chain` calls.
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
            ->chain([
                FindReplaceAndSortAlphaPhase::class,
                StrmSyncPhase::class,
                StrmPostProcessPhase::class,
            ])
            ->phase(ChannelScanPhase::class, required: false)
            ->parallel([
                AutoSyncToCustomPhase::class,
                PlexDvrSyncPhase::class,
                PostProcessPhase::class,
                PluginDispatchPhase::class,
            ]);
    }

    /**
     * Reduced plan used when a playlist sync did NOT complete successfully.
     *
     * Mirrors the legacy {@see SyncListener} behaviour where post-process jobs
     * (e.g. user-defined webhooks) fired regardless of sync outcome, but all
     * other phases — which mutate the channel list or downstream systems —
     * were guarded behind a Status::Completed check.
     */
    public static function buildPostProcessOnly(): SyncPlan
    {
        return SyncPlan::make('playlist.post_sync.post_process_only')
            ->phase(PostProcessPhase::class, required: false);
    }
}
