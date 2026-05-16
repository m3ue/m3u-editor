<?php

use App\Enums\SyncRunPhase;
use App\Enums\SyncRunStatus;
use App\Events\SyncCompleted;
use App\Jobs\FetchTmdbIds;
use App\Jobs\ProcessM3uImportSeries;
use App\Jobs\ProcessVodChannels;
use App\Jobs\SyncSeriesStrmFiles;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\SyncPipelineService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function mockPipelineSettings(bool $tmdb = false): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->tmdb_auto_lookup_on_import = $tmdb;

    app()->instance(GeneralSettings::class, $mock);
}

function makePlaylistWithVod(User $user, array $attrs = []): Playlist
{
    $playlist = Playlist::factory()->for($user)->create($attrs);
    Channel::factory()->for($playlist)->for($user)->create(['enabled' => true, 'is_vod' => true]);

    return $playlist;
}

function makePlaylistWithSeries(User $user, array $attrs = []): Playlist
{
    $playlist = Playlist::factory()->for($user)->create($attrs);
    Series::factory()->for($playlist)->for($user)->create(['enabled' => true]);

    return $playlist;
}

function makePlaylistWithBoth(User $user, array $attrs = []): Playlist
{
    $playlist = Playlist::factory()->for($user)->create($attrs);
    Channel::factory()->for($playlist)->for($user)->create(['enabled' => true, 'is_vod' => true]);
    Series::factory()->for($playlist)->for($user)->create(['enabled' => true]);

    return $playlist;
}

beforeEach(function () {
    Bus::fake();
    Event::fake();

    $this->user = User::factory()->create();
    $this->service = app(SyncPipelineService::class);
});

// ── buildPipeline: phase resolution ─────────────────────────────────────────

it('builds empty pipeline (only SyncCompleted) when playlist has no vod or series', function () {
    mockPipelineSettings();
    $playlist = Playlist::factory()->for($this->user)->create();

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)->toBe([SyncRunPhase::SyncCompleted->value])
        ->and($run->status)->toBe(SyncRunStatus::Pending->value);
});

it('includes VodMetadata when auto_fetch_vod_metadata is enabled', function () {
    mockPipelineSettings();
    $playlist = makePlaylistWithVod($this->user, ['auto_fetch_vod_metadata' => true]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)->toContain(SyncRunPhase::VodMetadata->value);
});

it('excludes VodMetadata when auto_fetch_vod_metadata is disabled', function () {
    mockPipelineSettings();
    $playlist = makePlaylistWithVod($this->user, ['auto_fetch_vod_metadata' => false]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)->not->toContain(SyncRunPhase::VodMetadata->value);
});

it('includes VodTmdb when global tmdb_auto_lookup_on_import is enabled and playlist has vod', function () {
    mockPipelineSettings(tmdb: true);
    $playlist = makePlaylistWithVod($this->user);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)->toContain(SyncRunPhase::VodTmdb->value);
});

it('excludes VodStrm and includes VodStrmPostProbe when probe and strm are both enabled', function () {
    mockPipelineSettings();
    $playlist = makePlaylistWithVod($this->user, [
        'auto_sync_vod_stream_files' => true,
        'auto_probe_vod_streams' => true,
    ]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)
        ->not->toContain(SyncRunPhase::VodStrm->value)
        ->toContain(SyncRunPhase::VodProbe->value)
        ->toContain(SyncRunPhase::VodStrmPostProbe->value);
});

it('excludes VodStrmPostProbe when strm is disabled even if probe is enabled', function () {
    mockPipelineSettings();
    $playlist = makePlaylistWithVod($this->user, [
        'auto_sync_vod_stream_files' => false,
        'auto_probe_vod_streams' => true,
    ]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)
        ->toContain(SyncRunPhase::VodProbe->value)
        ->not->toContain(SyncRunPhase::VodStrmPostProbe->value);
});

it('includes SeriesMetadata when auto_fetch_series_metadata is enabled', function () {
    mockPipelineSettings();
    $playlist = makePlaylistWithSeries($this->user, ['auto_fetch_series_metadata' => true]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)->toContain(SyncRunPhase::SeriesMetadata->value);
});

