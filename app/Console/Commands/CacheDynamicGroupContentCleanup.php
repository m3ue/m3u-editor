<?php

namespace App\Console\Commands;

use App\Jobs\DynamicGroupCacheRetentionCleanup;
use Illuminate\Console\Command;

class CacheDynamicGroupContentCleanup extends Command
{
    protected $signature = 'app:cache-dynamic-group-content-cleanup';

    protected $description = 'Run retention cleanup for Dynamic Group cached content (detaches expired pivot rows, hard-deletes unreferenced files)';

    public function handle(): int
    {
        DynamicGroupCacheRetentionCleanup::dispatch();

        $this->info('Dispatched DynamicGroupCacheRetentionCleanup');

        return self::SUCCESS;
    }
}
