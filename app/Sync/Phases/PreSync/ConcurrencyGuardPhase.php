<?php

namespace App\Sync\Phases\PreSync;

use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\Phases\AbstractPhase;
use App\Sync\PlaylistSyncDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Halt the sync pipeline when the playlist is already mid-sync, or when
 * auto-sync is disabled and the playlist has been synced at least once.
 *
 * Reads the `force` flag from `$context` (set by
 * {@see PlaylistSyncDispatcher} from the dispatch arguments). When
 * `force` is true the guard is bypassed entirely — matches the legacy in-job
 * behaviour where forced refreshes always proceed regardless of state.
 *
 * Mirrors the in-job guard at the top of {@see ProcessM3uImport::handle()};
 * that guard remains as defense-in-depth for any direct dispatch path that
 * bypasses the dispatcher.
 */
class ConcurrencyGuardPhase extends AbstractPhase
{
    public static function slug(): string
    {
        return 'concurrency_guard';
    }

    protected function execute(SyncRun $run, Playlist $playlist, array $context): ?array
    {
        $force = (bool) ($context['force'] ?? false);

        if ($force) {
            return null;
        }

        if ($playlist->isProcessing()) {
            Log::info('PreSync: playlist is currently processing, skipping refresh', [
                'playlist_id' => $playlist->id,
                'name' => $playlist->name,
                'sync_run_id' => $run->id,
            ]);

            return [
                'halt' => true,
                'halt_reason' => 'already_processing',
            ];
        }

        if (! $playlist->auto_sync && $playlist->synced) {
            return [
                'halt' => true,
                'halt_reason' => 'auto_sync_disabled',
            ];
        }

        return null;
    }
}
