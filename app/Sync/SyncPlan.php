<?php

namespace App\Sync;

use App\Models\SyncRun;
use App\Sync\Contracts\BatchablePhase;
use App\Sync\Contracts\ChainablePhase;
use App\Sync\Contracts\SyncPhase;
use InvalidArgumentException;

/**
 * Fluent builder describing the ordered set of phases the
 * {@see SyncOrchestrator} should execute against a {@see SyncRun}.
 *
 * Plans are built once and reused per run:
 *
 *     SyncPlan::make('playlist.post_sync')
 *         ->phase(FindReplaceAndSortAlphaPhase::class)
 *         ->phase(ChannelScanPhase::class)
 *         ->parallel([
 *             AutoSyncToCustomPhase::class,
 *             PlexDvrSyncPhase::class,
 *         ])
 *         ->phase(PostProcessPhase::class, required: false)
 *         ->phase(PluginDispatchPhase::class, required: false);
 *
 * Phases marked `required: true` (the default) halt the run on failure. The
 * orchestrator records the failure on the SyncRun and stops dispatching
 * subsequent phases. `required: false` phases record the error but allow the
 * run to continue.
 *
 * The `parallel()` API records a parallel-group id on each step. The current
 * orchestrator executes steps in declaration order regardless of group; a
 * future queue-batch runner (Step 7) will dispatch grouped steps concurrently.
 */
final class SyncPlan
{
    /** @var array<int, PlanStep> */
    private array $steps = [];

    private function __construct(public readonly string $name) {}

    public static function make(string $name): self
    {
        return new self($name);
    }

    /**
     * @param  class-string<SyncPhase>  $phaseClass
     */
    public function phase(string $phaseClass, bool $required = true): self
    {
        $this->assertPhaseClass($phaseClass);
        $this->steps[] = new PlanStep($phaseClass, required: $required);

        return $this;
    }

    /**
     * Declare a parallel group of phases. Each phase must implement
     * {@see BatchablePhase} and contributes jobs to a single `Bus::batch`
     * the orchestrator dispatches at the end of the block. The queue runs
     * the contributed jobs concurrently with no ordering between them.
     *
     * Phases in a parallel group are treated as `required: false` by default
     * since one failure should not block its sibling phases.
     *
     * @param  array<int, class-string<BatchablePhase>>  $phaseClasses
     */
    public function parallel(array $phaseClasses, bool $required = false): self
    {
        if (empty($phaseClasses)) {
            return $this;
        }

        $groupId = 'g'.(count($this->steps) + 1);

        foreach ($phaseClasses as $class) {
            $this->assertBatchablePhaseClass($class);
            $this->steps[] = new PlanStep($class, required: $required, parallelGroup: $groupId);
        }

        return $this;
    }

    /**
     * Declare a chain block: each phase in the block must implement
     * {@see ChainablePhase} and contributes jobs to a single `Bus::chain`
     * the orchestrator dispatches at the end of the block. Strict ordering
     * across queue workers is preserved (the queue runs the next chained
     * job only after the previous one completes).
     *
     * Use this when downstream phases depend on side effects of an earlier
     * phase's queued work (e.g. STRM sync must observe processed
     * `title_custom` values written by Find/Replace).
     *
     * Phases in a chain block are treated as `required: false` by default
     * because a single failure in the chain (e.g. F/R failing) should not
     * be allowed to halt unrelated post-sync work; the failure is recorded
     * on the SyncRun's error log and the chain itself stops at the failed
     * job (Bus::chain semantics).
     *
     * @param  array<int, class-string<ChainablePhase>>  $phaseClasses
     */
    public function chain(array $phaseClasses, bool $required = false): self
    {
        if (empty($phaseClasses)) {
            return $this;
        }

        $groupId = 'c'.(count($this->steps) + 1);

        foreach ($phaseClasses as $class) {
            $this->assertChainablePhaseClass($class);
            $this->steps[] = new PlanStep($class, required: $required, chainGroup: $groupId);
        }

        return $this;
    }

    /**
     * @return array<int, PlanStep>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }

    /**
     * @param  class-string  $class
     */
    private function assertPhaseClass(string $class): void
    {
        if (! is_subclass_of($class, SyncPhase::class)) {
            throw new InvalidArgumentException(
                "Phase class [{$class}] must implement ".SyncPhase::class,
            );
        }
    }

    /**
     * @param  class-string  $class
     */
    private function assertChainablePhaseClass(string $class): void
    {
        if (! is_subclass_of($class, ChainablePhase::class)) {
            throw new InvalidArgumentException(
                "Phase class [{$class}] used in chain() must implement ".ChainablePhase::class,
            );
        }
    }

    /**
     * @param  class-string  $class
     */
    private function assertBatchablePhaseClass(string $class): void
    {
        if (! is_subclass_of($class, BatchablePhase::class)) {
            throw new InvalidArgumentException(
                "Phase class [{$class}] used in parallel() must implement ".BatchablePhase::class,
            );
        }
    }
}
