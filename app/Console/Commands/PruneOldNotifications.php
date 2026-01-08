<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneOldNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prune-old-notifications {--days=7 : The number of days to keep notifications}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune old notifications from the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = $this->option('days') ?? 7;
        $this->info('Cleaning notifications older than '.$days.' days...');
        DB::table('notifications')
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info('Notifications have been cleaned up!');
    }
}
