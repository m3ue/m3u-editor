<?php

/**
 * Tests for the FireStreamFilesSyncedEvent job and its integration
 * into the VOD and series STRM sync pipeline chains.
 */

use App\Enums\Status;
use App\Jobs\CheckSeriesImportProgress;
use App\Jobs\FetchTmdbIds;
use App\Jobs\FireStreamFilesSyncedEvent;
use App\Jobs\RunPostProcess;
use App\Jobs\SyncSeriesStrmFiles;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Playlist;
use App\Models\PostProcess;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly([
        'status' => Status::Completed,
    ]);
});

// ──────────────────────────────────────────────────────────────────────────────
// FireStreamFilesSyncedEvent — dispatches RunPostProcess for matching records
// ──────────────────────────────────────────────────────────────────────────────

it('dispatches RunPostProcess for each enabled vod_stream_files_synced post-process', function () {
    $pp1 = PostProcess::factory()->create([
        'user_id' => $this->user->id,
        'event' => 'vod_stream_files_synced',
        'enabled' => true,
        'metadata' => ['local' => 'url', 'path' => 'https://example.com/hook'],
    ]);
    $pp2 = PostProcess::factory()->create([
        'user_id' => $this->user->id,
        'event' => 'vod_stream_files_synced',
        'enabled' => true,
        'metadata' => ['local' => 'url', 'path' => 'https://example.com/hook2'],
    ]);

    $this->playlist->postProcesses()->attach([$pp1->id, $pp2->id]);

    (new FireStreamFilesSyncedEvent($this->playlist, 'vod_stream_files_synced'))->handle();

    Bus::assertDispatched(RunPostProcess::class, 2);
});

it('dispatches RunPostProcess for each enabled series_stream_files_synced post-process', function () {
    $pp = PostProcess::factory()->create([
        'user_id' => $this->user->id,
        'event' => 'series_stream_files_synced',
        'enabled' => true,
        'metadata' => ['local' => 'url', 'path' => 'https://example.com/hook'],
    ]);

    $this->playlist->postProcesses()->attach($pp->id);

    (new FireStreamFilesSyncedEvent($this->playlist, 'series_stream_files_synced'))->handle();

    Bus::assertDispatched(RunPostProcess::class, 1);
});

it('does not dispatch RunPostProcess for disabled stream_files_synced post-processes', function () {
    $pp = PostProcess::factory()->create([
        'user_id' => $this->user->id,
        'event' => 'vod_stream_files_synced',
        'enabled' => false,
        'metadata' => ['local' => 'url', 'path' => 'https://example.com/hook'],
    ]);

    $this->playlist->postProcesses()->attach($pp->id);

    (new FireStreamFilesSyncedEvent($this->playlist, 'vod_stream_files_synced'))->handle();

    Bus::assertNotDispatched(RunPostProcess::class);
});

it('does not dispatch RunPostProcess for a different event type', function () {
    $pp = PostProcess::factory()->create([
        'user_id' => $this->user->id,
        'event' => 'vod_stream_files_synced',
        'enabled' => true,
        'metadata' => ['local' => 'url', 'path' => 'https://example.com/hook'],
    ]);

    $this->playlist->postProcesses()->attach($pp->id);

    // Fire series event — should NOT run the VOD post-process
    (new FireStreamFilesSyncedEvent($this->playlist, 'series_stream_files_synced'))->handle();

    Bus::assertNotDispatched(RunPostProcess::class);
});

it('does not dispatch RunPostProcess for synced post-processes', function () {
    $pp = PostProcess::factory()->create([
        'user_id' => $this->user->id,
        'event' => 'synced',
        'enabled' => true,
        'metadata' => ['local' => 'url', 'path' => 'https://example.com/hook'],
    ]);

    $this->playlist->postProcesses()->attach($pp->id);

    (new FireStreamFilesSyncedEvent($this->playlist))->handle();

    Bus::assertNotDispatched(RunPostProcess::class);
});

