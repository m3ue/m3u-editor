<?php

use App\Enums\Status;
use App\Enums\SyncRunPhase;
use App\Enums\SyncRunStatus;
use App\Events\SyncCompleted;
use App\Jobs\FetchTmdbIds;
use App\Jobs\MergeChannels;
use App\Jobs\ProbeChannelStreams;
use App\Jobs\ProbeStreams;
use App\Jobs\ProcessM3uImport;
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

function mockPipelineSettings(bool $tmdb = false, string $lookupScope = 'enabled'): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->tmdb_auto_lookup_on_import = $tmdb;
    $mock->tmdb_auto_lookup_all_new = $lookupScope;

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

    // Metadata + probe run first; TMDB runs after (no FindReplace in this playlist,
    // so TMDB immediately follows probe); STRM phases close out.
    expect($run->phases)->toBe([
        SyncRunPhase::VodMetadata->value,
        SyncRunPhase::VodProbe->value,
        SyncRunPhase::SeriesMetadata->value,
        SyncRunPhase::SeriesProbe->value,
        SyncRunPhase::VodTmdb->value,
        SyncRunPhase::SeriesTmdb->value,
        SyncRunPhase::VodStrmPostProbe->value,
        SyncRunPhase::SeriesStrmPostProbe->value,
        SyncRunPhase::SyncCompleted->value,
    ]);
});

