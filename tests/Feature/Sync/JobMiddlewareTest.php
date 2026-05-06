<?php

/**
 * RecordsSyncPhaseCompletion middleware tests for Step 4.
 *
 * Verifies the middleware:
 *   - Is a no-op for jobs that don't implement TracksSyncRun
 *   - Marks phase Running before the job, Completed on success
 *   - Marks phase Failed on exception and rethrows
 *   - Survives a missing SyncRun without exploding
 */

use App\Enums\SyncPhaseStatus;
use App\Enums\SyncRunStatus;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use App\Sync\Concerns\InteractsWithSyncRun;
use App\Sync\Contracts\TracksSyncRun;
use App\Sync\Middleware\RecordsSyncPhaseCompletion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    $this->run = SyncRun::openFor($this->playlist);
    $this->middleware = new RecordsSyncPhaseCompletion;
});

it('passes through jobs that do not implement TracksSyncRun', function () {
    $job = new class
    {
        public bool $ran = false;
    };

    $this->middleware->handle($job, function ($j) {
        $j->ran = true;
    });

    expect($job->ran)->toBeTrue();
    // Run state untouched.
    expect($this->run->fresh()->phases)->toBe([]);
});

it('marks phase running then completed on success', function () {
    $job = new MiddlewareTestJob;
    $job->withSyncContext($this->run, 'middleware_phase');

    $this->middleware->handle($job, function () {
        // simulate work
    });

    $fresh = $this->run->fresh();
    expect($fresh->phaseStatus('middleware_phase'))->toBe(SyncPhaseStatus::Completed);
    expect($fresh->phases['middleware_phase']['started_at'])->not->toBeNull();
    expect($fresh->phases['middleware_phase']['finished_at'])->not->toBeNull();
});

it('marks phase failed and rethrows when the job throws', function () {
    $job = new MiddlewareTestJob;
    $job->withSyncContext($this->run, 'middleware_phase');

    expect(fn () => $this->middleware->handle($job, function () {
        throw new RuntimeException('worker boom');
    }))->toThrow(RuntimeException::class, 'worker boom');

    $fresh = $this->run->fresh();
    expect($fresh->phaseStatus('middleware_phase'))->toBe(SyncPhaseStatus::Failed);
    expect($fresh->phases['middleware_phase']['error'])->toBe('worker boom');
    expect($fresh->errors)->toHaveCount(1);
    expect($fresh->errors[0]['phase'])->toBe('middleware_phase');
});

it('passes through when SyncRun id is missing', function () {
    $job = new MiddlewareTestJob; // no withSyncContext call
    $ran = false;

    $this->middleware->handle($job, function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue();
});

it('does not crash when the SyncRun has been deleted', function () {
    $job = new MiddlewareTestJob;
    $job->withSyncContext($this->run, 'middleware_phase');
    $this->run->delete();

    $ran = false;
    $this->middleware->handle($job, function () use (&$ran) {
        $ran = true;
    });

    expect($ran)->toBeTrue();
});

it('does not flip run status when closesRun is false (default)', function () {
    $job = new MiddlewareTestJob;
    $job->withSyncContext($this->run, 'middleware_phase');

    expect($this->run->fresh()->status)->toBe(SyncRunStatus::Pending);

    $this->middleware->handle($job, function () {
        // success
    });

    // Phase completed, but run remains Pending (orchestrator owns the run lifecycle).
    $fresh = $this->run->fresh();
    expect($fresh->status)->toBe(SyncRunStatus::Pending);
    expect($fresh->finished_at)->toBeNull();
    expect($fresh->phaseStatus('middleware_phase'))->toBe(SyncPhaseStatus::Completed);
});

it('flips run from Pending to Running then Completed when closesRun is true', function () {
    $job = new MiddlewareTestJob;
    $job->withSyncContext($this->run, 'middleware_phase', closesRun: true);

    $observedDuringWork = null;

    $this->middleware->handle($job, function () use (&$observedDuringWork) {
        $observedDuringWork = $this->run->fresh()->status;
    });

    expect($observedDuringWork)->toBe(SyncRunStatus::Running);

    $fresh = $this->run->fresh();
    expect($fresh->status)->toBe(SyncRunStatus::Completed);
    expect($fresh->finished_at)->not->toBeNull();
});

it('marks run Failed when closesRun is true and the job throws', function () {
    $job = new MiddlewareTestJob;
    $job->withSyncContext($this->run, 'middleware_phase', closesRun: true);

    expect(fn () => $this->middleware->handle($job, function () {
        throw new RuntimeException('worker boom');
    }))->toThrow(RuntimeException::class, 'worker boom');

    $fresh = $this->run->fresh();
    expect($fresh->status)->toBe(SyncRunStatus::Failed);
    expect($fresh->finished_at)->not->toBeNull();
    expect($fresh->phaseStatus('middleware_phase'))->toBe(SyncPhaseStatus::Failed);
});

it('does not overwrite a terminal run status when closesRun is true', function () {
    $job = new MiddlewareTestJob;
    $job->withSyncContext($this->run, 'middleware_phase', closesRun: true);

    // Pre-mark the run as Cancelled (e.g. pre-sync halt) before the job runs.
    $this->run->markCancelled('halted');

    $this->middleware->handle($job, function () {
        // success path
    });

    $fresh = $this->run->fresh();
    expect($fresh->status)->toBe(SyncRunStatus::Cancelled);
});

class MiddlewareTestJob implements TracksSyncRun
{
    use InteractsWithSyncRun;
}
