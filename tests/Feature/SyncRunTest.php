<?php

/**
 * Covers the SyncRun ledger model added in Step 2 of the sync pipeline
 * refactor: open/start/complete/fail lifecycle, phase tracking, error
 * recording, the currentPhase accessor, uuid auto-generation, and the
 * Playlist <-> SyncRun relations.
 */

use App\Enums\SyncPhaseStatus;
use App\Enums\SyncRunStatus;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

it('opens a run for a playlist in the pending state', function () {
    $run = SyncRun::openFor($this->playlist, kind: 'full', trigger: 'scheduled', meta: ['source' => 'test']);

    expect($run->exists)->toBeTrue();
    expect($run->playlist_id)->toBe($this->playlist->getKey());
    expect($run->user_id)->toBe($this->user->getKey());
    expect($run->kind)->toBe('full');
    expect($run->trigger)->toBe('scheduled');
    expect($run->status)->toBe(SyncRunStatus::Pending);
    expect($run->meta)->toBe(['source' => 'test']);
    expect($run->phases)->toBe([]);
    expect($run->started_at)->toBeNull();
    expect($run->finished_at)->toBeNull();
});

it('auto-generates a uuid on creation', function () {
    $run = SyncRun::openFor($this->playlist);

    expect($run->uuid)->not->toBeEmpty();
    expect($run->uuid)->toMatch('/^[0-9a-f-]{36}$/i');
});

it('uses the provided uuid when one is given', function () {
    $uuid = '11111111-2222-3333-4444-555555555555';
    $run = SyncRun::factory()->forPlaylist($this->playlist)->create(['uuid' => $uuid]);

    expect($run->uuid)->toBe($uuid);
});

it('transitions through running -> completed', function () {
    $run = SyncRun::openFor($this->playlist);

    $run->markStarted();
    expect($run->fresh()->status)->toBe(SyncRunStatus::Running);
    expect($run->fresh()->started_at)->not->toBeNull();
    expect($run->fresh()->isRunning())->toBeTrue();
    expect($run->fresh()->isFinished())->toBeFalse();

    $startedAt = $run->fresh()->started_at;
    $run->markStarted(); // idempotent — should not reset started_at
    expect($run->fresh()->started_at->equalTo($startedAt))->toBeTrue();

    $run->markCompleted();
    expect($run->fresh()->status)->toBe(SyncRunStatus::Completed);
    expect($run->fresh()->finished_at)->not->toBeNull();
    expect($run->fresh()->isFinished())->toBeTrue();
});

it('records errors when marked failed', function () {
    $run = SyncRun::openFor($this->playlist)->markStarted();

    $run->markFailed(new RuntimeException('boom'));

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(SyncRunStatus::Failed);
    expect($fresh->finished_at)->not->toBeNull();
    expect($fresh->errors)->toHaveCount(1);
    expect($fresh->errors[0]['message'])->toBe('boom');
    expect($fresh->errors[0]['exception'])->toBe(RuntimeException::class);
    expect($fresh->isFinished())->toBeTrue();
});

it('marks the run cancelled with optional reason', function () {
    $run = SyncRun::openFor($this->playlist)->markStarted();

    $run->markCancelled('user requested');

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(SyncRunStatus::Cancelled);
    expect($fresh->errors)->toHaveCount(1);
    expect($fresh->errors[0]['message'])->toBe('user requested');
});

it('appends multiple recordError entries', function () {
    $run = SyncRun::openFor($this->playlist);

    $run->recordError('first', phase: 'discovery');
    $run->recordError('second', phase: 'tmdb_fetch');

    $errors = $run->fresh()->errors;
    expect($errors)->toHaveCount(2);
    expect($errors[0]['message'])->toBe('first');
    expect($errors[0]['phase'])->toBe('discovery');
    expect($errors[1]['phase'])->toBe('tmdb_fetch');
});

