<?php

namespace App\Sync;

use App\Models\SyncRun;
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
     * Declare a parallel group of phases. Steps in the group share a parallel
     * group id but the current orchestrator still runs them sequentially in
     * declaration order. Any phase in a parallel group is treated as
     * `required: false` by default since one failure should not block its
     * sibling phases.
     *
     * @param  array<int, class-string<SyncPhase>>  $phaseClasses
     */
    public function parallel(array $phaseClasses, bool $required = false): self
    {
        if (empty($phaseClasses)) {
            return $this;
        }

        $groupId = 'g'.(count($this->steps) + 1);

        foreach ($phaseClasses as $class) {
            $this->assertPhaseClass($class);
            $this->steps[] = new PlanStep($class, required: $required, parallelGroup: $groupId);
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
}
