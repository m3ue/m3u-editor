<?php

namespace App\Sync\Contracts;

use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\SyncPlan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;

/**
 * A SyncPhase whose work can participate in a {@see Bus}
 * chain assembled across multiple consecutive phases.
 *
 * Standard phases dispatch their jobs immediately and return — the queue then
 * runs them concurrently with sibling phases. That's fine when a phase's
 * downstream work is independent of other phases, but it cannot guarantee
 * ordering between, e.g., Find/Replace and STRM sync (STRM must observe the
 * processed `title_custom` values F/R writes).
 *
 * When a SyncPlan declares a {@see SyncPlan::chain()} block, the
 * orchestrator collects the jobs returned by each chainable phase in that
 * block and dispatches them as a single `Bus::chain([...])`. Phase-state
 * bookkeeping (markPhaseStarted/Completed) is still recorded per-phase as
 * each phase is asked to contribute, mirroring the dispatch-time semantics
 * used elsewhere in the orchestrator.
 */
interface ChainablePhase extends SyncPhase
{
    /**
     * Return the jobs this phase contributes to the surrounding Bus::chain.
     *
     * Return an empty array to participate in the chain without contributing
     * any work (useful when {@see SyncPhase::shouldRun()} cannot fully
     * determine applicability without inspecting context). Returning a
     * non-empty array implies all jobs are queueable ({@see ShouldQueue}).
     *
     * @param  array<string, mixed>  $context
     * @return array<int, ShouldQueue>
     */
    public function chainJobs(SyncRun $run, Playlist $playlist, array $context = []): array;
}
