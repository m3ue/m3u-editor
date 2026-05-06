<?php

namespace App\Sync\Phases\PreSync;

use App\Enums\Status;
use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\Phases\AbstractPhase;
use App\Sync\PlaylistSyncDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Halt the sync pipeline for "network playlists" — virtual playlists whose
 * channels come from assigned upstream networks rather than an M3U / Xtream
 * source. They have nothing to import, so the sync is immediately marked
 * Completed on the playlist and the import job is skipped.
 *
 * Mirrors the in-job guard at the top of {@see ProcessM3uImport::handle()}.
 * That guard remains in place as defense-in-depth in case the job is ever
 * dispatched without going through {@see PlaylistSyncDispatcher}.
 */
class NetworkPlaylistPhase extends AbstractPhase
{
    public static function slug(): string
    {
        return 'network_guard';
    }

    protected function execute(SyncRun $run, Playlist $playlist, array $context): ?array
    {
        if (! $playlist->is_network_playlist) {
            return null;
        }

        Log::info('PreSync: network playlist, skipping M3U import', [
            'playlist_id' => $playlist->id,
            'name' => $playlist->name,
            'sync_run_id' => $run->id,
        ]);

        $playlist->update(['status' => Status::Completed]);

        return [
            'halt' => true,
            'halt_reason' => 'network_playlist',
        ];
    }
}