it('excludes SeriesStrm and includes SeriesStrmPostProbe when series probe and series strm are both enabled', function () {
    mockPipelineSettings();
    $playlist = makePlaylistWithSeries($this->user, [
        'auto_sync_series_stream_files' => true,
        'auto_probe_vod_streams' => true,
    ]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)
        ->not->toContain(SyncRunPhase::SeriesStrm->value)
        ->toContain(SyncRunPhase::SeriesProbe->value)
        ->toContain(SyncRunPhase::SeriesStrmPostProbe->value);
});

it('always ends with SyncCompleted', function () {
    mockPipelineSettings(tmdb: true);
    $playlist = makePlaylistWithBoth($this->user, [
        'auto_fetch_vod_metadata' => true,
        'auto_sync_vod_stream_files' => true,
        'auto_fetch_series_metadata' => true,
        'auto_sync_series_stream_files' => true,
    ]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect(last($run->phases))->toBe(SyncRunPhase::SyncCompleted->value);
});

it('builds a full pipeline with all phases for vod-and-series playlist', function () {
    mockPipelineSettings(tmdb: true);
    $playlist = makePlaylistWithBoth($this->user, [
        'auto_fetch_vod_metadata' => true,
        'auto_sync_vod_stream_files' => true,
        'auto_probe_vod_streams' => true,
        'auto_fetch_series_metadata' => true,
        'auto_sync_series_stream_files' => true,
    ]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)->toBe([
        SyncRunPhase::VodMetadata->value,
        SyncRunPhase::VodTmdb->value,
        SyncRunPhase::VodProbe->value,
        SyncRunPhase::VodStrmPostProbe->value,
        SyncRunPhase::SeriesMetadata->value,
        SyncRunPhase::SeriesTmdb->value,
        SyncRunPhase::SeriesProbe->value,
        SyncRunPhase::SeriesStrmPostProbe->value,
        SyncRunPhase::SyncCompleted->value,
    ]);
});

// ── startRun: dispatches first phase ────────────────────────────────────────

it('startRun dispatches ProcessVodChannels when first phase is VodMetadata', function () {
    mockPipelineSettings();
    $playlist = makePlaylistWithVod($this->user, ['auto_fetch_vod_metadata' => true]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));
    $this->service->startRun($run);

    Bus::assertDispatched(ProcessVodChannels::class, fn ($job) => $job->syncRunId === $run->id);
    expect($run->fresh()->status)->toBe(SyncRunStatus::Running->value);
});

it('startRun dispatches ProcessM3uImportSeries when first phase is SeriesMetadata', function () {
    mockPipelineSettings();
    $playlist = makePlaylistWithSeries($this->user, ['auto_fetch_series_metadata' => true]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));
    $this->service->startRun($run);

    Bus::assertDispatched(ProcessM3uImportSeries::class, fn ($job) => $job->syncRunId === $run->id);
});

it('startRun finishes immediately when pipeline has only SyncCompleted', function () {
    mockPipelineSettings();
    $playlist = Playlist::factory()->for($this->user)->create();

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));
    $this->service->startRun($run);

    expect($run->fresh()->status)->toBe(SyncRunStatus::Completed->value);
    Event::assertDispatched(SyncCompleted::class);
});

// ── completePhase: chaining ──────────────────────────────────────────────────

it('completePhase dispatches SyncVodStrmFiles after VodMetadata completes', function () {
    mockPipelineSettings();
    $playlist = makePlaylistWithVod($this->user, [
        'auto_fetch_vod_metadata' => true,
        'auto_sync_vod_stream_files' => true,
    ]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));
    $run->update(['status' => SyncRunStatus::Running->value]);

    $this->service->completePhase($run->id, SyncRunPhase::VodMetadata);

    Bus::assertDispatched(SyncVodStrmFiles::class, fn ($job) => $job->syncRunId === $run->id
        && $job->completionPhase === SyncRunPhase::VodStrm);
});