it('places TMDB phases after FindReplace so find-replace title cleaning applies to TMDB search', function () {
    mockPipelineSettings(tmdb: true);
    $playlist = makePlaylistWithBoth($this->user, [
        'auto_fetch_series_metadata' => true,
        'auto_sync_series_stream_files' => true,
        'find_replace_rules' => [['enabled' => true, 'find_replace' => '|FR ', 'replace_with' => '']],
    ]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    $findReplacePos = array_search(SyncRunPhase::FindReplace->value, $run->phases);
    $seriesTmdbPos = array_search(SyncRunPhase::SeriesTmdb->value, $run->phases);

    expect($findReplacePos)->not->toBeFalse()
        ->and($seriesTmdbPos)->not->toBeFalse()
        ->and($findReplacePos)->toBeLessThan($seriesTmdbPos);
});

it('places FindReplace before STRM phases so filenames embed corrected titles', function () {
    mockPipelineSettings();
    $playlist = makePlaylistWithVod($this->user, [
        'auto_fetch_vod_metadata' => true,
        'auto_sync_vod_stream_files' => true,
        'find_replace_rules' => [['enabled' => true, 'search' => 'HD', 'replace' => '']],
    ]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    $findReplacePos = array_search(SyncRunPhase::FindReplace->value, $run->phases);
    $strmPos = array_search(SyncRunPhase::VodStrm->value, $run->phases);

    expect($findReplacePos)->toBeLessThan($strmPos);
});

it('places FindReplace before SeriesStrm so series filenames embed corrected titles', function () {
    // Regression: series chunks must run earlier in the chain so series rows exist
    // before FindReplace runs and rewrites their titles, ensuring SeriesStrm picks
    // up the corrected names. See: ProcessM3uImport chain reorder.
    mockPipelineSettings();
    $playlist = makePlaylistWithBoth($this->user, [
        'auto_fetch_vod_metadata' => true,
        'auto_sync_series_stream_files' => true,
        'find_replace_rules' => [['enabled' => true, 'search' => 'HD', 'replace' => '']],
    ]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    $findReplacePos = array_search(SyncRunPhase::FindReplace->value, $run->phases);
    $seriesStrmPos = array_search(SyncRunPhase::SeriesStrm->value, $run->phases);

    expect($findReplacePos)->not->toBeFalse()
        ->and($seriesStrmPos)->not->toBeFalse()
        ->and($findReplacePos)->toBeLessThan($seriesStrmPos);
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

// ── Upfront Import phase (modern kickoff flow) ───────────────────────────────

it('startImport creates a SyncRun immediately with only the Import phase', function () {
    $playlist = Playlist::factory()->for($this->user)->create();

    $run = $this->service->startImport($playlist, trigger: 'unit_test');

    expect($run->status)->toBe(SyncRunStatus::Running->value)
        ->and($run->trigger)->toBe('unit_test')
        ->and($run->phases)->toBe([SyncRunPhase::Import->value])
        ->and($run->current_phase)->toBe(SyncRunPhase::Import->value)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->context['playlist_id'])->toBe($playlist->id);
});

it('expandPipelineAfterImport replaces phases with the resolved post-import plan', function () {
    mockPipelineSettings();
    $playlist = makePlaylistWithVod($this->user, [
        'auto_fetch_vod_metadata' => true,
        'auto_sync_vod_stream_files' => true,
    ]);

    $run = $this->service->startImport($playlist);
    $this->service->expandPipelineAfterImport($run, $playlist, app(GeneralSettings::class));

    $run->refresh();
    $phases = $run->phases;

    expect($phases[0])->toBe(SyncRunPhase::Import->value)
        ->and($phases)->toContain(SyncRunPhase::VodMetadata->value)
        ->and($phases)->toContain(SyncRunPhase::VodStrm->value)
        ->and(end($phases))->toBe(SyncRunPhase::SyncCompleted->value);
});

it('completing the Import phase dispatches the next resolved phase', function () {
    mockPipelineSettings();
    $playlist = makePlaylistWithVod($this->user, ['auto_fetch_vod_metadata' => true]);

    $run = $this->service->startImport($playlist);
    $this->service->expandPipelineAfterImport($run, $playlist, app(GeneralSettings::class));

    $this->service->completePhase($run->id, SyncRunPhase::Import);

    Bus::assertDispatched(ProcessVodChannels::class);
    expect($run->fresh()->isPhaseComplete(SyncRunPhase::Import))->toBeTrue()
        ->and($run->fresh()->current_phase)->toBe(SyncRunPhase::VodMetadata->value);
});

it('expanded pipeline finishes cleanly when only Import + SyncCompleted remain', function () {
    mockPipelineSettings();
    // Playlist with no post-processing configured at all
    $playlist = Playlist::factory()->for($this->user)->create();

    $run = $this->service->startImport($playlist);
    $this->service->expandPipelineAfterImport($run, $playlist, app(GeneralSettings::class));

    expect($run->fresh()->phases)->toBe([
        SyncRunPhase::Import->value,
        SyncRunPhase::SyncCompleted->value,
    ]);

    $this->service->completePhase($run->id, SyncRunPhase::Import);

    expect($run->fresh()->status)->toBe(SyncRunStatus::Completed->value);
    Event::assertDispatched(SyncCompleted::class, fn ($event) => $event->syncRunId === $run->id);
});

it('Import phase is a no-op in dispatchPhase (driven externally)', function () {
    $playlist = Playlist::factory()->for($this->user)->create();
    $run = $this->service->startImport($playlist);

    $this->service->dispatchPhase($run, SyncRunPhase::Import);

    Bus::assertNothingDispatched();
});

// ── ChannelMerge & LiveProbe phases ──────────────────────────────────────────

it('includes ChannelMerge when auto_merge_channels_enabled is true', function () {
    mockPipelineSettings();
    $playlist = Playlist::factory()->for($this->user)->create([
        'auto_merge_channels_enabled' => true,
    ]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)->toContain(SyncRunPhase::ChannelMerge->value);
});

it('excludes ChannelMerge when auto_merge_channels_enabled is false', function () {
    mockPipelineSettings();
    $playlist = Playlist::factory()->for($this->user)->create([
        'auto_merge_channels_enabled' => false,
    ]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)->not->toContain(SyncRunPhase::ChannelMerge->value);
});

it('includes LiveProbe when auto_probe_streams is true', function () {
    mockPipelineSettings();
    $playlist = Playlist::factory()->for($this->user)->create([
        'auto_probe_streams' => true,
    ]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)->toContain(SyncRunPhase::LiveProbe->value);
});

it('excludes LiveProbe when auto_probe_streams is false', function () {
    mockPipelineSettings();
    $playlist = Playlist::factory()->for($this->user)->create([
        'auto_probe_streams' => false,
    ]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)->not->toContain(SyncRunPhase::LiveProbe->value);
});

it('orders ChannelMerge before LiveProbe and both before VOD/Series STRM phases', function () {
    mockPipelineSettings();
    $playlist = makePlaylistWithVod($this->user, [
        'auto_merge_channels_enabled' => true,
        'auto_probe_streams' => true,
        'auto_sync_vod_stream_files' => true,
    ]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    $mergePos = array_search(SyncRunPhase::ChannelMerge->value, $run->phases);
    $probePos = array_search(SyncRunPhase::LiveProbe->value, $run->phases);
    $strmPos = array_search(SyncRunPhase::VodStrm->value, $run->phases);

    expect($mergePos)->not->toBeFalse()
        ->and($probePos)->not->toBeFalse()
        ->and($strmPos)->not->toBeFalse()
        ->and($mergePos)->toBeLessThan($probePos)
        ->and($probePos)->toBeLessThan($strmPos);
});

it('dispatchChannelMerge dispatches MergeChannels job when merge config produces a job', function () {
    mockPipelineSettings();
    $playlist = Playlist::factory()->for($this->user)->create([
        'auto_merge_channels_enabled' => true,
    ]);

    $run = SyncRun::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'trigger' => 'test',
        'status' => SyncRunStatus::Running->value,
        'phases' => [SyncRunPhase::ChannelMerge->value, SyncRunPhase::SyncCompleted->value],
        'phase_statuses' => (object) [],
        'context' => ['playlist_id' => $playlist->id],
        'started_at' => now(),
    ]);

    $this->service->startRun($run);

    Bus::assertDispatched(MergeChannels::class);
});

it('dispatchLiveProbe dispatches ProbeChannelStreams with syncRunId', function () {
    mockPipelineSettings();
    $playlist = Playlist::factory()->for($this->user)->create([
        'auto_probe_streams' => true,
    ]);

    $run = SyncRun::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'trigger' => 'test',
        'status' => SyncRunStatus::Running->value,
        'phases' => [SyncRunPhase::LiveProbe->value, SyncRunPhase::SyncCompleted->value],
        'phase_statuses' => (object) [],
        'context' => ['playlist_id' => $playlist->id],
        'started_at' => now(),
    ]);

    $this->service->startRun($run);

    Bus::assertDispatched(
        ProbeChannelStreams::class,
        fn ($job) => $job->syncRunId === $run->id && $job->playlistId === $playlist->id,
    );
});

it('dispatchLiveProbe short-circuits to completePhase if auto_probe_streams was toggled off', function () {
    mockPipelineSettings();
    // Build the run with LiveProbe in phases, then flip the setting before dispatch.
    $playlist = Playlist::factory()->for($this->user)->create([
        'auto_probe_streams' => true,
    ]);

    $run = SyncRun::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'trigger' => 'test',
        'status' => SyncRunStatus::Running->value,
        'phases' => [SyncRunPhase::LiveProbe->value, SyncRunPhase::SyncCompleted->value],
        'phase_statuses' => (object) [],
        'context' => ['playlist_id' => $playlist->id],
        'started_at' => now(),
    ]);

    // Toggle off after the phase was scheduled.
    $playlist->update(['auto_probe_streams' => false]);

    $this->service->startRun($run);

    Bus::assertNotDispatched(ProbeChannelStreams::class);
    expect($run->fresh()->isPhaseComplete(SyncRunPhase::LiveProbe))->toBeTrue();
});

