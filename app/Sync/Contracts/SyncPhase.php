<?php

namespace App\Sync\Contracts;

use App\Models\Playlist;
use App\Models\SyncRun;

/**
 * A SyncPhase represents a single, named stage of a playlist sync that the
 * SyncOrchestrator can execute against a {@see SyncRun} ledger row.
 *
 * Phases are intentionally thin wrappers around the queued jobs that do the
 * actual work. In Step 3 they record dispatch-time state on the SyncRun
 * (Running -> Completed / Skipped / Failed). In Step 4 a job middleware
 * will upgrade that to true completion semantics by writing back when the
 * underlying jobs finish executing.
 */
interface SyncPhase
{
    /**
     * Stable, machine-readable identifier for this phase. Used as the key in
     * {@see SyncRun::$phases} and in plan definitions. Lower_snake_case.
     */
    public static function slug(): string;

    /**
     * Whether this phase should execute for the given playlist. Phases that
     * read playlist configuration to decide if they apply (e.g. "find_replace
     * has at least one enabled rule") return false here, and the orchestrator
     * will mark the phase as Skipped instead of invoking {@see run()}.
     */
    public function shouldRun(Playlist $playlist): bool;

    /**
     * Execute the phase. Implementations are responsible for transitioning the
     * phase status on the SyncRun (markPhaseStarted -> markPhaseCompleted /
     * markPhaseFailed). Most phases simply dispatch the underlying job(s) and
     * mark themselves complete on successful dispatch.
     *
     * @param  array<string, mixed>  $context  shared data passed between phases by the orchestrator
     * @return array<string, mixed> optional context updates merged into the run context
     */
    public function run(SyncRun $run, Playlist $playlist, array $context = []): array;
}
