<?php

/**
 * Workstream-3: pre-sync phases live under App\Sync\Phases\PreSync\* and run
 * synchronously inside PlaylistSyncDispatcher before the M3U import job is
 * dispatched. Each phase records its own status on the SyncRun and may
 * signal `halt` to suppress the import dispatch entirely.
 */

use App\Enums\PlaylistSourceType;
use App\Enums\Status;
use App\Enums\SyncPhaseStatus;
use App\Jobs\CreateBackup;
use App\Jobs\SyncMediaServer;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use App\Sync\Phases\PreSync\BackupPhase;
use App\Sync\Phases\PreSync\ConcurrencyGuardPhase;
use App\Sync\Phases\PreSync\InitializeSyncStatePhase;
use App\Sync\Phases\PreSync\MediaServerRedirectPhase;
use App\Sync\Phases\PreSync\NetworkPlaylistPhase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    $this->run = SyncRun::openFor($this->playlist);
});

// -----------------------------------------------------------------------------
// NetworkPlaylistPhase
// -----------------------------------------------------------------------------

it('halts and marks playlist completed when playlist is a network playlist', function () {
    $this->playlist->update(['is_network_playlist' => true, 'status' => Status::Pending]);

    $result = (new NetworkPlaylistPhase)->run($this->run, $this->playlist->fresh());

    expect($result)->toMatchArray([
        'halt' => true,
        'halt_reason' => 'network_playlist',
    ]);
    expect($this->playlist->fresh()->status)->toBe(Status::Completed);
    expect($this->run->fresh()->phaseStatus('network_guard'))->toBe(SyncPhaseStatus::Completed);
});

it('does not halt or mutate playlist when playlist is not a network playlist', function () {
    $this->playlist->update(['is_network_playlist' => false, 'status' => Status::Pending]);

    $result = (new NetworkPlaylistPhase)->run($this->run, $this->playlist->fresh());

    expect($result)->toBe([]);
    expect($this->playlist->fresh()->status)->toBe(Status::Pending);
    expect($this->run->fresh()->phaseStatus('network_guard'))->toBe(SyncPhaseStatus::Completed);
});

// -----------------------------------------------------------------------------
// MediaServerRedirectPhase
// -----------------------------------------------------------------------------

it('dispatches SyncMediaServer and halts when integration exists', function () {
    $this->playlist->update(['source_type' => PlaylistSourceType::Emby]);
    $integration = MediaServerIntegration::factory()
        ->for($this->user)
        ->create(['playlist_id' => $this->playlist->id, 'type' => 'emby']);

    $result = (new MediaServerRedirectPhase)->run($this->run, $this->playlist->fresh());

    expect($result)->toMatchArray([
        'halt' => true,
        'halt_reason' => 'media_server_redirected',
        'media_server_integration_id' => $integration->id,
    ]);
    Bus::assertDispatched(SyncMediaServer::class, fn ($job) => $job->integrationId === $integration->id);
    expect($this->run->fresh()->phaseStatus('media_server_redirect'))->toBe(SyncPhaseStatus::Completed);
});

it('halts with no_integration reason when media server playlist has no integration', function () {
    $this->playlist->update(['source_type' => PlaylistSourceType::Jellyfin]);

    $result = (new MediaServerRedirectPhase)->run($this->run, $this->playlist->fresh());

    expect($result)->toMatchArray([
        'halt' => true,
        'halt_reason' => 'media_server_no_integration',
    ]);
    Bus::assertNotDispatched(SyncMediaServer::class);
});

it('does not halt when playlist is not a media server playlist', function () {
    // Default factory source_type is not Emby/Jellyfin
    $result = (new MediaServerRedirectPhase)->run($this->run, $this->playlist);

    expect($result)->toBe([]);
    Bus::assertNotDispatched(SyncMediaServer::class);
});

// -----------------------------------------------------------------------------
// ConcurrencyGuardPhase
// -----------------------------------------------------------------------------

it('bypasses concurrency check when force is true', function () {
    $this->playlist->update(['processing' => ['live_processing' => true]]);

    $result = (new ConcurrencyGuardPhase)->run($this->run, $this->playlist->fresh(), ['force' => true]);

    expect($result)->toBe([]);
});

