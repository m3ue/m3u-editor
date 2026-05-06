<?php

namespace App\Sync\Middleware;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use App\Sync\Concerns\InteractsWithSyncRun;
use App\Sync\Contracts\TracksSyncRun;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job middleware that mirrors a queued job's execution lifecycle onto its
 * associated {@see SyncRun} phase entry.
 *
 * - Before the job runs: writes phase status = Running with `started_at` (no-op
 *   if the orchestrator already marked it Running on dispatch).
 * - On success: writes phase status = Completed with `finished_at`.
 * - On failure: writes phase status = Failed and rethrows so Laravel's normal
 *   retry/failure handling continues to apply.
 *
 * If the job's {@see TracksSyncRun::closesSyncRun()} returns true, the
 * middleware also flips the run-level status: Pending → Running on entry,
 * and Completed/Failed on exit. This is used by jobs that constitute the
 * entire run's work (e.g. ProcessM3uImport on a sync-kind run) so the run
 * doesn't sit in Pending forever.
 *
 * The job opts in by implementing {@see TracksSyncRun} (typically via the
 * {@see InteractsWithSyncRun} trait) and listing this
 * middleware in its `middleware()` method.
 */
class RecordsSyncPhaseCompletion
{
    public function handle(object $job, Closure $next): void
    {
        if (! $job instanceof TracksSyncRun) {
            $next($job);

            return;
        }

        $runId = $job->syncRunId();
        $slug = $job->syncPhaseSlug();

        if ($runId === null || $slug === null) {
            $next($job);

            return;
        }

        $run = SyncRun::find($runId);
        $closesRun = $job->closesSyncRun();

        $run?->markPhaseStarted($slug);

        if ($closesRun && $run !== null && $run->status === SyncRunStatus::Pending) {
            $run->markStarted();
        }

        try {
            $next($job);
        } catch (Throwable $e) {
            try {
                $run?->markPhaseFailed($slug, $e);

                if ($closesRun && $run !== null && ! $run->status->isTerminal()) {
                    $run->markFailed($e);
                }
            } catch (Throwable $inner) {
                // Don't let bookkeeping errors mask the original job failure.
                Log::error('Failed to record SyncRun phase failure', [
                    'sync_run_id' => $runId,
                    'phase' => $slug,
                    'original' => $e->getMessage(),
                    'bookkeeping_error' => $inner->getMessage(),
                ]);
            }

            throw $e;
        }

        $run?->markPhaseCompleted($slug);

        if ($closesRun && $run !== null && ! $run->status->isTerminal()) {
            $run->markCompleted();
        }
    }
}
