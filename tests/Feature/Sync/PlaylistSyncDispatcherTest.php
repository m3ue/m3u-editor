<?php

/**
 * Workstream-2 wiring: PlaylistSyncDispatcher attaches the sync-side SyncRun
 * to the dispatched ProcessM3uImport job so the
 * RecordsSyncPhaseCompletion middleware can mirror the m3u_import phase
 * lifecycle onto the run.
 */

use App\Enums\PlaylistSourceType;
use App\Enums\Status;
use App\Enums\SyncPhaseStatus;
use App\Enums\SyncRunStatus;
use App\Jobs\ProcessM3uImport;
use App\Jobs\SyncMediaServer;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use App\Sync\Concerns\InteractsWithSyncRun;
use App\Sync\Contracts\TracksSyncRun;
use App\Sync\Middleware\RecordsSyncPhaseCompletion;
use App\Sync\PlaylistSyncDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

it('opens a sync-kind SyncRun and dispatches ProcessM3uImport with sync context attached', function () {
    Bus::fake();

    $dispatcher = new PlaylistSyncDispatcher;
    $run = $dispatcher->dispatch(
        $this->playlist,
        PlaylistSyncDispatcher::TRIGGER_API_REFRESH,
        force: true,
        isNew: false,
    );

    expect($run)->toBeInstanceOf(SyncRun::class);
    expect($run->kind)->toBe('sync');
    expect($run->trigger)->toBe(PlaylistSyncDispatcher::TRIGGER_API_REFRESH);
    expect($run->status)->toBe(SyncRunStatus::Pending);
    expect($run->meta)->toMatchArray(['force' => true, 'is_new' => false]);

    Bus::assertDispatched(ProcessM3uImport::class, function (ProcessM3uImport $job) use ($run) {
        return $job->syncRunId() === $run->getKey()
            && $job->syncPhaseSlug() === PlaylistSyncDispatcher::PHASE_M3U_IMPORT
            && $job->playlist->is($this->playlist)
            && $job->force === true
            && $job->isNew === false;
    });
});

it('uses the m3u_import phase slug constant', function () {
    expect(PlaylistSyncDispatcher::PHASE_M3U_IMPORT)->toBe('m3u_import');
});