it('dispatches nothing when the playlist has no post-processes', function () {
    (new FireStreamFilesSyncedEvent($this->playlist))->handle();

    Bus::assertNotDispatched(RunPostProcess::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// CheckSeriesImportProgress — dispatches SyncSeriesStrmFiles (event fires from inside it)
// ──────────────────────────────────────────────────────────────────────────────

it('chains SyncSeriesStrmFiles when series STRM sync is enabled', function () {
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->tmdb_auto_lookup_on_import = false;
    app()->instance(GeneralSettings::class, $mock);

    (new CheckSeriesImportProgress(
        currentOffset: 0,
        totalSeries: 0,
        notify: false,
        playlist_id: $this->playlist->id,
        user_id: $this->user->id,
        sync_stream_files: true,
    ))->handle(app(GeneralSettings::class));

    Bus::assertChained([SyncSeriesStrmFiles::class]);
    Bus::assertNotDispatched(FireStreamFilesSyncedEvent::class);
});

it('does not dispatch SyncSeriesStrmFiles when series STRM sync is disabled', function () {
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->tmdb_auto_lookup_on_import = false;
    app()->instance(GeneralSettings::class, $mock);

    (new CheckSeriesImportProgress(
        currentOffset: 0,
        totalSeries: 0,
        notify: false,
        playlist_id: $this->playlist->id,
        user_id: $this->user->id,
        sync_stream_files: false,
    ))->handle(app(GeneralSettings::class));

    Bus::assertNotDispatched(SyncSeriesStrmFiles::class);
    Bus::assertNotDispatched(FireStreamFilesSyncedEvent::class);
});

it('chains TMDB fetch before SyncSeriesStrmFiles', function () {
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->tmdb_auto_lookup_on_import = true;
    app()->instance(GeneralSettings::class, $mock);

    (new CheckSeriesImportProgress(
        currentOffset: 0,
        totalSeries: 0,
        notify: false,
        playlist_id: $this->playlist->id,
        user_id: $this->user->id,
        sync_stream_files: true,
    ))->handle(app(GeneralSettings::class));

    Bus::assertChained([FetchTmdbIds::class, SyncSeriesStrmFiles::class]);
});

// ──────────────────────────────────────────────────────────────────────────────
// SyncSeriesStrmFiles (isCleanupJob) — dispatches FireStreamFilesSyncedEvent at terminal
// ──────────────────────────────────────────────────────────────────────────────

it('SyncSeriesStrmFiles cleanup dispatches FireStreamFilesSyncedEvent for the playlist', function () {
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
        return $job->playlist->id === $this->playlist->id;
    });
});

it('SyncSeriesStrmFiles cleanup does not dispatch FireStreamFilesSyncedEvent when all_playlists is true', function () {
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->default_series_stream_file_setting_id = null;
    $mock->stream_file_sync_enabled = false;
    app()->instance(GeneralSettings::class, $mock);

    (new SyncSeriesStrmFiles(
        notify: false,
        all_playlists: true,
        user_id: $this->user->id,
        isCleanupJob: true,
    ))->handle(app(GeneralSettings::class));

    Bus::assertNotDispatched(FireStreamFilesSyncedEvent::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// SyncVodStrmFiles (isCleanupJob) — dispatches FireStreamFilesSyncedEvent at terminal
// ──────────────────────────────────────────────────────────────────────────────

it('SyncVodStrmFiles cleanup dispatches FireStreamFilesSyncedEvent for the playlist', function () {
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->default_vod_stream_file_setting_id = null;
    $mock->vod_stream_file_sync_location = null;
    app()->instance(GeneralSettings::class, $mock);

    (new SyncVodStrmFiles(
        notify: false,
        playlist: $this->playlist,
        isCleanupJob: true,
    ))->handle(app(GeneralSettings::class));

    Bus::assertDispatched(FireStreamFilesSyncedEvent::class, function (FireStreamFilesSyncedEvent $job): bool {
        return $job->playlist->id === $this->playlist->id;
    });
});

it('SyncVodStrmFiles cleanup does not dispatch FireStreamFilesSyncedEvent when all_playlists is true', function () {
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->default_vod_stream_file_setting_id = null;
    $mock->vod_stream_file_sync_location = null;
    app()->instance(GeneralSettings::class, $mock);

    (new SyncVodStrmFiles(
        notify: false,
        all_playlists: true,
        user_id: $this->user->id,
        isCleanupJob: true,
    ))->handle(app(GeneralSettings::class));

    Bus::assertNotDispatched(FireStreamFilesSyncedEvent::class);
});
