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

class MiddlewareTestJob implements TracksSyncRun
{
    use InteractsWithSyncRun;
}
