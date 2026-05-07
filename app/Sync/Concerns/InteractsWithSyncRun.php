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

    public bool $syncStartsRun = false;

    /**
     * Attach a SyncRun + phase slug to this job so the middleware can mirror
     * its lifecycle onto the run when the worker executes it.
     *
     * Pass `startsRun: true` when this job is the first queued step of the
     * run — the middleware will transition the run from Pending to Running on
     * entry (and to Failed on exception).
     *
     * Pass `closesRun: true` when this job also constitutes the *end* of the
     * run's work — the middleware will then also flip the run to
     * Completed/Failed on exit. When a job only dispatches downstream chains
     * (e.g. ProcessM3uImport for Xtream playlists), use `startsRun: true` +
     * `closesRun: false` so the run stays Running until the downstream work
     * signals completion (SyncCompleted event → SyncListener closes the run).
     */
    public function withSyncContext(SyncRun $run, string $phaseSlug, bool $closesRun = false, bool $startsRun = false): static
    {
        $this->syncRunId = $run->getKey();
        $this->syncPhaseSlug = $phaseSlug;
        $this->syncClosesRun = $closesRun;
        $this->syncStartsRun = $startsRun;

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

    public function startsSyncRun(): bool
    {
        return $this->syncStartsRun;
    }
}
