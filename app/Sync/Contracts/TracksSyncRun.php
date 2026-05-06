<?php

namespace App\Sync\Contracts;

use App\Sync\Concerns\InteractsWithSyncRun;
use App\Sync\Middleware\RecordsSyncPhaseCompletion;

/**
 * Marker interface for queued jobs that wish to participate in SyncRun phase
 * tracking. A job opting in must expose its associated SyncRun id and phase
 * slug so that {@see RecordsSyncPhaseCompletion} can
 * update the ledger when the job actually finishes (or fails) on a worker.
 *
 * Combine with the {@see InteractsWithSyncRun} trait to
 * satisfy the interface and pick up a fluent `withSyncContext()` builder.
 */
interface TracksSyncRun
{
    public function syncRunId(): ?int;

    public function syncPhaseSlug(): ?string;

    /**
     * Whether the middleware should also transition the SyncRun's own
     * lifecycle (Pending -> Running on entry, Completed/Failed on exit) when
     * this job runs. Use for jobs that constitute the entire run's work
     * (e.g. ProcessM3uImport on a sync-kind run).
     *
     * Defaults to false: the middleware only updates the phase entry and
     * leaves run lifecycle to the SyncOrchestrator.
     */
    public function closesSyncRun(): bool;
}
