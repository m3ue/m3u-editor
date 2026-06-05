<?php

use App\Enums\Status;
use App\Enums\SyncRunStatus;
use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Event::fake(); // prevents PlaylistListener from dispatching ProcessM3uImport during factory setup
    $this->user = User::factory()->create();
});

it('fails a Running SyncRun and dispatches a fresh import on reset (container-restart behaviour)', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'status' => Status::Processing,
        'auto_sync' => true,
    ]);

    $run = SyncRun::factory()->for($playlist)->create([
        'status' => SyncRunStatus::Running->value,
        'started_at' => now()->subMinutes(10),
        'finished_at' => null,
    ]);

    $this->artisan('app:reset-sync-process')->assertSuccessful();

    // Stale Running SyncRun must be failed so the fresh import starts clean.
    expect($run->fresh()->status)->toBe(SyncRunStatus::Failed->value);
    Queue::assertPushed(ProcessM3uImport::class);
});

it('dispatches a new import for a stuck playlist with no active SyncRun', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'status' => Status::Processing,
        'auto_sync' => true,
    ]);

    SyncRun::factory()->for($playlist)->create([
        'status' => SyncRunStatus::Failed->value,
        'started_at' => now()->subHours(5),
        'finished_at' => now()->subMinutes(30),
    ]);

    $this->artisan('app:reset-sync-process')->assertSuccessful();

    Queue::assertPushed(ProcessM3uImport::class);
});

it('skips playlists that are already completed', function () {
    Playlist::factory()->for($this->user)->create([
        'status' => Status::Completed,
        'auto_sync' => true,
    ]);

    $this->artisan('app:reset-sync-process')->assertSuccessful();

    Queue::assertNotPushed(ProcessM3uImport::class);
});

it('resets a stuck playlist with auto_sync disabled to Pending without dispatching an import', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'status' => Status::Processing,
        'auto_sync' => false,
    ]);

    $this->artisan('app:reset-sync-process')->assertSuccessful();

    Queue::assertNotPushed(ProcessM3uImport::class);
    expect($playlist->fresh()->status)->toBe(Status::Pending);
});