it('includes VodTmdb for new disabled channels when tmdb_auto_lookup_all_new is new', function () {
    mockPipelineSettings(tmdb: true, lookupScope: 'new');

    $playlist = Playlist::factory()->for($this->user)->create();
    Channel::factory()->for($playlist)->for($this->user)->create(['enabled' => false, 'is_vod' => true, 'new' => true]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)->toContain(SyncRunPhase::VodTmdb->value);
});

it('excludes VodTmdb for new disabled channels when tmdb_auto_lookup_all_new is enabled', function () {
    mockPipelineSettings(tmdb: true, lookupScope: 'enabled');

    $playlist = Playlist::factory()->for($this->user)->create();
    Channel::factory()->for($playlist)->for($this->user)->create(['enabled' => false, 'is_vod' => true, 'new' => true]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)->not->toContain(SyncRunPhase::VodTmdb->value);
});

it('includes SeriesTmdb for new disabled series when tmdb_auto_lookup_all_new is new', function () {
    mockPipelineSettings(tmdb: true, lookupScope: 'new');

    $playlist = Playlist::factory()->for($this->user)->create();
    Series::factory()->for($playlist)->for($this->user)->create(['enabled' => false, 'new' => true]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)->toContain(SyncRunPhase::SeriesTmdb->value);
});

it('dispatches FetchTmdbIds with lookupScope new when tmdb_auto_lookup_all_new is new', function () {
    mockPipelineSettings(tmdb: true, lookupScope: 'new');
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
        && $job->completionPhase === SyncRunPhase::VodTmdb
        && $job->lookupScope === 'new');
});

it('dispatches FetchTmdbIds with lookupScope enabled when tmdb_auto_lookup_all_new is enabled', function () {
    mockPipelineSettings(tmdb: true, lookupScope: 'enabled');
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
        && $job->completionPhase === SyncRunPhase::VodTmdb
        && $job->lookupScope === 'enabled');
});