it('halts when playlist is currently processing', function () {
    $this->playlist->update(['processing' => ['live_processing' => true]]);

    $result = (new ConcurrencyGuardPhase)->run($this->run, $this->playlist->fresh(), ['force' => false]);

    expect($result)->toMatchArray([
        'halt' => true,
        'halt_reason' => 'already_processing',
    ]);
});

it('halts when auto_sync is disabled and playlist has been synced', function () {
    $this->playlist->update([
        'auto_sync' => false,
        'synced' => now(),
        'status' => Status::Completed,
    ]);

    $result = (new ConcurrencyGuardPhase)->run($this->run, $this->playlist->fresh(), ['force' => false]);

    expect($result)->toMatchArray([
        'halt' => true,
        'halt_reason' => 'auto_sync_disabled',
    ]);
});

it('does not halt when auto_sync is enabled', function () {
    $this->playlist->update([
        'auto_sync' => true,
        'synced' => now(),
        'status' => Status::Completed,
    ]);

    $result = (new ConcurrencyGuardPhase)->run($this->run, $this->playlist->fresh(), ['force' => false]);

    expect($result)->toBe([]);
});

it('does not halt when playlist has never been synced', function () {
    $this->playlist->update([
        'auto_sync' => false,
        'synced' => null,
        'status' => Status::Pending,
    ]);

    $result = (new ConcurrencyGuardPhase)->run($this->run, $this->playlist->fresh(), ['force' => false]);

    expect($result)->toBe([]);
});

// -----------------------------------------------------------------------------
// InitializeSyncStatePhase
// -----------------------------------------------------------------------------

it('stamps playlist into Processing with zeroed progress and reset processing flags', function () {
    $this->playlist->update([
        'status' => Status::Pending,
        'progress' => 50,
        'vod_progress' => 25,
        'series_progress' => 10,
        'errors' => 'previous error',
        'processing' => [
            'live_processing' => true,
            'vod_processing' => true,
            'series_processing' => true,
            'custom_flag' => 'preserved',
        ],
    ]);

    (new InitializeSyncStatePhase)->run($this->run, $this->playlist->fresh());

    $fresh = $this->playlist->fresh();
    expect($fresh->status)->toBe(Status::Processing);
    expect($fresh->progress)->toBe(0.0);
    expect($fresh->vod_progress)->toBe(0.0);
    expect($fresh->series_progress)->toBe(0.0);
    expect($fresh->errors)->toBeNull();
    expect($fresh->synced)->not->toBeNull();
    expect($fresh->processing)->toMatchArray([
        'live_processing' => false,
        'vod_processing' => false,
        'series_processing' => false,
        'custom_flag' => 'preserved',
    ]);
    expect($this->run->fresh()->phaseStatus('initialize_sync_state'))->toBe(SyncPhaseStatus::Completed);
});

// -----------------------------------------------------------------------------
// Slug constants — guard against accidental rename
// -----------------------------------------------------------------------------

it('exposes stable phase slugs', function () {
    expect(NetworkPlaylistPhase::slug())->toBe('network_guard');
    expect(MediaServerRedirectPhase::slug())->toBe('media_server_redirect');
    expect(ConcurrencyGuardPhase::slug())->toBe('concurrency_guard');
    expect(BackupPhase::slug())->toBe('backup');
    expect(InitializeSyncStatePhase::slug())->toBe('initialize_sync_state');
});

// -----------------------------------------------------------------------------
// BackupPhase
// -----------------------------------------------------------------------------

it('dispatches CreateBackup when backup_before_sync is enabled and playlist has been synced', function () {
    $this->playlist->update(['backup_before_sync' => true, 'synced' => now()]);

    (new BackupPhase)->run($this->run, $this->playlist->fresh());

    Bus::assertDispatched(CreateBackup::class, fn ($job) => $job->includeFiles === false);
    expect($this->run->fresh()->phaseStatus('backup'))->toBe(SyncPhaseStatus::Completed);
});

it('does not dispatch CreateBackup on first sync (synced is null)', function () {
    $this->playlist->update(['backup_before_sync' => true, 'synced' => null]);

    $phase = new BackupPhase;
    expect($phase->shouldRun($this->playlist->fresh()))->toBeFalse();
});

it('does not dispatch CreateBackup when backup_before_sync is disabled', function () {
    $this->playlist->update(['backup_before_sync' => false, 'synced' => now()]);

    $phase = new BackupPhase;
    expect($phase->shouldRun($this->playlist->fresh()))->toBeFalse();
});
