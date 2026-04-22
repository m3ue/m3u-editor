<?php

namespace App\Jobs;

use App\Services\DvrRetentionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * DvrRetentionCleanup — Enforce keepLast, retention_days, and disk quota policies.
 *
 * Should be scheduled hourly.
 */
class DvrRetentionCleanup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('dvr');
    }

    public function handle(DvrRetentionService $retention): void
    {
        Log::debug('DVR retention cleanup starting');
        $retention->runAll();
        Log::debug('DVR retention cleanup complete');
    }
}
