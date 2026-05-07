<?php

/**
 * Regression tests for ProcessM3uImportSeries status-overwrite bug.
 *
 * ProcessM3uImportSeries used to unconditionally write status=Processing before
 * dispatching episodes. This overwrote the Completed status set by
 * ProcessM3uImportComplete and caused SyncListener to build only the 1-phase
 * post-sync plan instead of the full 10-phase plan.
 */

use App\Enums\Status;
use App\Jobs\ProcessM3uImportSeries;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly([
        'status' => Status::Completed,
    ]);
});

it('does not overwrite playlist status to Processing when forced', function () {
    $job = new ProcessM3uImportSeries(
        playlist: $this->playlist,
        force: true,
    );

    $job->handle();

    expect($this->playlist->fresh()->status)
        ->toBe(Status::Completed, 'ProcessM3uImportSeries must not overwrite status=Completed with Processing');
});

it('does not overwrite playlist status to Processing on a normal (non-forced) run', function () {
    // auto_sync=true and synced=false means the early-return guard passes.
    $this->playlist->update(['auto_sync' => true, 'synced' => false]);

    $job = new ProcessM3uImportSeries(
        playlist: $this->playlist->fresh(),
        force: false,
    );

    $job->handle();

    expect($this->playlist->fresh()->status)
        ->toBe(Status::Completed, 'ProcessM3uImportSeries must not overwrite status=Completed with Processing');
});
