<?php

use App\Enums\Status;
use App\Enums\SyncRunStatus;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
});

it('resets a stuck playlist whose SyncRun started before the threshold', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'status' => Status::Processing->value,
        'auto_sync' => false,
        'is_network_playlist' => false,
    ]);

    SyncRun::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'status' => SyncRunStatus::Running->value,
        'started_at' => now()->subMinutes(200),
        'phases' => ['import'],
        'phase_statuses' => (object) [],
    ]);

    $this->artisan('app:refresh-playlist')->assertSuccessful();

    expect($playlist->fresh()->status)->toBe(Status::Pending);
});

it('does not reset a playlist whose SyncRun started within the threshold', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'status' => Status::Processing->value,
        'auto_sync' => false,
        'is_network_playlist' => false,
    ]);

    SyncRun::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'status' => SyncRunStatus::Running->value,
        'started_at' => now()->subMinutes(30),
        'phases' => ['import'],
        'phase_statuses' => (object) [],
    ]);

    $this->artisan('app:refresh-playlist')->assertSuccessful();

    expect($playlist->fresh()->status)->toBe(Status::Processing);
});

it('does not reset a playlist with an active SyncRun even when updated_at is stale', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'status' => Status::Processing->value,
        'auto_sync' => false,
        'is_network_playlist' => false,
    ]);

    // Bypass Eloquent's automatic timestamp handling to set a stale updated_at
    DB::table('playlists')->where('id', $playlist->id)->update([
        'updated_at' => now()->subMinutes(200)->toDateTimeString(),
    ]);

    // Active SyncRun started recently — should protect the playlist from reset
    SyncRun::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'status' => SyncRunStatus::Running->value,
        'started_at' => now()->subMinutes(30),
        'phases' => ['import'],
        'phase_statuses' => (object) [],
    ]);

    $this->artisan('app:refresh-playlist')->assertSuccessful();

    expect($playlist->fresh()->status)->toBe(Status::Processing);
});

it('marks stuck SyncRuns as failed', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'status' => Status::Processing->value,
        'auto_sync' => false,
    ]);

    $run = SyncRun::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'status' => SyncRunStatus::Running->value,
        'started_at' => now()->subMinutes(200),
        'phases' => ['import'],
        'phase_statuses' => (object) [],
    ]);

    $this->artisan('app:refresh-playlist')->assertSuccessful();

    expect($run->fresh()->status)->toBe(SyncRunStatus::Failed->value);
});