it('exposes ProcessM3uImport as a TracksSyncRun job with the lifecycle middleware', function () {
    $job = new ProcessM3uImport($this->playlist);

    expect($job)->toBeInstanceOf(TracksSyncRun::class);
    expect(class_uses_recursive($job))->toContain(InteractsWithSyncRun::class);
    expect($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(RecordsSyncPhaseCompletion::class);
});

it('records the m3u_import phase end-to-end through the middleware on a successful job run', function () {
    // Drive the middleware directly with a no-op closure to verify the wiring
    // contract without invoking the full ProcessM3uImport handle().
    $run = SyncRun::openFor(
        $this->playlist,
        kind: 'sync',
        trigger: PlaylistSyncDispatcher::TRIGGER_API_REFRESH,
    );

    $job = (new ProcessM3uImport($this->playlist))
        ->withSyncContext($run, PlaylistSyncDispatcher::PHASE_M3U_IMPORT);

    (new RecordsSyncPhaseCompletion)->handle($job, fn () => null);

    $fresh = $run->fresh();
    expect($fresh->phaseStatus(PlaylistSyncDispatcher::PHASE_M3U_IMPORT))
        ->toBe(SyncPhaseStatus::Completed);
    expect($fresh->phases[PlaylistSyncDispatcher::PHASE_M3U_IMPORT]['started_at'])
        ->not->toBeNull();
    expect($fresh->phases[PlaylistSyncDispatcher::PHASE_M3U_IMPORT]['finished_at'])
        ->not->toBeNull();
});

it('records the m3u_import phase as failed when the worker throws', function () {
    $run = SyncRun::openFor(
        $this->playlist,
        kind: 'sync',
        trigger: PlaylistSyncDispatcher::TRIGGER_API_REFRESH,
    );

    $job = (new ProcessM3uImport($this->playlist))
        ->withSyncContext($run, PlaylistSyncDispatcher::PHASE_M3U_IMPORT);

    expect(fn () => (new RecordsSyncPhaseCompletion)->handle($job, function () {
        throw new RuntimeException('worker boom');
    }))->toThrow(RuntimeException::class, 'worker boom');

    $fresh = $run->fresh();
    expect($fresh->phaseStatus(PlaylistSyncDispatcher::PHASE_M3U_IMPORT))
        ->toBe(SyncPhaseStatus::Failed);
    expect($fresh->phases[PlaylistSyncDispatcher::PHASE_M3U_IMPORT]['error'])
        ->toBe('worker boom');
});

// -----------------------------------------------------------------------------
// Workstream-3: pre-sync phase plan integration
// -----------------------------------------------------------------------------

it('runs the pre-sync plan and records each phase on the SyncRun', function () {
    Bus::fake();

    $run = (new PlaylistSyncDispatcher)->dispatch(
        $this->playlist->fresh(),
        PlaylistSyncDispatcher::TRIGGER_API_REFRESH,
    );

    $fresh = $run->fresh();
    expect($fresh->phaseStatus('network_guard'))->toBe(SyncPhaseStatus::Completed);
    expect($fresh->phaseStatus('media_server_redirect'))->toBe(SyncPhaseStatus::Completed);
    expect($fresh->phaseStatus('concurrency_guard'))->toBe(SyncPhaseStatus::Completed);
    expect($fresh->phaseStatus('initialize_sync_state'))->toBe(SyncPhaseStatus::Completed);

    Bus::assertDispatched(ProcessM3uImport::class);
});

it('skips ProcessM3uImport dispatch when network playlist guard halts', function () {
    Bus::fake();
    $this->playlist->update(['is_network_playlist' => true]);

    $run = (new PlaylistSyncDispatcher)->dispatch(
        $this->playlist->fresh(),
        PlaylistSyncDispatcher::TRIGGER_API_REFRESH,
    );

    Bus::assertNotDispatched(ProcessM3uImport::class);

    $fresh = $run->fresh();
    expect($fresh->phaseStatus('network_guard'))->toBe(SyncPhaseStatus::Completed);
    // Phases after the halt point are never invoked.
    expect($fresh->phaseStatus('initialize_sync_state'))->toBe(SyncPhaseStatus::Pending);
    // Halted run is closed out as Cancelled with the halt reason recorded.
    expect($fresh->status)->toBe(SyncRunStatus::Cancelled);
    expect($fresh->finished_at)->not->toBeNull();
    expect($fresh->errors)->toBeArray()->and($fresh->errors[0]['message'] ?? null)
        ->toBe('Pre-sync halted: network_playlist');

    expect($this->playlist->fresh()->status)->toBe(Status::Completed);
});

it('skips ProcessM3uImport dispatch and dispatches SyncMediaServer when integration exists', function () {
    Bus::fake();
    $this->playlist->update(['source_type' => PlaylistSourceType::Emby]);
    $integration = MediaServerIntegration::factory()
        ->for($this->user)
        ->create(['playlist_id' => $this->playlist->id, 'type' => 'emby']);

    (new PlaylistSyncDispatcher)->dispatch(
        $this->playlist->fresh(),
        PlaylistSyncDispatcher::TRIGGER_API_REFRESH,
    );

    Bus::assertNotDispatched(ProcessM3uImport::class);
    Bus::assertDispatched(SyncMediaServer::class, fn ($job) => $job->integrationId === $integration->id);
});

it('skips ProcessM3uImport dispatch when concurrency guard halts (already processing)', function () {
    Bus::fake();
    $this->playlist->update(['processing' => ['live_processing' => true]]);

    $run = (new PlaylistSyncDispatcher)->dispatch(
        $this->playlist->fresh(),
        PlaylistSyncDispatcher::TRIGGER_API_REFRESH,
        force: false,
    );

    Bus::assertNotDispatched(ProcessM3uImport::class);
    expect($run->fresh()->phaseStatus('initialize_sync_state'))->toBe(SyncPhaseStatus::Pending);
});

it('bypasses concurrency guard when dispatched with force=true', function () {
    Bus::fake();
    $this->playlist->update(['processing' => ['live_processing' => true]]);

    (new PlaylistSyncDispatcher)->dispatch(
        $this->playlist->fresh(),
        PlaylistSyncDispatcher::TRIGGER_API_REFRESH,
        force: true,
    );

    Bus::assertDispatched(ProcessM3uImport::class, fn ($job) => $job->force === true);
});
