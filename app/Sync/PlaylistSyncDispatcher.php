<?php

namespace App\Sync;

use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use App\Models\SyncRun;

/**
 * Single entry point for dispatching playlist sync work.
 *
 * Opens a `SyncRun` ledger row (kind: `sync`) so every sync attempt is
 * traceable to the trigger that initiated it (UI action, API call, scheduled
 * command, model lifecycle event, ...) before handing off to
 * `ProcessM3uImport`.
 *
 * The post-sync flow that runs after `SyncCompleted` fires opens a *separate*
 * `SyncRun` (kind: `post_sync`) inside `SyncListener`. Wiring the sync-side
 * run into `ProcessM3uImport` itself (so it can be marked Running / Completed
 * / Failed) is intentionally deferred until Step 7 when the import job is
 * split into smaller pieces.
 */
class PlaylistSyncDispatcher
{
    // -------------------------------------------------------------------------
    // Trigger identifiers — centralised for grep-ability and consistency.
    // -------------------------------------------------------------------------

    public const TRIGGER_PLAYLIST_CREATED = 'playlist.created';

    public const TRIGGER_API_REFRESH = 'api.refresh';

    public const TRIGGER_CONSOLE_REFRESH = 'console.refresh';

    public const TRIGGER_CONSOLE_REFRESH_SCHEDULED = 'console.refresh.scheduled';

    public const TRIGGER_CONSOLE_RESET_STUCK = 'console.reset_stuck';

    public const TRIGGER_FILAMENT_PROCESS = 'filament.process';

    public const TRIGGER_FILAMENT_BULK_PROCESS = 'filament.bulk_process';

    /**
     * Open a SyncRun ledger row and dispatch the M3U import job.
     *
     * @param  array<string, mixed>  $meta  Additional metadata to merge into the run's `meta` column.
     */
    public function dispatch(
        Playlist $playlist,
        string $trigger,
        bool $force = false,
        bool $isNew = false,
        array $meta = [],
    ): SyncRun {
        $run = SyncRun::openFor(
            playlist: $playlist,
            kind: 'sync',
            trigger: $trigger,
            meta: array_merge([
                'force' => $force,
                'is_new' => $isNew,
            ], $meta),
        );

        dispatch(new ProcessM3uImport($playlist, $force, $isNew));

        return $run;
    }
}
