<?php

namespace App\Sync\Plans;

use App\Jobs\ProcessM3uImport;
use App\Jobs\SyncMediaServer;
use App\Sync\Phases\PreSync\BackupPhase;
use App\Sync\Phases\PreSync\ConcurrencyGuardPhase;
use App\Sync\Phases\PreSync\InitializeSyncStatePhase;
use App\Sync\Phases\PreSync\MediaServerRedirectPhase;
use App\Sync\Phases\PreSync\NetworkPlaylistPhase;
use App\Sync\PlaylistSyncDispatcher;
use App\Sync\SyncPlan;

/**
 * Canonical pre-sync plan executed by {@see PlaylistSyncDispatcher}
 * before dispatching {@see ProcessM3uImport}.
 *
 * Phases run in declaration order. Each phase may set `halt` on the shared
 * context; the dispatcher inspects that flag after the plan finishes and
 * skips the import dispatch when set. Order matters:
 *
 *   1. **Network playlist guard** — virtual playlists with no upstream M3U
 *      have nothing to import. Marks the playlist Completed and halts.
 *   2. **Media server redirect** — Emby / Jellyfin playlists either redirect
 *      to {@see SyncMediaServer} or warn the user; either way the
 *      M3U import path is skipped. Run before the concurrency guard so that
 *      a media-server playlist mid-sync still gets routed correctly.
 *   3. **Concurrency / auto-sync guard** — short-circuits when the playlist
 *      is already processing or auto-sync is disabled. Bypassed when the
 *      dispatcher was called with `force: true`.
 *   4. **Backup** — dispatches a pre-sync DB backup when `backup_before_sync`
 *      is enabled and this is not the first sync. Skipped otherwise.
 *   5. **Initialize sync state** — only reached when no guard halted. Stamps
 *      the playlist into Processing with zeroed progress counters.
 */
final class PlaylistPreSyncPlan
{
    public static function build(): SyncPlan
    {
        return SyncPlan::make('playlist.pre_sync')
            ->phase(NetworkPlaylistPhase::class)
            ->phase(MediaServerRedirectPhase::class)
            ->phase(ConcurrencyGuardPhase::class)
            ->phase(BackupPhase::class)
            ->phase(InitializeSyncStatePhase::class);
    }
}
