<?php

namespace App\Sync\Contracts;

use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\SyncPlan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;

/**
 * A SyncPhase whose work can participate in a {@see Bus} batch
 * assembled across multiple sibling phases.
 *
 * Standard parallel phases each dispatch their own jobs immediately and the
 * queue runs them concurrently, but there is no shared lifecycle: callers
 * cannot observe overall progress or react when the entire group finishes.
 *
 * When a SyncPlan declares a {@see SyncPlan::parallel()} block, the
 * orchestrator collects the jobs returned by each batchable phase in that
 * block and dispatches them as a single `Bus::batch([...])`. Phase-state
 * bookkeeping (markPhaseStarted/Completed) is still recorded per-phase as
 * each phase is asked to contribute, mirroring the dispatch-time semantics
 * used elsewhere in the orchestrator.
 */
interface BatchablePhase extends SyncPhase
{
    /**
     * Return the jobs this phase contributes to the surrounding Bus::batch.
     *
     * Return an empty array to participate in the batch without contributing
     * any work (useful when {@see SyncPhase::shouldRun()} cannot fully
     * determine applicability without inspecting context). Returning a
     * non-empty array implies all jobs are queueable ({@see ShouldQueue}).
     *
     * @param  array<string, mixed>  $context
     * @return array<int, ShouldQueue>
     */
    public function batchJobs(SyncRun $run, Playlist $playlist, array $context = []): array;
}
