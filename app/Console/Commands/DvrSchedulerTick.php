<?php

namespace App\Console\Commands;

use App\Services\DvrSchedulerService;
use Illuminate\Console\Command;

class DvrSchedulerTick extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:dvr-scheduler-tick';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Trigger and stop scheduled DVR recordings that are due';

    /**
     * Execute the console command.
     *
     * Runs synchronously within the scheduler process, mirroring app:refresh-playlist
     * and app:refresh-epg, so an idle tick (no rules, no active recordings) never
     * shows up as a job in Horizon/queue monitoring. Actual recording work is still
     * dispatched to the dvr queue by DvrSchedulerService via StartDvrRecording/StopDvrRecording.
     */
    public function handle(DvrSchedulerService $scheduler): void
    {
        $scheduler->tick();
    }
}
