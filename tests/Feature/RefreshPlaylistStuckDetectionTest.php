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
    // Pin the threshold to 120 minutes so tests are independent of the env/config value.
    config(['dev.stuck_processing_minutes' => 120]);
});

it('resets a stuck playlist when updated_at is past the threshold', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'status' => Status::Processing->value,
        'auto_sync' => false,
        'is_network_playlist' => false,
    ]);

    DB::table('playlists')->where('id', $playlist->id)->update([
        'updated_at' => now()->subMinutes(200)->toDateTimeString(),
    ]);

    $this->artisan('app:refresh-playlist')->assertSuccessful();

    expect($playlist->fresh()->status)->toBe(Status::Pending);
});

it('does not reset a playlist when updated_at is within the threshold', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'status' => Status::Processing->value,
        'auto_sync' => false,
        'is_network_playlist' => false,
    ]);

    // updated_at defaults to now() in the factory, so it falls within the threshold.

    $this->artisan('app:refresh-playlist')->assertSuccessful();

    expect($playlist->fresh()->status)->toBe(Status::Processing);
});

it('fails Running SyncRuns when resetting a stuck playlist', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'status' => Status::Processing->value,
        'auto_sync' => false,
        'is_network_playlist' => false,
    ]);

    DB::table('playlists')->where('id', $playlist->id)->update([
        'updated_at' => now()->subMinutes(200)->toDateTimeString(),
    ]);

    $run = SyncRun::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'status' => SyncRunStatus::Running->value,
        'started_at' => now()->subMinutes(200),
        'current_phase' => 'import',
        'phases' => ['import'],
        'phase_statuses' => (object) [],
    ]);

    $this->artisan('app:refresh-playlist')->assertSuccessful();

    expect($run->fresh()->status)->toBe(SyncRunStatus::Failed->value);
    expect($playlist->fresh()->status)->toBe(Status::Pending);
});

it('does not reset a playlist that is recently active even when a SyncRun exists', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'status' => Status::Processing->value,
        'auto_sync' => false,
        'is_network_playlist' => false,
    ]);

    // updated_at is recent — the sync is actively progressing
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
