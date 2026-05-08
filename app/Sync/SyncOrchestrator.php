<?php

namespace App\Sync;

use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\Contracts\BatchablePhase;
use App\Sync\Contracts\ChainablePhase;
use App\Sync\Contracts\SyncPhase;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executes a {@see SyncPlan} against a {@see SyncRun} ledger row.
 *
 * Responsibilities:
 *   - Resolve each phase from the container (constructor DI works for free).
 *   - Honour `shouldRun()` gating — non-applicable phases are recorded as
 *     Skipped on the run rather than invoked.
 *   - Thread a shared `$context` array between phases. Each phase may return
 *     context updates which are merged into the running context.
 *   - Mark the run Started before the first phase and Completed after the
 *     last phase succeeds.
 *   - When a `required` phase throws, mark the run Failed and stop. When an
 *     optional phase throws, log it, record on the run-level error log, and
 *     continue with subsequent phases.
 *   - Collect jobs from consecutive {@see ChainablePhase} steps that share a
 *     `chainGroup` and dispatch them as a single `Bus::chain([...])`. Each
 *     phase's jobs are appended in declaration order so the queue runs them
 *     strictly sequentially.
 *   - Collect jobs from consecutive {@see BatchablePhase} steps that share a
 *     `parallelGroup` and dispatch them as a single `Bus::batch([...])` so
 *     the queue may run them concurrently across workers.
 *
 * The orchestrator itself runs synchronously: the underlying phase work is
 * already queued (either dispatched fire-and-forget, or queued as part of an
 * assembled chain or batch), so running phases inline does not block on
 * actual job execution.
 */
class SyncOrchestrator
{
    public function __construct(private readonly Container $container) {}

    /**
     * Execute the given plan for the run. Returns the (refreshed) SyncRun.
     *
     * @param  array<string, mixed>  $context
     */
    public function execute(SyncRun $run, SyncPlan $plan, array $context = []): SyncRun
    {
        $playlist = $run->playlist;

        if (! $playlist instanceof Playlist) {
            throw new \RuntimeException("SyncRun [{$run->id}] is not associated with a playlist.");
        }

        $run->markStarted();

        try {
            $context = $this->runSteps($plan->steps(), $run, $playlist, $context);
        } catch (Throwable $e) {
            $run->markFailed($e);

            return $run;
        }

        $run->markCompleted();

        return $run;
    }

    /**
     * Walk the plan's steps. Consecutive steps sharing a `chainGroup` are
     * collected into a single Bus::chain dispatched once at the boundary.
     *
     * @param  array<int, PlanStep>  $steps
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runSteps(array $steps, SyncRun $run, Playlist $playlist, array $context): array
    {
        $i = 0;
        $count = count($steps);

        while ($i < $count) {
            $step = $steps[$i];

            if ($step->chainGroup !== null) {
                // Find the contiguous range of steps in this chain group.
                $end = $i;
                while ($end + 1 < $count && $steps[$end + 1]->chainGroup === $step->chainGroup) {
                    $end++;
                }

                $context = $this->runChainGroup(
                    array_slice($steps, $i, $end - $i + 1),
                    $run,
                    $playlist,
                    $context,
                );

                $i = $end + 1;

                continue;
            }

            if ($step->parallelGroup !== null) {
                // Find the contiguous range of steps in this parallel group.
                $end = $i;
                while ($end + 1 < $count && $steps[$end + 1]->parallelGroup === $step->parallelGroup) {
                    $end++;
                }

                $context = $this->runParallelGroup(
                    array_slice($steps, $i, $end - $i + 1),
                    $run,
                    $playlist,
                    $context,
                );

                $i = $end + 1;

                continue;
            }

            $context = $this->runStep($step, $run, $playlist, $context);
            $i++;
        }

        return $context;
    }

    /**
     * Run a single (non-chain) plan step. Returns the (possibly updated)
     * context.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runStep(PlanStep $step, SyncRun $run, Playlist $playlist, array $context): array
    {
        /** @var SyncPhase $phase */
        $phase = $this->container->make($step->phaseClass);

        if (! $phase->shouldRun($playlist)) {
            $run->markPhaseSkipped($phase::slug(), reason: 'shouldRun returned false');

            return $context;
        }

