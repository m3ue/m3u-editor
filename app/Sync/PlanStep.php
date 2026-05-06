<?php

namespace App\Sync;

use App\Sync\Contracts\ChainablePhase;
use App\Sync\Contracts\SyncPhase;

/**
 * Immutable description of a single step in a {@see SyncPlan}.
 *
 * A step references the phase class to instantiate, whether failure should
 * halt the run (`required`), an optional parallel group id, and an optional
 * chain group id.
 *
 * - `parallelGroup` (legacy): the orchestrator currently runs grouped phases
 *   sequentially in declaration order; the future queue-batch runner will
 *   fan them out concurrently.
 * - `chainGroup`: phases sharing a chain group must implement
 *   {@see ChainablePhase} and contribute jobs to a single
 *   `Bus::chain([...])` that the orchestrator dispatches once at the end of
 *   the chain block. Used to enforce strict ordering across queue workers
 *   (e.g. STRM sync must run only after Find/Replace finishes).
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
        public readonly ?string $chainGroup = null,
    ) {}
}
