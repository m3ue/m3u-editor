<?php

/**
 * SeriesStrmSyncPhase + SeriesStrmPostProcessPhase tests for Step 6 of the
 * sync pipeline refactor.
 *
 * Mirrors {@see StrmPhasesTest} for the series side. Covers:
 *   - SeriesStrmSyncPhase::shouldRun gating
 *     (auto_sync_series_stream_files × series count).
 *   - SeriesStrmSyncPhase::chainJobs returns SyncSeriesStrmFiles with
 *     suppressPostProcessEvents:true so the chained
 *     SeriesStrmPostProcessPhase owns the event firing.
 *   - SeriesStrmPostProcessPhase::shouldRun checks for enabled
 *     `series_stream_files_synced` post-processes.
 *   - SeriesStrmPostProcessPhase::chainJobs returns FireStreamFilesSyncedEvent
 *     with the `series_stream_files_synced` event name.
 *   - SyncSeriesStrmFiles respects the suppressPostProcessEvents flag and
 *     does NOT dispatch FireStreamFilesSyncedEvent inline when suppressed.
 *   - Integration: PlaylistPostSyncPlan executed via orchestrator dispatches
 *     a single Bus::chain that includes BOTH the VOD STRM and Series STRM
 *     legs in the correct order when the playlist is configured for both.
 */

use App\Enums\SyncRunStatus;
use App\Jobs\FireStreamFilesSyncedEvent;
use App\Jobs\RunPlaylistSortAlpha;
use App\Jobs\SyncSeriesStrmFiles;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\PostProcess;
use App\Models\Series;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Sync\Phases\SeriesStrmPostProcessPhase;
use App\Sync\Phases\SeriesStrmSyncPhase;
use App\Sync\Phases\StrmPostProcessPhase;
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
// SeriesStrmSyncPhase::shouldRun
// -----------------------------------------------------------------------------

it('does not run series STRM sync phase when auto_sync_series_stream_files is false', function () {
    $this->playlist->update(['auto_sync_series_stream_files' => false]);
    Series::factory()->for($this->user)->for($this->playlist)->create();

    expect(app(SeriesStrmSyncPhase::class)->shouldRun($this->playlist->fresh()))->toBeFalse();
});

it('does not run series STRM sync phase when the playlist has no series', function () {
    $this->playlist->update(['auto_sync_series_stream_files' => true]);

    expect(app(SeriesStrmSyncPhase::class)->shouldRun($this->playlist->fresh()))->toBeFalse();
});

it('runs series STRM sync phase when auto_sync is on and at least one series exists', function () {
    $this->playlist->update(['auto_sync_series_stream_files' => true]);
    Series::factory()->for($this->user)->for($this->playlist)->create();

    expect(app(SeriesStrmSyncPhase::class)->shouldRun($this->playlist->fresh()))->toBeTrue();
});

// -----------------------------------------------------------------------------
// SeriesStrmSyncPhase::chainJobs
// -----------------------------------------------------------------------------

it('contributes a SyncSeriesStrmFiles job with suppressPostProcessEvents:true', function () {
    $jobs = app(SeriesStrmSyncPhase::class)->chainJobs($this->run, $this->playlist);

    expect($jobs)->toHaveCount(1);
    expect($jobs[0])->toBeInstanceOf(SyncSeriesStrmFiles::class);
    expect($jobs[0]->suppressPostProcessEvents)->toBeTrue();
    expect($jobs[0]->playlist_id)->toBe($this->playlist->id);
    expect($jobs[0]->user_id)->toBe($this->user->id);
});

// -----------------------------------------------------------------------------
// SeriesStrmPostProcessPhase::shouldRun
// -----------------------------------------------------------------------------

it('does not run series STRM post-process phase when no enabled post-processes exist for the event', function () {
    expect(app(SeriesStrmPostProcessPhase::class)->shouldRun($this->playlist))->toBeFalse();
});

it('does not run series STRM post-process phase when only disabled post-processes exist', function () {
    $pp = PostProcess::factory()->create([
        'user_id' => $this->user->id,
        'event' => SeriesStrmPostProcessPhase::EVENT,
        'enabled' => false,
    ]);
    $this->playlist->postProcesses()->attach($pp->id);

    expect(app(SeriesStrmPostProcessPhase::class)->shouldRun($this->playlist->fresh()))->toBeFalse();
});

it('runs series STRM post-process phase when at least one enabled post-process exists', function () {
    $pp = PostProcess::factory()->create([
        'user_id' => $this->user->id,
        'event' => SeriesStrmPostProcessPhase::EVENT,
        'enabled' => true,
    ]);
    $this->playlist->postProcesses()->attach($pp->id);

    expect(app(SeriesStrmPostProcessPhase::class)->shouldRun($this->playlist->fresh()))->toBeTrue();
});

