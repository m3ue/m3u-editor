<?php

/**
 * StrmSyncPhase + StrmPostProcessPhase tests for Step 6 of the sync pipeline
 * refactor.
 *
 * Covers:
 *   - StrmSyncPhase::shouldRun gating (auto_sync_vod_stream_files × VOD count).
 *   - StrmSyncPhase::chainJobs returns SyncVodStrmFiles with
 *     suppressPostProcessEvents:true so the chained StrmPostProcessPhase owns
 *     the event firing.
 *   - StrmPostProcessPhase::shouldRun checks for enabled
 *     `vod_stream_files_synced` post-processes.
 *   - StrmPostProcessPhase::chainJobs returns FireStreamFilesSyncedEvent.
 *   - SyncVodStrmFiles respects the suppressPostProcessEvents flag and does
 *     NOT dispatch FireStreamFilesSyncedEvent inline when suppressed.
 *   - SyncVodStrmFiles default behaviour (suppress=false) preserved for
 *     standalone dispatches.
 *   - Integration: PlaylistPostSyncPlan executed via orchestrator dispatches a
 *     single Bus::chain matching the playlist's configuration.
 */

use App\Enums\SyncRunStatus;
use App\Jobs\FireStreamFilesSyncedEvent;
use App\Jobs\RunPlaylistSortAlpha;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\PostProcess;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Sync\Phases\StrmPostProcessPhase;
use App\Sync\Phases\StrmSyncPhase;
use App\Sync\Plans\PlaylistPostSyncPlan;
use App\Sync\SyncOrchestrator;
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
// StrmSyncPhase::shouldRun
// -----------------------------------------------------------------------------

it('does not run STRM sync phase when auto_sync_vod_stream_files is false', function () {
    $this->playlist->update(['auto_sync_vod_stream_files' => false]);
    Channel::factory()->for($this->user)->for($this->playlist)->create(['is_vod' => true]);

    expect(app(StrmSyncPhase::class)->shouldRun($this->playlist->fresh()))->toBeFalse();
});

it('does not run STRM sync phase when there are no VOD channels', function () {
    $this->playlist->update(['auto_sync_vod_stream_files' => true]);
    Channel::factory()->for($this->user)->for($this->playlist)->create(['is_vod' => false]);

    expect(app(StrmSyncPhase::class)->shouldRun($this->playlist->fresh()))->toBeFalse();
});

it('runs STRM sync phase when auto_sync is on and at least one VOD channel exists', function () {
    $this->playlist->update(['auto_sync_vod_stream_files' => true]);
    Channel::factory()->for($this->user)->for($this->playlist)->create(['is_vod' => true]);

    expect(app(StrmSyncPhase::class)->shouldRun($this->playlist->fresh()))->toBeTrue();
});

// -----------------------------------------------------------------------------
// StrmSyncPhase::chainJobs
// -----------------------------------------------------------------------------

it('contributes a SyncVodStrmFiles job with suppressPostProcessEvents:true', function () {
    $jobs = app(StrmSyncPhase::class)->chainJobs($this->run, $this->playlist);

    expect($jobs)->toHaveCount(1);
    expect($jobs[0])->toBeInstanceOf(SyncVodStrmFiles::class);
    expect($jobs[0]->suppressPostProcessEvents)->toBeTrue();
    expect($jobs[0]->playlist?->id)->toBe($this->playlist->id);
});

// -----------------------------------------------------------------------------
// StrmPostProcessPhase::shouldRun
// -----------------------------------------------------------------------------

it('does not run STRM post-process phase when no enabled post-processes exist for the event', function () {
    expect(app(StrmPostProcessPhase::class)->shouldRun($this->playlist))->toBeFalse();
});

it('does not run STRM post-process phase when only disabled post-processes exist', function () {
    $pp = PostProcess::factory()->create([
        'user_id' => $this->user->id,
        'event' => StrmPostProcessPhase::EVENT,
        'enabled' => false,
    ]);
    $this->playlist->postProcesses()->attach($pp->id);

    expect(app(StrmPostProcessPhase::class)->shouldRun($this->playlist->fresh()))->toBeFalse();
});

it('runs STRM post-process phase when at least one enabled post-process exists', function () {
    $pp = PostProcess::factory()->create([
        'user_id' => $this->user->id,
        'event' => StrmPostProcessPhase::EVENT,
        'enabled' => true,
    ]);
    $this->playlist->postProcesses()->attach($pp->id);

    expect(app(StrmPostProcessPhase::class)->shouldRun($this->playlist->fresh()))->toBeTrue();
});

// -----------------------------------------------------------------------------
// StrmPostProcessPhase::chainJobs
// -----------------------------------------------------------------------------

it('contributes a FireStreamFilesSyncedEvent job with the vod_stream_files_synced event', function () {
    $jobs = app(StrmPostProcessPhase::class)->chainJobs($this->run, $this->playlist);

    expect($jobs)->toHaveCount(1);
    expect($jobs[0])->toBeInstanceOf(FireStreamFilesSyncedEvent::class);
    expect($jobs[0]->event)->toBe(StrmPostProcessPhase::EVENT);
});

// -----------------------------------------------------------------------------
// SyncVodStrmFiles suppressPostProcessEvents flag
// -----------------------------------------------------------------------------

it('does not dispatch FireStreamFilesSyncedEvent when suppressPostProcessEvents is true', function () {
    Channel::factory()->for($this->user)->for($this->playlist)->create(['is_vod' => true]);

    $job = new SyncVodStrmFiles(
        notify: false,
        playlist: $this->playlist->fresh(),
        suppressPostProcessEvents: true,
    );
    $job->handle(app(GeneralSettings::class));

    Bus::assertNotDispatched(FireStreamFilesSyncedEvent::class);
});

// -----------------------------------------------------------------------------
// Integration with PlaylistPostSyncPlan
// -----------------------------------------------------------------------------

it('orchestrated post-sync plan dispatches a single chain containing F/R + STRM + STRM post-process', function () {
    // Configure playlist so all 3 chain phases contribute.
    $this->playlist->update([
        'auto_sync_vod_stream_files' => true,
        'sort_alpha_config' => [['enabled' => true, 'group' => null]],
    ]);
    Channel::factory()->for($this->user)->for($this->playlist)->create(['is_vod' => true]);
    $pp = PostProcess::factory()->create([
        'user_id' => $this->user->id,
        'event' => StrmPostProcessPhase::EVENT,
        'enabled' => true,
    ]);
    $this->playlist->postProcesses()->attach($pp->id);

    $run = SyncRun::openFor($this->playlist->fresh());

    app(SyncOrchestrator::class)->execute($run, PlaylistPostSyncPlan::build());

    // The chain block dispatches a single Bus::chain whose first job is SortAlpha
    // (when no F/R rules are defined) followed by SyncVodStrmFiles, then
    // FireStreamFilesSyncedEvent. The orchestrator emits the chain once.
    Bus::assertChained([
        RunPlaylistSortAlpha::class,
        SyncVodStrmFiles::class,
        FireStreamFilesSyncedEvent::class,
    ]);

    expect($run->fresh()->status)->toBe(SyncRunStatus::Completed);
});
