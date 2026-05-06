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

    public bool $syncClosesRun = false;

    /**
     * Attach a SyncRun + phase slug to this job so the middleware can mirror
     * its lifecycle onto the run when the worker executes it.
     *
     * Pass `closesRun: true` when this job represents the entire run's work
     * — the middleware will then also flip the run's status from Pending to
     * Running on entry and to Completed/Failed on exit.
     */
    public function withSyncContext(SyncRun $run, string $phaseSlug, bool $closesRun = false): static
    {
        $this->syncRunId = $run->getKey();
        $this->syncPhaseSlug = $phaseSlug;
        $this->syncClosesRun = $closesRun;

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

    public function closesSyncRun(): bool
    {
        return $this->syncClosesRun;
    }
}
