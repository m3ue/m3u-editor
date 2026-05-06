<?php

namespace App\Sync;

use App\Sync\Contracts\SyncPhase;

/**
 * Immutable description of a single step in a {@see SyncPlan}.
 *
 * A step references the phase class to instantiate, whether failure should
 * halt the run (`required`), and an optional group id used to indicate that
 * the step is a member of a parallel group. The orchestrator is currently
 * sequential — group ids are recorded for future use when a queue-batch
 * parallel runner lands (Step 7), but execution order today is the order in
 * which steps were appended to the plan.
 */
final class PlanStep
{
    /**
     * @param  class-string<SyncPhase>  $phaseClass
     */
    public function __construct(
        public readonly string $phaseClass,
        public readonly bool $required = true,
        public readonly ?string $parallelGroup = null,
    ) {}
}
