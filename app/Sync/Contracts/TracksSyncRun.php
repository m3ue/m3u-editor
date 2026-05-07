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
     * Whether the middleware should transition the SyncRun from Pending to
     * Running when this job begins executing on a worker.
     *
     * Set to true for the job that kicks off the run (e.g. ProcessM3uImport).
     * When combined with `closesSyncRun() === false`, the run stays Running
     * after the job returns — useful when the job only dispatches downstream
     * work (chains, batches) that is the actual run payload. The run is then
     * closed externally (e.g. by SyncListener when SyncCompleted fires).
     *
     * If this job throws, the run is also marked Failed regardless of whether
     * `closesSyncRun()` is true — a job that starts the run crashing means
     * the whole run is dead.
     *
     * Defaults to false.
     */
    public function startsSyncRun(): bool;

    /**
     * Whether the middleware should also transition the SyncRun to
     * Completed/Failed when this job finishes (success or exception).
     *
     * Defaults to false: the middleware only updates the phase entry and
     * leaves run lifecycle to the SyncOrchestrator or an external closer.
     */
    public function closesSyncRun(): bool;
}