        try {
            $updates = $phase->run($run, $playlist, $context);

            return array_merge($context, $updates);
        } catch (Throwable $e) {
            // AbstractPhase already called markPhaseFailed before rethrowing,
            // which appended to the run-level error log. For required steps
            // we propagate to halt the orchestrator; for optional steps we
            // swallow and continue so siblings still run.
            if ($step->required) {
                throw $e;
            }

            Log::warning('Sync phase failed (optional, continuing)', [
                'sync_run_id' => $run->id,
                'phase' => $phase::slug(),
                'message' => $e->getMessage(),
            ]);

            return $context;
        }
    }

    /**
     * Execute a chain group: collect jobs from each {@see ChainablePhase} in
     * declaration order, then dispatch the assembled `Bus::chain`. Each
     * phase's lifecycle (markPhaseStarted/Completed/Skipped/Failed) is
     * recorded as it contributes, mirroring dispatch-time semantics.
     *
     * If no phase contributes jobs (all skipped or all returned []), nothing
     * is dispatched. A `chainContribution` failure on a `required: false`
     * phase is logged and that phase is excluded from the chain; the remaining
     * phases still contribute. A failure on a `required: true` phase
     * propagates and halts the orchestrator before dispatch.
     *
     * @param  array<int, PlanStep>  $steps
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runChainGroup(array $steps, SyncRun $run, Playlist $playlist, array $context): array
    {
        /** @var array<int, ShouldQueue> $jobs */
        $jobs = [];
        $contributors = [];

        foreach ($steps as $step) {
            /** @var ChainablePhase $phase */
            $phase = $this->container->make($step->phaseClass);

            if (! $phase->shouldRun($playlist)) {
                $run->markPhaseSkipped($phase::slug(), reason: 'shouldRun returned false');

                continue;
            }

            $run->markPhaseStarted($phase::slug());

            try {
                $contribution = $phase->chainJobs($run, $playlist, $context);
            } catch (Throwable $e) {
                $run->markPhaseFailed($phase::slug(), $e);

                if ($step->required) {
                    throw $e;
                }

                Log::warning('Sync chain phase failed (optional, continuing)', [
                    'sync_run_id' => $run->id,
                    'phase' => $phase::slug(),
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($contribution as $job) {
                $jobs[] = $job;
            }

            $contributors[] = $phase::slug();
            $run->markPhaseCompleted($phase::slug());
        }

        if ($jobs !== []) {
            Bus::chain($jobs)->dispatch();
        }

        return array_merge($context, [
            'chain_dispatched' => $contributors,
            'chain_job_count' => count($jobs),
        ]);
    }

    /**
     * Execute a parallel group: collect jobs from each {@see BatchablePhase}
     * in the block, then dispatch the assembled `Bus::batch`. Each phase's
     * lifecycle (markPhaseStarted/Completed/Skipped/Failed) is recorded as
     * it contributes, mirroring dispatch-time semantics used by chain groups.
     *
     * If no phase contributes jobs (all skipped or all returned []), nothing
     * is dispatched. A `batchJobs` failure on a `required: false` phase is
     * logged and that phase is excluded from the batch; sibling phases still
     * contribute. A failure on a `required: true` phase propagates and halts
     * the orchestrator before dispatch.
     *
     * @param  array<int, PlanStep>  $steps
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runParallelGroup(array $steps, SyncRun $run, Playlist $playlist, array $context): array
    {
        /** @var array<int, ShouldQueue> $jobs */
        $jobs = [];
        $contributors = [];

        foreach ($steps as $step) {
            /** @var BatchablePhase $phase */
            $phase = $this->container->make($step->phaseClass);

            if (! $phase->shouldRun($playlist)) {
                $run->markPhaseSkipped($phase::slug(), reason: 'shouldRun returned false');

                continue;
            }

            $run->markPhaseStarted($phase::slug());

            try {
                $contribution = $phase->batchJobs($run, $playlist, $context);
            } catch (Throwable $e) {
                $run->markPhaseFailed($phase::slug(), $e);

                if ($step->required) {
                    throw $e;
                }

                Log::warning('Sync parallel phase failed (optional, continuing)', [
                    'sync_run_id' => $run->id,
                    'phase' => $phase::slug(),
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($contribution as $job) {
                $jobs[] = $job;
            }

            $contributors[] = $phase::slug();
            $run->markPhaseCompleted($phase::slug());
        }

        $batchId = null;

        if ($jobs !== []) {
            $runId = $run->id;
            $batch = Bus::batch($jobs)
                ->name(sprintf('sync_run:%d:parallel', $runId))
                ->allowFailures()
                ->finally(function (Batch $batch) use ($runId): void {
                    Log::info('Sync parallel batch finished', [
                        'sync_run_id' => $runId,
                        'batch_id' => $batch->id,
                        'total_jobs' => $batch->totalJobs,
                        'failed_jobs' => $batch->failedJobs,
                    ]);
                })
                ->dispatch();

            $batchId = $batch->id;
        }

        return array_merge($context, [
            'batch_dispatched' => $contributors,
            'batch_job_count' => count($jobs),
            'batch_id' => $batchId,
        ]);
    }
}
