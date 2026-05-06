<?php

/**
 * Tests for the VOD pipeline completion ordering.
 *
 * As of the orchestrator chain refactor:
 *   - STRM sync (SyncVodStrmFiles) and Find/Replace are NO LONGER chained
 *     inside ProcessVodChannelsComplete / ProcessM3uImportVod. They run via
 *     {@see StrmSyncPhase} and
 *     {@see FindReplaceAndSortAlphaPhase} after the
 *     completion job fires SyncCompleted, which the orchestrator picks up.
 *   - The completion job (FireSyncCompletedEvent or TriggerSeriesImport) is
 *     either chained after FetchTmdbIds (if TMDB lookup is enabled) or
 *     dispatched directly.
 */

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Jobs\FetchTmdbIds;
use App\Jobs\FireSyncCompletedEvent;
use App\Jobs\ProcessM3uImportSeries;
use App\Jobs\ProcessM3uImportVod;
use App\Jobs\ProcessVodChannelsComplete;
use App\Jobs\RunPlaylistFindReplaceRules;
use App\Jobs\SyncVodStrmFiles;
use App\Jobs\TriggerSeriesImport;
use App\Models\Playlist;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Sync\Phases\FindReplaceAndSortAlphaPhase;
use App\Sync\Phases\StrmSyncPhase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function mockVodCompleteSettings(bool $tmdbAutoLookup): GeneralSettings
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->tmdb_auto_lookup_on_import = $tmdbAutoLookup;
    app()->instance(GeneralSettings::class, $mock);

    return $mock;
}

beforeEach(function () {
    Bus::fake();
    Event::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly([
        'status' => Status::Completed,
        'auto_sync_vod_stream_files' => false,
        'find_replace_rules' => null,
    ]);
});

// ──────────────────────────────────────────────────────────────────────────────
// ProcessVodChannelsComplete — null completionJob (UI-triggered refreshes)
// ──────────────────────────────────────────────────────────────────────────────

it('fires no completion job when completionJob is null and no post-jobs are needed', function () {
    mockVodCompleteSettings(tmdbAutoLookup: false);

    (new ProcessVodChannelsComplete(playlist: $this->playlist))->handle(app(GeneralSettings::class));

    Event::assertNotDispatched(SyncCompleted::class);
    Bus::assertNotDispatched(FireSyncCompletedEvent::class);
    Bus::assertNotDispatched(TriggerSeriesImport::class);
    Bus::assertNotDispatched(SyncVodStrmFiles::class);
});

