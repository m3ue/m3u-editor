<?php

namespace App\Sync;

use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\Contracts\SyncPhase;
use Illuminate\Contracts\Container\Container;
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
 *
 * The orchestrator is intentionally synchronous: the underlying phase work is
 * already queued, so running phases inline does not block on actual job
 * execution. A future queue-batch runner (Step 7) will dispatch phases in
 * parallel groups concurrently.
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
            foreach ($plan->steps() as $step) {
                $context = $this->runStep($step, $run, $playlist, $context);
            }
        } catch (Throwable $e) {
            $run->markFailed($e);

            return $run->fresh() ?? $run;
        }

        $run->markCompleted();

        return $run->fresh() ?? $run;
    }

    /**
     * Run a single plan step. Returns the (possibly updated) context.
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
}
