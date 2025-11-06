<?php

namespace App\Console\Commands;

use App\Models\Epg;
use App\Services\EpgCacheService;
use Illuminate\Console\Command;

class GenerateEpgCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:epg-cache-generate {id : The EPG ID to cache}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate cache for an EPG to improve performance';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $id = $this->argument('id');

        $epg = Epg::find($id);
        if (! $epg) {
            $this->error("EPG with ID {$id} not found");

            return 1;
        }

        $this->info("Generating cache for EPG: {$epg->name}");

        $cacheService = new EpgCacheService;

        // Set high memory and time limits for command line execution
        ini_set('memory_limit', '4G');
        set_time_limit(0); // No time limit for CLI

        $start = microtime(true);
        $result = $cacheService->cacheEpgData($epg);
        $duration = microtime(true) - $start;

        if ($result) {
            $this->info('Cache generated successfully in ' . round($duration, 2) . ' seconds');

            return 0;
        } else {
            $this->error('Failed to generate cache');

            return 1;
        }
    }
}
