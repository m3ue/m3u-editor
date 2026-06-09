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

        // Clear the queue to prevent any stale data issues
        $this->call('queue:clear', [
            '--force' => true,
        ]);
        $this->call('queue:clear', [
            '--queue' => 'import',
            '--force' => true,
        ]);
        $this->call('queue:clear', [
            '--queue' => 'file_sync',
            '--force' => true,
        ]);

        // Truncate the jobs table to remove any remaining job records (optional, but helps keep the database clean)
        Job::truncate();

        $this->info("✅ Horizon queue restarted\n");
    }
}
