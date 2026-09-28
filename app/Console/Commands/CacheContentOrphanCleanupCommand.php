<?php

namespace App\Console\Commands;

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use Illuminate\Console\Command;

/**
 * Deletes `CachedContentFile` rows where:
 *  - `file_path IS NULL` (the job never wrote bytes to disk),
 *  - status is NOT `Downloading` (an in-flight download must never be
 *    deleted out from under the worker), and
 *  - `updated_at` is older than --days.
 *
 * Age is judged by `updated_at` (not `created_at`) so a Failed row
 * that was retried, or a Pending row that was re-queued / had its
 * dispatch timestamp bumped, is not considered abandoned as long as it
 * moved within the window. This is the "abandoned download" case where
 * the worker never wrote a file (Pending/Failed that no dispatcher
 * will ever pick back up) - rows the retention service can't catch
 * because they have no `file_path` to anchor cleanup on.
 *
 * Scheduled daily at 03:30 (after the 03:00 retention pass) by
 * routes/console.php. Uses chunkById so a large cleanup doesn't lock
 * the table - matches the rest of the cleanup path's memory / lock
 * profile.
 */
class CacheContentOrphanCleanupCommand extends Command
{
    protected $signature = 'cache:cleanup-orphans
                                {--days=7 : Delete orphan rows whose updated_at is older than this many days}
                                {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Delete abandoned cached_content_files rows (no file on disk, not currently downloading, untouched for N days)';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $days = max(1, (int) $this->option('days'));

        $cutoff = now()->subDays($days);

        $query = CachedContentFile::query()
            ->whereNull('file_path')
            ->where('status', '!=', CachedContentFileStatus::Downloading->value)
            ->where('updated_at', '<', $cutoff);

        $count = $query->count();
        $this->line(sprintf(
            '%s %d orphan cached_content_files rows older than %d day(s).',
            $isDryRun ? '[DRY RUN] Identified' : 'Identified',
            $count,
            $days,
        ));

        if ($isDryRun || $count === 0) {
            return self::SUCCESS;
        }

        // chunkById - mutate-safe, bounded memory, no table lock.
        $deleted = 0;
        $query->chunkById(500, function ($rows) use (&$deleted): void {
            foreach ($rows as $row) {
                $row->delete();
                $deleted++;
            }
        });

        $this->info("Deleted {$deleted} orphan cached_content_files rows.");

        return self::SUCCESS;
    }
}
