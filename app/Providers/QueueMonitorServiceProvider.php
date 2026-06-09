<?php

namespace App\Providers;

use App\Models\QueueMonitor;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Throwable;

class QueueMonitorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Queue::before(static fn (JobProcessing $event) => self::jobStarted($event->job));
        Queue::after(static fn (JobProcessed $event) => self::jobFinished($event->job));
        Queue::failing(static fn (JobFailed $event) => self::jobFinished($event->job, true, $event->exception));
        Queue::exceptionOccurred(static fn (JobExceptionOccurred $event) => self::jobFinished($event->job, true, $event->exception));
    }

    protected static function jobStarted(JobContract $job): void
    {
        try {
            $payload = $job->payload();
            $jobId = QueueMonitor::getJobId($job);
            $batchId = $payload['batchId'] ?? null;
            $batchName = null;

            if ($batchId) {
                $batchName = DB::table('job_batches')->where('id', $batchId)->value('name');
            }

            $monitor = QueueMonitor::create([
                'job_id' => $jobId,
                'name' => $job->resolveName(),
                'queue' => $job->getQueue(),
                'batch_id' => $batchId,
                'batch_name' => $batchName,
                'started_at' => now(),
                'attempt' => $job->attempts(),
                'progress' => 0,
            ]);

            // Mark any orphaned duplicate records (same job_id, still running) as failed.
            QueueMonitor::where('id', '!=', $monitor->id)
                ->where('job_id', $jobId)
                ->where('failed', false)
                ->whereNull('finished_at')
                ->update(['finished_at' => now(), 'failed' => true]);
        } catch (Throwable) {
            // Never let monitoring break the actual job.
        }
    }

    protected static function jobFinished(JobContract $job, bool $failed = false, ?Throwable $exception = null): void
    {
        try {
            $monitor = QueueMonitor::where('job_id', QueueMonitor::getJobId($job))
                ->where('attempt', $job->attempts())
                ->orderByDesc('started_at')
                ->first();

            if ($monitor === null) {
                return;
            }

            $attributes = [
                'progress' => 100,
                'finished_at' => now(),
                'failed' => $failed,
            ];

            if ($exception !== null) {
                $attributes['exception_message'] = mb_strcut($exception->getMessage(), 0, 65535);
            }

            $monitor->update($attributes);
        } catch (Throwable) {
            // Never let monitoring break the actual job.
        }
    }
}
