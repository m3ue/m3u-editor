<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Jobs\GenerateEpgCache;
use App\Jobs\ProcessEpgImport;
use App\Models\Epg;
use Cron\CronExpression;
use Illuminate\Console\Command;

class RefreshEpg extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:refresh-epg {epg?} {force?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh EPGs in batch (or specific EPG when ID provided)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $epgId = $this->argument('epg');
        if ($epgId) {
            $force = $this->argument('force') ?? false;
            $this->info("Refreshing EPG with ID: {$epgId}");
            $epg = Epg::findOrFail($epgId);
            dispatch(new ProcessEpgImport($epg, (bool) $force));
            $this->info('Dispatched EPG for refresh');
        } else {
            $this->info('Refreshing all EPGs');

            // First, let's see if we have any EPGs that have processed, but errored out creating a cache
            $failed = Epg::query()
                ->where([
                    ['status', Status::Failed],
                    ['auto_sync', '=', true],
                    ['is_cached', '=', false],
                ])->whereNotNull('synced')->get();
            foreach ($failed as $epg) {
                $this->info("Attempting to re-cache EPG: {$epg->id}");
                dispatch(new GenerateEpgCache($epg));
            }

            // Next, let's get all EPGs that are not currently processing and check if they are due for a sync
            $epgs = Epg::query()->where([
                ['status', '!=', Status::Processing],
                ['auto_sync', '=', true],
            ])->whereNotNull('synced');

            $totalEpgs = $epgs->count();
            if ($totalEpgs === 0) {
                $this->info('No EPGs ready refresh');

                return;
            }

            $count = 0;
            $epgs->get()->each(function (Epg $epg) use (&$count) {
                $cronExpression = new CronExpression($epg->sync_interval);

                // Check if sync is due (with a 1-minute buffer)
                $lastRun = $epg->synced ?? now()->subYears(1);
                $nextDue = $cronExpression->getNextRunDate($lastRun->toDateTimeImmutable());

                if (now() >= $nextDue) {
                    $count++;
                    dispatch(new ProcessEpgImport($epg));
                }
            });
            $this->info('Dispatched ' . $count . ' epgs for refresh');
        }
    }
}
