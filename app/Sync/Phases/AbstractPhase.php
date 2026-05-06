<?php

namespace App\Sync\Phases;

use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\Contracts\SyncPhase;
use Throwable;

/**
 * Convenience base class for phases that follow the standard
 * "mark started -> do work -> mark completed (or failed)" pattern.
 *
 * Subclasses implement {@see execute()} which contains the actual dispatch
 * logic. Any thrown exception is caught, recorded on the SyncRun via
 * markPhaseFailed (which mirrors into the run-level error log) and rethrown
 * so the orchestrator can decide whether subsequent phases should still run.
 */
abstract class AbstractPhase implements SyncPhase
{
    /**
     * Default policy: phases run unless their config says otherwise. Subclasses
     * should override when they have a meaningful "is this configured?" check.
     */
    public function shouldRun(Playlist $playlist): bool
    {
        return true;
    }

    /**
     * Wrap {@see execute()} with phase-state bookkeeping on the SyncRun.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function run(SyncRun $run, Playlist $playlist, array $context = []): array
    {
        $run->markPhaseStarted(static::slug());

        try {
            $result = $this->execute($run, $playlist, $context) ?? [];
        } catch (Throwable $e) {
            $run->markPhaseFailed(static::slug(), $e);

            throw $e;
        }

        $run->markPhaseCompleted(static::slug());

        return $result;
    }

    /**
     * Perform the phase work. May return an array of context updates to merge
     * into the orchestrator's shared context.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    abstract protected function execute(SyncRun $run, Playlist $playlist, array $context): ?array;
}