it('only matches the series_stream_files_synced event (not the VOD event)', function () {
    $pp = PostProcess::factory()->create([
        'user_id' => $this->user->id,
        'event' => StrmPostProcessPhase::EVENT, // vod_stream_files_synced
        'enabled' => true,
    ]);
    $this->playlist->postProcesses()->attach($pp->id);

    expect(app(SeriesStrmPostProcessPhase::class)->shouldRun($this->playlist->fresh()))->toBeFalse();
});

// -----------------------------------------------------------------------------
// SeriesStrmPostProcessPhase::chainJobs
// -----------------------------------------------------------------------------

it('contributes a FireStreamFilesSyncedEvent job with the series_stream_files_synced event', function () {
    $jobs = app(SeriesStrmPostProcessPhase::class)->chainJobs($this->run, $this->playlist);

    expect($jobs)->toHaveCount(1);
    expect($jobs[0])->toBeInstanceOf(FireStreamFilesSyncedEvent::class);
    expect($jobs[0]->event)->toBe(SeriesStrmPostProcessPhase::EVENT);
    expect($jobs[0]->event)->toBe('series_stream_files_synced');
});

// -----------------------------------------------------------------------------
// SyncSeriesStrmFiles suppressPostProcessEvents flag
// -----------------------------------------------------------------------------

it('does not dispatch FireStreamFilesSyncedEvent when suppressPostProcessEvents is true', function () {
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->default_series_stream_file_setting_id = null;
    $mock->stream_file_sync_enabled = false;
    app()->instance(GeneralSettings::class, $mock);

    (new SyncSeriesStrmFiles(
        notify: false,
        playlist_id: $this->playlist->id,
        user_id: $this->user->id,
        isCleanupJob: true,
        suppressPostProcessEvents: true,
    ))->handle(app(GeneralSettings::class));

    Bus::assertNotDispatched(FireStreamFilesSyncedEvent::class);
});

it('preserves default behaviour and dispatches FireStreamFilesSyncedEvent when suppress flag is false (standalone path)', function () {
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->default_series_stream_file_setting_id = null;
    $mock->stream_file_sync_enabled = false;
    app()->instance(GeneralSettings::class, $mock);

    (new SyncSeriesStrmFiles(
        notify: false,
        playlist_id: $this->playlist->id,
        user_id: $this->user->id,
        isCleanupJob: true,
    ))->handle(app(GeneralSettings::class));

    Bus::assertDispatched(FireStreamFilesSyncedEvent::class, function (FireStreamFilesSyncedEvent $job): bool {
        return $job->playlist->id === $this->playlist->id
            && $job->event === 'series_stream_files_synced';
    });
});

// -----------------------------------------------------------------------------
// Integration with PlaylistPostSyncPlan — chain spans VOD + Series legs
// -----------------------------------------------------------------------------

it('orchestrated post-sync plan dispatches a single chain spanning F/R + VOD STRM + Series STRM and post-processes', function () {
    // Configure playlist so all 5 chain phases contribute.
    $this->playlist->update([
        'auto_sync_vod_stream_files' => true,
        'auto_sync_series_stream_files' => true,
        'sort_alpha_config' => [['enabled' => true, 'group' => null]],
    ]);
    Channel::factory()->for($this->user)->for($this->playlist)->create(['is_vod' => true]);
    Series::factory()->for($this->user)->for($this->playlist)->create();

    $vodPostProcess = PostProcess::factory()->create([
        'user_id' => $this->user->id,
        'event' => StrmPostProcessPhase::EVENT,
        'enabled' => true,
    ]);
    $seriesPostProcess = PostProcess::factory()->create([
        'user_id' => $this->user->id,
        'event' => SeriesStrmPostProcessPhase::EVENT,
        'enabled' => true,
    ]);
    $this->playlist->postProcesses()->attach([$vodPostProcess->id, $seriesPostProcess->id]);

    $run = SyncRun::openFor($this->playlist->fresh());

    app(SyncOrchestrator::class)->execute($run, PlaylistPostSyncPlan::build());

    Bus::assertChained([
        RunPlaylistSortAlpha::class,
        SyncVodStrmFiles::class,
        FireStreamFilesSyncedEvent::class,
        SyncSeriesStrmFiles::class,
        FireStreamFilesSyncedEvent::class,
    ]);

    expect($run->fresh()->status)->toBe(SyncRunStatus::Completed);
});
