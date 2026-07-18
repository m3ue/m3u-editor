<?php

namespace App\Jobs;

use App\Services\DvrSchedulerService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * DvrDeepScan — Match all enabled DVR rules against the EPG lookahead window.
 *
 * Runs on a daily schedule (default 03:00, configurable via dvr.deep_scan_hour).
 * Picks up new EPG data added since the previous run so airings more than 30
 * minutes from now are still scheduled. The per-minute tick only handles
 * trigger/stop of already-scheduled recordings; this is the steady-state
 * rescan that catches EPG refreshes between user actions.
 *
 * TODO: replace this daily cadence with an EPG-event-driven scan that fires
 * when XMLTV or Schedules Direct imports add new programmes. The daily scan
 * will then become a belt-and-suspenders fallback.
 */
class DvrDeepScan implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('dvr');
    }

    public function handle(DvrSchedulerService $scheduler): void
    {
        $lookaheadDays = max(1, (int) config('dvr.initial_lookahead_days', 14));
        $lookaheadMinutes = $lookaheadDays * 24 * 60;

        Log::info("DVR deep scan starting (lookahead: {$lookaheadDays} day(s))");

        $scheduler->matchAndSchedule($lookaheadMinutes);

        Log::info('DVR deep scan complete');
    }
}