it('does not chain SyncVodStrmFiles even when auto_sync_vod_stream_files is true (orchestrator owns STRM dispatch)', function () {
    mockVodCompleteSettings(tmdbAutoLookup: false);
    $this->playlist->update(['auto_sync_vod_stream_files' => true]);

    (new ProcessVodChannelsComplete(playlist: $this->playlist))->handle(app(GeneralSettings::class));

    Event::assertNotDispatched(SyncCompleted::class);
    Bus::assertNotDispatched(SyncVodStrmFiles::class);
    Bus::assertNotDispatched(FireSyncCompletedEvent::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// ProcessVodChannelsComplete — FireSyncCompletedEvent as completionJob (VOD-only sync)
// ──────────────────────────────────────────────────────────────────────────────

it('dispatches FireSyncCompletedEvent directly when no post-jobs are needed', function () {
    mockVodCompleteSettings(tmdbAutoLookup: false);

    $completionJob = new FireSyncCompletedEvent($this->playlist);
    (new ProcessVodChannelsComplete($this->playlist, $completionJob))->handle(app(GeneralSettings::class));

    Event::assertNotDispatched(SyncCompleted::class);
    Bus::assertDispatched(FireSyncCompletedEvent::class);
});

it('dispatches FireSyncCompletedEvent directly when only STRM sync is enabled (no chain)', function () {
    mockVodCompleteSettings(tmdbAutoLookup: false);
    $this->playlist->update(['auto_sync_vod_stream_files' => true]);

    $completionJob = new FireSyncCompletedEvent($this->playlist);
    (new ProcessVodChannelsComplete($this->playlist, $completionJob))->handle(app(GeneralSettings::class));

    Event::assertNotDispatched(SyncCompleted::class);
    Bus::assertDispatched(FireSyncCompletedEvent::class);
    Bus::assertNotDispatched(SyncVodStrmFiles::class);
});

it('chains FireSyncCompletedEvent after FetchTmdbIds when only TMDB lookup is enabled', function () {
    mockVodCompleteSettings(tmdbAutoLookup: true);

    $completionJob = new FireSyncCompletedEvent($this->playlist);
    (new ProcessVodChannelsComplete($this->playlist, $completionJob))->handle(app(GeneralSettings::class));

    Event::assertNotDispatched(SyncCompleted::class);
    Bus::assertChained([FetchTmdbIds::class, FireSyncCompletedEvent::class]);
});

it('chains FireSyncCompletedEvent after FetchTmdbIds when both TMDB and STRM sync are enabled', function () {
    mockVodCompleteSettings(tmdbAutoLookup: true);
    $this->playlist->update(['auto_sync_vod_stream_files' => true]);

    $completionJob = new FireSyncCompletedEvent($this->playlist);
    (new ProcessVodChannelsComplete($this->playlist, $completionJob))->handle(app(GeneralSettings::class));

    Event::assertNotDispatched(SyncCompleted::class);
    Bus::assertChained([FetchTmdbIds::class, FireSyncCompletedEvent::class]);
    Bus::assertNotDispatched(SyncVodStrmFiles::class);
});

it('does not chain FindReplace inside ProcessVodChannelsComplete (orchestrator owns name processing)', function () {
    mockVodCompleteSettings(tmdbAutoLookup: false);
    $this->playlist->update([
        'auto_sync_vod_stream_files' => true,
        'find_replace_rules' => [['enabled' => true, 'find_replace' => '^US- ', 'replace_with' => '']],
    ]);

    $completionJob = new FireSyncCompletedEvent($this->playlist);
    (new ProcessVodChannelsComplete($this->playlist, $completionJob))->handle(app(GeneralSettings::class));

    Event::assertNotDispatched(SyncCompleted::class);
    Bus::assertNotDispatched(RunPlaylistFindReplaceRules::class);
    Bus::assertNotDispatched(SyncVodStrmFiles::class);
    Bus::assertDispatched(FireSyncCompletedEvent::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// ProcessVodChannelsComplete — TriggerSeriesImport as completionJob (VOD→Series)
// ──────────────────────────────────────────────────────────────────────────────

it('dispatches TriggerSeriesImport directly when only STRM sync is enabled', function () {
    mockVodCompleteSettings(tmdbAutoLookup: false);
    $this->playlist->update(['auto_sync_vod_stream_files' => true]);

    $completionJob = new TriggerSeriesImport($this->playlist, false, 'batch-123');
    (new ProcessVodChannelsComplete($this->playlist, $completionJob))->handle(app(GeneralSettings::class));

    Event::assertNotDispatched(SyncCompleted::class);
    Bus::assertDispatched(TriggerSeriesImport::class);
    Bus::assertNotDispatched(SyncVodStrmFiles::class);
});

it('dispatches TriggerSeriesImport directly when no post-jobs are needed', function () {
    mockVodCompleteSettings(tmdbAutoLookup: false);

    $completionJob = new TriggerSeriesImport($this->playlist, false, 'batch-123');
    (new ProcessVodChannelsComplete($this->playlist, $completionJob))->handle(app(GeneralSettings::class));

    Event::assertNotDispatched(SyncCompleted::class);
    Bus::assertDispatched(TriggerSeriesImport::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// ProcessM3uImportVod — STRM-only path (no metadata fetch)
// ──────────────────────────────────────────────────────────────────────────────

it('dispatches FireSyncCompletedEvent in the STRM-only path (orchestrator handles STRM sync)', function () {
    $this->playlist->update(['auto_fetch_vod_metadata' => false, 'auto_sync_vod_stream_files' => true]);

    $completionJob = new FireSyncCompletedEvent($this->playlist);
    (new ProcessM3uImportVod($this->playlist, false, 'batch', $completionJob))->handle();

    Bus::assertDispatched(FireSyncCompletedEvent::class);
    Bus::assertNotDispatched(SyncVodStrmFiles::class);
});

it('dispatches TriggerSeriesImport in the STRM-only path', function () {
    $this->playlist->update(['auto_fetch_vod_metadata' => false, 'auto_sync_vod_stream_files' => true]);

    $completionJob = new TriggerSeriesImport($this->playlist, false, 'batch');
    (new ProcessM3uImportVod($this->playlist, false, 'batch', $completionJob))->handle();

    Bus::assertDispatched(TriggerSeriesImport::class);
    Bus::assertNotDispatched(SyncVodStrmFiles::class);
});

it('dispatches nothing in the STRM-only path when completionJob is null', function () {
    $this->playlist->update(['auto_fetch_vod_metadata' => false, 'auto_sync_vod_stream_files' => true]);

    (new ProcessM3uImportVod($this->playlist, false, 'batch'))->handle();

    Bus::assertNotDispatched(SyncVodStrmFiles::class);
    Bus::assertNotDispatched(FireSyncCompletedEvent::class);
    Bus::assertNotDispatched(TriggerSeriesImport::class);
});

it('does not chain FindReplace in STRM-only path (orchestrator owns name processing)', function () {
    $this->playlist->update([
        'auto_fetch_vod_metadata' => false,
        'auto_sync_vod_stream_files' => true,
        'find_replace_rules' => [['enabled' => true, 'find_replace' => '^US- ', 'replace_with' => '']],
    ]);

    $completionJob = new FireSyncCompletedEvent($this->playlist);
    (new ProcessM3uImportVod($this->playlist, false, 'batch', $completionJob))->handle();

    Bus::assertNotDispatched(RunPlaylistFindReplaceRules::class);
    Bus::assertNotDispatched(SyncVodStrmFiles::class);
    Bus::assertDispatched(FireSyncCompletedEvent::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// TriggerSeriesImport — dispatches ProcessM3uImportSeries
// ──────────────────────────────────────────────────────────────────────────────

it('TriggerSeriesImport dispatches ProcessM3uImportSeries with force=true', function () {
    (new TriggerSeriesImport($this->playlist, false, 'batch-abc'))->handle();

    Bus::assertDispatched(ProcessM3uImportSeries::class, function (ProcessM3uImportSeries $job): bool {
        return $job->playlist->id === $this->playlist->id
            && $job->force === true
            && $job->batchNo === 'batch-abc';
    });
});

// ──────────────────────────────────────────────────────────────────────────────
// FireSyncCompletedEvent — fires SyncCompleted
// ──────────────────────────────────────────────────────────────────────────────

it('FireSyncCompletedEvent fires SyncCompleted for its playlist', function () {
    Event::fake();

    (new FireSyncCompletedEvent($this->playlist))->handle();

    Event::assertDispatched(SyncCompleted::class, fn (SyncCompleted $e) => $e->model->id === $this->playlist->id);
});
