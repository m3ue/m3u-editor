<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Jobs\ProcessEpgImport;
use App\Models\Epg;
use Carbon\CarbonInterval;
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
            dispatch(new ProcessEpgImport($epg, (bool)$force));
            $this->info('Dispatched EPG for refresh');
        } else {
            $this->info('Refreshing all EPGs');
            // Get all EPGs that are not currently processing
            $epgs = Epg::query()->where(
                'status',
                '!=',
                Status::Processing,
            )->whereNotNull('synced');

            $totalEpgs = $epgs->count();
            if ($totalEpgs === 0) {
                $this->info('No EPGs ready refresh');
                return;
            }

            $count = 0;
            $epgs->get()->each(function (Epg $epg) use (&$count) {
                // Check the sync interval to see if we need to refresh yet
                $isDue = (new CronExpression($epg->sync_interval))->isDue();
                if ($isDue) {
                    $count++;
                    dispatch(new ProcessEpgImport($epg));
                }
            });
            $this->info('Dispatched ' . $count . ' epgs for refresh');
        }
        return;
    }
}