it('includes VodTmdb for new disabled channels when tmdb_auto_lookup_all_new is both', function () {
    mockPipelineSettings(tmdb: true, lookupScope: 'both');

    $playlist = Playlist::factory()->for($this->user)->create();
    Channel::factory()->for($playlist)->for($this->user)->create(['enabled' => false, 'is_vod' => true, 'new' => true]);

    $run = $this->service->buildPipeline($playlist, app(GeneralSettings::class));

    expect($run->phases)->toContain(SyncRunPhase::VodTmdb->value);
});

it('dispatches FetchTmdbIds with lookupScope both when tmdb_auto_lookup_all_new is both', function () {
    mockPipelineSettings(tmdb: true, lookupScope: 'both');
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
        && $job->completionPhase === SyncRunPhase::VodTmdb
        && $job->lookupScope === 'both');
});

// ── startImport: concurrency guard ──────────────────────────────────────────

it('returns a new SyncRun when no run is currently active for the playlist', function () {
    $playlist = Playlist::factory()->for($this->user)->create();

    $run = $this->service->startImport($playlist, 'test');

    expect($run)->toBeInstanceOf(SyncRun::class)
        ->and($run->playlist_id)->toBe($playlist->id)
        ->and($run->status)->toBe(SyncRunStatus::Running->value)
        ->and($run->trigger)->toBe('test');

    $this->assertDatabaseCount('sync_runs', 1);
});

it('returns the existing run and creates no new run when one is already active', function () {
    $playlist = Playlist::factory()->for($this->user)->create();

    $existing = SyncRun::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'trigger' => 'scheduled_refresh',
        'status' => SyncRunStatus::Running->value,
        'current_phase' => SyncRunPhase::Import->value,
        'phases' => [SyncRunPhase::Import->value],
        'phase_statuses' => (object) [],
        'context' => ['playlist_id' => $playlist->id],
        'started_at' => now(),
    ]);

    $returned = $this->service->startImport($playlist, 'filament_refresh');

    expect($returned->id)->toBe($existing->id);
    $this->assertDatabaseCount('sync_runs', 1);
});

it('creates a new run when a previous run is completed (not running)', function () {
    $playlist = Playlist::factory()->for($this->user)->create();

    SyncRun::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'trigger' => 'scheduled_refresh',
        'status' => SyncRunStatus::Completed->value,
        'phases' => [SyncRunPhase::SyncCompleted->value],
        'phase_statuses' => (object) [],
        'context' => ['playlist_id' => $playlist->id],
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(5),
    ]);

    $run = $this->service->startImport($playlist, 'scheduled_refresh');

    expect($run->status)->toBe(SyncRunStatus::Running->value);
    $this->assertDatabaseCount('sync_runs', 2);
});

it('creates a new run when a previous run is failed (not running)', function () {
    $playlist = Playlist::factory()->for($this->user)->create();

    SyncRun::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'trigger' => 'scheduled_refresh',
        'status' => SyncRunStatus::Failed->value,
        'phases' => [SyncRunPhase::Import->value],
        'phase_statuses' => (object) [],
        'context' => ['playlist_id' => $playlist->id],
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(5),
    ]);

    $run = $this->service->startImport($playlist, 'scheduled_refresh');

    expect($run->status)->toBe(SyncRunStatus::Running->value);
    $this->assertDatabaseCount('sync_runs', 2);
});

it('does not create a concurrent run for the same playlist when called simultaneously', function () {
    $playlist = Playlist::factory()->for($this->user)->create();

    // Simulate two callers at the same time by calling startImport twice in sequence
    // (the cache lock serialises them and the second should return the existing run).
    $run1 = $this->service->startImport($playlist, 'scheduler');
    $run2 = $this->service->startImport($playlist, 'filament_refresh');

    expect($run2->id)->toBe($run1->id);
    $this->assertDatabaseCount('sync_runs', 1);
});

it('fails a stale Running run (import complete, pipeline dead) and creates a fresh run', function () {
    $playlist = Playlist::factory()->for($this->user)->create();

    $stale = SyncRun::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'trigger' => 'scheduled_refresh',
        'status' => SyncRunStatus::Running->value,
        'current_phase' => 'vod_tmdb',
        'phases' => [SyncRunPhase::Import->value, 'vod_tmdb', SyncRunPhase::SyncCompleted->value],
        'phase_statuses' => ['import' => ['status' => 'completed']], // Import marked complete
        'context' => ['playlist_id' => $playlist->id],
        'started_at' => now()->subMinutes(30),
    ]);

    $fresh = $this->service->startImport($playlist, 'scheduled_refresh');

    // Stale run must be failed, a new run created
    expect($stale->fresh()->status)->toBe(SyncRunStatus::Failed->value)
        ->and($fresh->id)->not->toBe($stale->id)
        ->and($fresh->status)->toBe(SyncRunStatus::Running->value);

    $this->assertDatabaseCount('sync_runs', 2);
});

