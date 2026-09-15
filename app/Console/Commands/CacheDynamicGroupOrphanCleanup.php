<?php

namespace App\Console\Commands;

use App\Services\DynamicGroupCacheRetentionService;
use Illuminate\Console\Command;

class CacheDynamicGroupOrphanCleanup extends Command
{
    protected $signature = 'app:cache-dynamic-group-content-cleanup-orphans
                            {--dry-run : List orphan paths without deleting anything}';

    protected $description = 'Delete cached files in Storage that are not referenced by any cached_content_files row (catches orphans from failed downloads, manual deletions, etc.)';

    public function handle(DynamicGroupCacheRetentionService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $orphans = $service->cleanupStorageOrphans(delete: ! $dryRun);

        if ($orphans === []) {
            $this->info($dryRun ? 'No orphan files found.' : 'No orphan files to delete.');

            return self::SUCCESS;
        }

        $this->line($dryRun ? 'Orphan files:' : 'Deleted orphan files:');
        foreach ($orphans as $path) {
            $this->line("  {$path}");
        }

        $count = count($orphans);
        $this->info(($dryRun ? "Found {$count}" : "Deleted {$count}").' orphan '.($count === 1 ? 'file' : 'files').'.');

        return self::SUCCESS;
    }
}
