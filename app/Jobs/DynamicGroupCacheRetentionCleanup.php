<?php

namespace App\Jobs;

use App\Services\DynamicGroupCacheRetentionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Daily cleanup of cached content no longer referenced by any active DynamicGroup.
 * Per-rule retention modes (never_expire / match_group_lifetime / lifetime_plus_days)
 * and the dropped_at grace period on the pivot are evaluated by the service.
 *
 * ShouldBeUnique prevents two cleanups from running concurrently (the schedule
 * already uses withoutOverlapping but this is a belt-and-suspenders).
 */
class DynamicGroupCacheRetentionCleanup implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800; // 30 minutes

    public function __construct()
    {
        $this->onQueue('dynamic-group-cache');
    }

    public function uniqueId(): string
    {
        return 'dynamic-group-cache-retention-cleanup';
    }

    public function handle(DynamicGroupCacheRetentionService $retention): void
    {
        Log::debug('Dynamic Group Cache retention cleanup starting');

        $retention->runAll();

        Log::debug('Dynamic Group Cache retention cleanup complete');
    }
}
