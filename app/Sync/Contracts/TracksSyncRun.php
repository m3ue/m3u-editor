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
}
