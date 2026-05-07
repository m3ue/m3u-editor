<?php

namespace App\Sync\Phases\PreSync;

use App\Jobs\CreateBackup;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\Phases\AbstractPhase;

/**
 * Dispatch a pre-sync database backup before channel data is overwritten.
 *
 * Only runs when both conditions hold:
 *   - `backup_before_sync` is enabled on the playlist.
 *   - The playlist has been synced at least once (`synced` is non-null).
 *     First-time syncs have no existing data worth preserving.
 *
 * The backup job is dispatched asynchronously so it does not block the
 * remaining pre-sync phases. Because `CreateBackup` uses an InnoDB mysqldump
 * (MVCC snapshot), it captures a consistent state regardless of whether other
 * read-only activity is happening concurrently. Actual channel writes only
 * occur in chunk jobs dispatched after `ProcessM3uImport` builds its chain,
 * so the backup will complete before any data is mutated under normal
 * queue ordering.
 */
class BackupPhase extends AbstractPhase
{
    public static function slug(): string
    {
        return 'backup';
    }

    /**
     * Skip on first-time syncs — there is nothing to preserve yet.
     */
    public function shouldRun(Playlist $playlist): bool
    {
        return $playlist->backup_before_sync && $playlist->synced !== null;
    }

    protected function execute(SyncRun $run, Playlist $playlist, array $context): ?array
    {
        dispatch(new CreateBackup(includeFiles: false));

        return null;
    }
}
