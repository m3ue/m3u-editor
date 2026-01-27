<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEpgSDImport;
use App\Models\Epg;
use Illuminate\Console\Command;

class RefreshEpgSchedulesDirectData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:refresh-epg-sd {epg} {force?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh SchedulesDirect data for a specific EPG. Use force flag to force refresh regardless of last modified time.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $epgId = $this->argument('epg');
        $force = $this->argument('force') ?? false;
        $this->info("Refreshing SchedulesDirect data for EPG with ID: {$epgId}, force: ".($force ? 'true' : 'false'));
        $epg = Epg::where([
            ['source_type', 'schedules_direct'],
            ['id', $epgId],
        ])->first();

        if (! $epg) {
            $this->error("EPG with ID {$epgId} not found or not a SchedulesDirect EPG.");

            return;
        }
        dispatch(new ProcessEpgSDImport(epg: $epg, force: $force));
        $this->info('Dispatched SchedulesDirect sync job for EPG ID: '.$epgId);

    }
}