it('completePhase dispatches FetchTmdbIds for VodTmdb phase on startRun', function () {
    mockPipelineSettings(tmdb: true);
    $playlist = makePlaylistWithVod($this->user);

    $run = SyncRun::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'trigger' => 'test',
        'status' => SyncRunStatus::Running->value,
        'phases' => [SyncRunPhase::VodTmdb->value, SyncRunPhase::SyncCompleted->value],
        'phase_statuses' => (object) [],
        'context' => ['playlist_id' => $playlist->id],
        'started_at' => now(),
    ]);

    $this->service->startRun($run);

    Bus::assertDispatched(FetchTmdbIds::class, fn ($job) => $job->syncRunId === $run->id
        && $job->completionPhase === SyncRunPhase::VodTmdb);
});

it('completePhase dispatches SyncSeriesStrmFiles for SeriesStrm phase', function () {
    mockPipelineSettings();
    $playlist = makePlaylistWithSeries($this->user);

    $run = SyncRun::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'trigger' => 'test',
        'status' => SyncRunStatus::Running->value,
        'phases' => [SyncRunPhase::SeriesStrm->value, SyncRunPhase::SyncCompleted->value],
        'phase_statuses' => (object) [],
        'context' => ['playlist_id' => $playlist->id],
        'started_at' => now(),
    ]);

    $this->service->startRun($run);

    Bus::assertDispatched(SyncSeriesStrmFiles::class, fn ($job) => $job->syncRunId === $run->id
        && $job->completionPhase === SyncRunPhase::SeriesStrm);
});

it('completePhase finishes the run and fires SyncCompleted when all phases done', function () {
    mockPipelineSettings();
    $playlist = makePlaylistWithVod($this->user, ['auto_fetch_vod_metadata' => true]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));
    $run->update(['status' => SyncRunStatus::Running->value]);
    $run->markPhase(SyncRunPhase::VodMetadata, 'completed');

    $this->service->completePhase($run->id, SyncRunPhase::SyncCompleted);

    expect($run->fresh()->status)->toBe(SyncRunStatus::Completed->value)
        ->and($run->fresh()->finished_at)->not->toBeNull();
    Event::assertDispatched(SyncCompleted::class);
});

it('completePhase is idempotent — ignores already-completed run', function () {
    mockPipelineSettings();
    $playlist = makePlaylistWithVod($this->user);

    $run = SyncRun::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'trigger' => 'test',
        'status' => SyncRunStatus::Completed->value,
        'phases' => [SyncRunPhase::SyncCompleted->value],
        'phase_statuses' => ['sync_completed' => 'completed'],
        'context' => ['playlist_id' => $playlist->id],
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $this->service->completePhase($run->id, SyncRunPhase::SyncCompleted);

    Bus::assertNothingDispatched();
    Event::assertNotDispatched(SyncCompleted::class);
});

// ── buildStandalonePipeline ──────────────────────────────────────────────────

it('buildStandalonePipeline creates a run with the requested phases plus SyncCompleted', function () {
    $playlist = Playlist::factory()->for($this->user)->create();

    $run = $this->service->buildStandalonePipeline(
        $playlist,
        [SyncRunPhase::VodStrm],
        'manual_strm_sync'
    );

    expect($run->trigger)->toBe('manual_strm_sync')
        ->and($run->phases)->toBe([
            SyncRunPhase::VodStrm->value,
            SyncRunPhase::SyncCompleted->value,
        ]);
});

// ── SyncRun model helpers ────────────────────────────────────────────────────

it('getPhaseTimeline returns label and status for each phase', function () {
    $playlist = Playlist::factory()->for($this->user)->create();

    $run = SyncRun::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'trigger' => 'test',
        'status' => SyncRunStatus::Running->value,
        'phases' => [SyncRunPhase::VodMetadata->value, SyncRunPhase::SyncCompleted->value],
        'phase_statuses' => [SyncRunPhase::VodMetadata->value => 'completed'],
        'context' => [],
        'started_at' => now(),
    ]);

    $timeline = $run->phase_timeline;

    expect($timeline)->toHaveCount(2)
        ->and($timeline[0]['label'])->toBe('VOD Metadata')
        ->and($timeline[0]['status'])->toBe('completed')
        ->and($timeline[1]['status'])->toBe('pending');
});
