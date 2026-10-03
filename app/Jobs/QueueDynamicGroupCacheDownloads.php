<?php

namespace App\Jobs;

use App\Models\DynamicGroup;
use App\Services\CachedContentDispatchService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fans one dynamic group's auto-cache out through
 * `CachedContentDispatchService::dispatchForDynamicGroup()` in the
 * background, off the sync pipeline (SyncDynamicGroups has a 900s timeout
 * and owns the pipeline-phase `finally` — the fan-out must not run inline).
 *
 * Runs on the `default` queue like QueueCachedContentDownloads: the cache
 * workers are tied up by long-running downloads and this job only creates
 * rows and dispatches. Unique per group id until processing starts, so
 * pipeline + cron + manual runs don't stack duplicates in the queue, while
 * a sync that changes membership while a fan-out is already running can
 * still queue a follow-up run (dispatchForDynamicGroup() is idempotent).
 */
class QueueDynamicGroupCacheDownloads implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 600;

    public function __construct(
        public int $dynamicGroupId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->dynamicGroupId;
    }

    /**
     * Execute the job.
     */
    public function handle(CachedContentDispatchService $service): void
    {
        $group = DynamicGroup::find($this->dynamicGroupId);
        if (! $group) {
            return;
        }

        $counts = $service->dispatchForDynamicGroup($group);

        // Background sync — a user notification per group refresh would be
        // noise; the Cached Downloads table shows the resulting rows.
        Log::info('QueueDynamicGroupCacheDownloads: dispatched', [
            'dynamic_group_id' => $group->id,
            'counts' => $counts,
        ]);
    }
}