// ── ProcessM3uImport: duplicate-import guard ─────────────────────────────────

it('ProcessM3uImport bails out without touching the playlist when the import phase is already complete', function () {
    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create([
        'status' => 'completed', // mid-pipeline state (e.g. between VOD and series phases)
    ]));

    // Simulate a run that has already completed its Import phase (pipeline still Running).
    $run = SyncRun::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'trigger' => 'scheduled_refresh',
        'status' => SyncRunStatus::Running->value,
        'current_phase' => SyncRunPhase::VodMetadata->value,
        'phases' => [
            SyncRunPhase::Import->value,
            SyncRunPhase::VodMetadata->value,
            SyncRunPhase::SyncCompleted->value,
        ],
        'phase_statuses' => [
            SyncRunPhase::Import->value => ['status' => 'completed', 'at' => now()->toIso8601String()],
        ],
        'context' => ['playlist_id' => $playlist->id],
        'started_at' => now()->subMinutes(30),
    ]);

    // Invoke the job directly (synchronous, no queue needed).
    (new ProcessM3uImport($playlist, false, $run->id))->handle(
        app(GeneralSettings::class),
    );

    // The playlist must not have been set to Processing — the job returned early.
    expect($playlist->fresh()->status)->toBe(Status::Completed);
});

it('dispatchLiveProbe forwards playlist probe scope settings', function () {
    mockPipelineSettings();
    $playlist = Playlist::factory()->for($this->user)->create([
        'auto_probe_streams' => true,
        'auto_probe_streams_only_unprobed' => false,
        'auto_probe_streams_include_disabled' => true,
    ]);

    $run = SyncRun::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'trigger' => 'test',
        'status' => SyncRunStatus::Running->value,
        'phases' => [SyncRunPhase::LiveProbe->value, SyncRunPhase::SyncCompleted->value],
        'phase_statuses' => (object) [],
        'context' => ['playlist_id' => $playlist->id],
        'started_at' => now(),
    ]);

    $this->service->startRun($run);

    Bus::assertDispatched(
        ProbeChannelStreams::class,
        fn ($job) => $job->syncRunId === $run->id
            && $job->playlistId === $playlist->id
            && $job->onlyUnprobed === false
            && $job->includeDisabled === true,
    );
});

it('dispatchProbe forwards playlist vod and series probe scope settings', function () {
    mockPipelineSettings();
    $playlist = makePlaylistWithBoth($this->user, [
        'auto_probe_vod_streams' => true,
        'auto_probe_vod_streams_only_unprobed' => false,
        'auto_probe_vod_streams_include_disabled' => true,
    ]);

    $vodRun = SyncRun::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'trigger' => 'test',
        'status' => SyncRunStatus::Running->value,
        'phases' => [SyncRunPhase::VodProbe->value, SyncRunPhase::SyncCompleted->value],
        'phase_statuses' => (object) [],
        'context' => ['playlist_id' => $playlist->id],
        'started_at' => now(),
    ]);

    $this->service->startRun($vodRun);

    Bus::assertDispatched(
        ProbeStreams::class,
        fn ($job) => $job->syncRunId === $vodRun->id
            && $job->playlistId === $playlist->id
            && $job->onlyUnprobed === false
            && $job->includeDisabled === true
            && $job->isSeriesProbe === false,
    );

    Bus::fake();
    $seriesRun = SyncRun::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'trigger' => 'test',
        'status' => SyncRunStatus::Running->value,
        'phases' => [SyncRunPhase::SeriesProbe->value, SyncRunPhase::SyncCompleted->value],
        'phase_statuses' => (object) [],
        'context' => ['playlist_id' => $playlist->id],
        'started_at' => now(),
    ]);

    $this->service->startRun($seriesRun);

    Bus::assertDispatched(
        ProbeStreams::class,
        fn ($job) => $job->syncRunId === $seriesRun->id
            && $job->playlistId === $playlist->id
            && $job->onlyUnprobed === false
            && $job->includeDisabled === true
            && $job->isSeriesProbe === true,
    );
});
