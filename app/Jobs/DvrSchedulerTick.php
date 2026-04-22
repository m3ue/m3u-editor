<?php

namespace App\Jobs;

use App\Services\DvrSchedulerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * DvrSchedulerTick — Runs every minute via the Laravel scheduler.
 *
 * Dispatches onto the 'dvr' queue so it runs inside the dvr-queue Horizon supervisor.
 */
class DvrSchedulerTick implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('dvr');
    }

    public function handle(DvrSchedulerService $scheduler): void
    {
        Log::debug('DVR scheduler tick starting');
        $scheduler->tick();
        Log::debug('DVR scheduler tick complete');
    }
}
