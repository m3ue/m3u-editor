<?php

namespace App\Console\Commands;

use App\Models\QueueMonitor;
use Illuminate\Console\Command;

class HealStaleQueueMonitors extends Command
{
    protected $signature = 'app:heal-stale-queue-monitors';

    protected $description = 'Mark still-"running" queue_monitor records as failed; intended to run before a fresh queue worker starts, when nothing could possibly still be processing them';

    public function handle(): int
    {
        $count = QueueMonitor::query()
            ->whereNull('finished_at')
            ->where('failed', false)
            ->update([
                'finished_at' => now(),
                'failed' => true,
                'exception_message' => 'Orphaned: no queue worker was running to process this job (marked stale on server startup).',
            ]);

        if ($count > 0) {
            $this->info("Marked {$count} stale queue monitor record(s) as failed.");
        }

        return self::SUCCESS;
    }
}
