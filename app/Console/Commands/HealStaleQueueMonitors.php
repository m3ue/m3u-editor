<?php

namespace App\Console\Commands;

use App\Models\QueueMonitor;
use Illuminate\Console\Command;

class HealStaleQueueMonitors extends Command
{
    protected $signature = 'app:heal-stale-queue-monitors
        {--max-age= : Override the staleness threshold (in minutes). Defaults to the default queue connection\'s retry_after.}';

    protected $description = 'Mark still-"running" queue_monitor records as failed once they exceed the staleness threshold (queue retry_after, or --max-age in minutes). Safe to run alongside a live worker — recently started jobs are left alone.';

    public function handle(): int
    {
        $thresholdMinutes = $this->resolveThresholdMinutes();

        $count = QueueMonitor::query()
            ->whereNull('finished_at')
            ->where('failed', false)
            ->where('started_at', '<', now()->subMinutes($thresholdMinutes))
            ->update([
                'finished_at' => now(),
                'failed' => true,
                'exception_message' => 'Orphaned: no queue worker has acknowledged this job within retry_after (marked stale).',
            ]);

        if ($count > 0) {
            $this->info("Marked {$count} stale queue monitor record(s) as failed (older than {$thresholdMinutes} min).");
        }

        return self::SUCCESS;
    }

    private function resolveThresholdMinutes(): int
    {
        if ($this->option('max-age') !== null) {
            return max(1, (int) $this->option('max-age'));
        }

        $retryAfter = (int) config(
            'queue.connections.'.config('queue.default').'.retry_after',
            7200,
        );

        return max(1, (int) ceil($retryAfter / 60));
    }
}
