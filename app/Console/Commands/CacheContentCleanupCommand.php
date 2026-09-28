<?php

namespace App\Console\Commands;

use App\Services\CachedContentRetentionService;
use Illuminate\Console\Command;

/**
 * Runs `CachedContentRetentionService::evaluate()` and deletes the
 * returned cached files. Scheduled daily at 03:00 by routes/console.php.
 *
 * `evaluate()` returns the list of IDs in scope without touching the DB
 * for the deletion itself, so the two halves (identify / delete) are
 * independently testable. This command glues them together.
 *
 * `--dry-run` reports the count of rows that WOULD be deleted without
 * actually touching the DB or storage - useful for sanity-checking
 * retention policy after a playlist change.
 */
class CacheContentCleanupCommand extends Command
{
    protected $signature = 'cache:cleanup
                                {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Delete cached files whose channel or episode is no longer in its playlist';

    public function handle(CachedContentRetentionService $service): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $ids = $service->evaluate();

        $this->line(sprintf(
            '%s %d cached files for cleanup.',
            $isDryRun ? '[DRY RUN] Identified' : 'Identified',
            $ids->count(),
        ));

        if ($isDryRun || $ids->isEmpty()) {
            return self::SUCCESS;
        }

        $deleted = $service->deleteIds($ids);

        $this->info("Deleted {$deleted} cached files (storage + row).");

        return self::SUCCESS;
    }
}
