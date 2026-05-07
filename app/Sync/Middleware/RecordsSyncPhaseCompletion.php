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
 * Two independent run-level lifecycle flags are supported:
 *
 * - {@see TracksSyncRun::startsSyncRun()} — when true, the middleware also
 *   transitions the run Pending → Running on entry. On exception, the run is
 *   also marked Failed regardless of `closesSyncRun()`, because a job that
 *   starts a run crashing means the run is dead.
 *
 * - {@see TracksSyncRun::closesSyncRun()} — when true, the middleware also
 *   marks the run Completed on success. Use for jobs whose completion is the
 *   natural end of the run's work.
 *
 * Combining `startsSyncRun: true` with `closesSyncRun: false` lets a job
 * mark the run as in-flight without terminating it — suitable for jobs that
 * only dispatch downstream chains (e.g. ProcessM3uImport for Xtream playlists
 * where the actual work is in the chained jobs). The run is then closed
 * externally when the downstream work signals completion.
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
        $startsRun = $job->startsSyncRun();
        $closesRun = $job->closesSyncRun();

        $run?->markPhaseStarted($slug);

        if ($startsRun && $run !== null && $run->status === SyncRunStatus::Pending) {
            $run->markStarted();
        }

        try {
            $next($job);
        } catch (Throwable $e) {
            try {
                $run?->markPhaseFailed($slug, $e);

                // If this job starts or closes the run, a thrown exception means
                // the run is dead — mark it Failed regardless of which flag is set.
                if (($startsRun || $closesRun) && $run !== null && ! $run->status->isTerminal()) {
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
