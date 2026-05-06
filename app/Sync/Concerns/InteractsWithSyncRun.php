<?php

namespace App\Sync\Concerns;

use App\Models\SyncRun;
use App\Sync\Contracts\TracksSyncRun;

/**
 * Trait that satisfies the {@see TracksSyncRun} interface
 * for a queued job. Use on jobs whose completion should update the SyncRun
 * phase ledger:
 *
 *     class MyJob implements ShouldQueue, TracksSyncRun
 *     {
 *         use Queueable, InteractsWithSyncRun;
 *
 *         public function middleware(): array
 *         {
 *             return [new RecordsSyncPhaseCompletion()];
 *         }
 *     }
 *
 * The phase orchestrator fluent-attaches context via {@see withSyncContext()}
 * before the job is dispatched. The middleware then reads the trait's public
 * properties on the executing worker to record start/finish state.
 */
trait InteractsWithSyncRun
{
    public ?int $syncRunId = null;

    public ?string $syncPhaseSlug = null;

    /**
     * Attach a SyncRun + phase slug to this job so the middleware can mirror
     * its lifecycle onto the run when the worker executes it.
     */
    public function withSyncContext(SyncRun $run, string $phaseSlug): static
    {
        $this->syncRunId = $run->getKey();
        $this->syncPhaseSlug = $phaseSlug;

        return $this;
    }

    public function syncRunId(): ?int
    {
        return $this->syncRunId;
    }

    public function syncPhaseSlug(): ?string
    {
        return $this->syncPhaseSlug;
    }
}