it('tracks phase lifecycle: started -> completed', function () {
    $run = SyncRun::openFor($this->playlist);

    $run->markPhaseStarted('discovery', meta: ['count' => 10]);
    $fresh = $run->fresh();
    expect($fresh->phaseStatus('discovery'))->toBe(SyncPhaseStatus::Running);
    expect($fresh->phases['discovery']['started_at'])->not->toBeNull();
    expect($fresh->phases['discovery']['meta'])->toBe(['count' => 10]);
    expect($fresh->current_phase)->toBe('discovery');

    $run->markPhaseCompleted('discovery');
    $fresh = $run->fresh();
    expect($fresh->phaseStatus('discovery'))->toBe(SyncPhaseStatus::Completed);
    expect($fresh->phases['discovery']['finished_at'])->not->toBeNull();
    // meta carried forward
    expect($fresh->phases['discovery']['meta'])->toBe(['count' => 10]);
    expect($fresh->current_phase)->toBeNull();
});

it('tracks phase lifecycle: started -> failed and mirrors error', function () {
    $run = SyncRun::openFor($this->playlist);

    $run->markPhaseStarted('tmdb_fetch');
    $run->markPhaseFailed('tmdb_fetch', new RuntimeException('rate limited'));

    $fresh = $run->fresh();
    expect($fresh->phaseStatus('tmdb_fetch'))->toBe(SyncPhaseStatus::Failed);
    expect($fresh->phases['tmdb_fetch']['error'])->toBe('rate limited');
    expect($fresh->errors)->toHaveCount(1);
    expect($fresh->errors[0]['phase'])->toBe('tmdb_fetch');
    expect($fresh->errors[0]['message'])->toBe('rate limited');
});

it('can skip a phase with a reason', function () {
    $run = SyncRun::openFor($this->playlist);

    $run->markPhaseSkipped('find_replace', reason: 'no rules configured');

    $fresh = $run->fresh();
    expect($fresh->phaseStatus('find_replace'))->toBe(SyncPhaseStatus::Skipped);
    expect($fresh->phases['find_replace']['meta'])->toBe(['reason' => 'no rules configured']);
});

it('returns Pending status for unknown phases', function () {
    $run = SyncRun::openFor($this->playlist);

    expect($run->phaseStatus('nonexistent'))->toBe(SyncPhaseStatus::Pending);
});

it('preserves started_at across repeated markPhaseStarted calls', function () {
    $run = SyncRun::openFor($this->playlist);

    $run->markPhaseStarted('discovery');
    $firstStartedAt = $run->fresh()->phases['discovery']['started_at'];

    $run->markPhaseStarted('discovery', meta: ['retry' => 1]);
    $fresh = $run->fresh();
    expect($fresh->phases['discovery']['started_at'])->toBe($firstStartedAt);
    expect($fresh->phases['discovery']['meta'])->toBe(['retry' => 1]);
});

it('exposes syncRuns and currentSyncRun relations on the playlist', function () {
    $older = SyncRun::factory()->forPlaylist($this->playlist)->completed()->create();
    // Force created_at separation so latestOfMany picks the right row.
    $older->forceFill(['created_at' => now()->subHour()])->save();
    $newer = SyncRun::factory()->forPlaylist($this->playlist)->running()->create();

    $playlist = $this->playlist->fresh(['syncRuns', 'currentSyncRun']);

    expect($playlist->syncRuns)->toHaveCount(2);
    expect($playlist->currentSyncRun->id)->toBe($newer->id);
});

it('detects terminal vs active enum states', function () {
    expect(SyncRunStatus::Pending->isActive())->toBeTrue();
    expect(SyncRunStatus::Running->isActive())->toBeTrue();
    expect(SyncRunStatus::Completed->isTerminal())->toBeTrue();
    expect(SyncRunStatus::Failed->isTerminal())->toBeTrue();
    expect(SyncRunStatus::Cancelled->isTerminal())->toBeTrue();
});
