<?php

namespace App\Sync\Phases\PreSync;

use App\Enums\Status;
use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\Phases\AbstractPhase;
use App\Sync\PlaylistSyncDispatcher;

/**
 * Final pre-sync step: clear the per-run dedup guard and stamp the playlist
 * into the Processing state with zeroed progress counters and reset
 * processing flags.
 *
 * Runs unconditionally (after the guard phases have all passed). Mirrors the
 * inline state mutation that previously lived in
 * {@see ProcessM3uImport::handle()}; the in-job version remains as
 * defense-in-depth so any direct dispatch path that bypasses
 * {@see PlaylistSyncDispatcher} still leaves the playlist in a
 * consistent Processing state before the importer starts.
 */
class InitializeSyncStatePhase extends AbstractPhase
{
    public static function slug(): string
    {
        return 'initialize_sync_state';
    }

    protected function execute(SyncRun $run, Playlist $playlist, array $context): ?array
    {
        // New sync window — clear the SyncCompleted dedup guard so the next
        // dispatch this run is allowed to fire (subsequent ones become no-ops).
        $playlist->resetSyncCompletedGuard();

        $playlist->update([
            'status' => Status::Processing,
            'synced' => now(),
            'errors' => null,
            'progress' => 0,
            'vod_progress' => 0,
            'series_progress' => 0,
            'processing' => [
                ...$playlist->processing ?? [],
                'live_processing' => false,
                'vod_processing' => false,
                'series_processing' => false,
            ],
        ]);

        return null;
    }
}
