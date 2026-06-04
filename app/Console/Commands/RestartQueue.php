<?php

namespace App\Console\Commands;

use App\Models\Job;
use Illuminate\Console\Command;

class RestartQueue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:restart-queue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restart Horizon queue and clear out any pending queue items';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("🔄 Restarting Horizon queue...\n");

        // Terminate Horizon to restart the queue workers
        $this->call('horizon:terminate');

        // Flush the queue to clear out any pending jobs that may be stuck
        $this->call('queue:flush');

        // Truncate the jobs table to remove any remaining job records (optional, but helps keep the database clean)
        Job::truncate();

        $this->info("✅ Horizon queue restarted\n");
    }
}
