<?php

namespace App\Console\Commands;

use App\Models\Job;
use Illuminate\Console\Command;

class FlushJobsTable extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:flush-jobs-table {all?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flush the jobs table for processing EPGs, Playlists and Channel mapping';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $all = $this->argument('all');
        if ($all) {
            $this->info('Flushing jobs table...');
            $this->info('Clearing: '.Job::count().' jobs');
            Job::truncate();
            $this->info('Jobs table flushed successfully.');
        } else {
            // Anything older than 1 day
            $olderThan = now()->subDay();
            $where = ['created_at', '<=',  $olderThan];
            $this->info("Flushing jobs table where job is older than {$olderThan->toDateTimeString()}...");
            $this->info('Clearing: '.Job::whereDate(...$where)->count().' jobs');
            // truncate() ignores query constraints and always empties the whole
            // table, which would delete fresh staging rows for jobs dispatched
            // but not yet consumed. Use delete() so only the matched rows go.
            Job::whereDate(...$where)->delete();
            $this->info('Jobs table flushed successfully.');
        }
    }
}
