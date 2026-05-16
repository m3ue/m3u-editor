<?php

use App\Jobs\FetchTmdbIds;
use App\Jobs\ProcessM3uImportSeries;
use App\Jobs\ProcessM3uImportSeriesComplete;
use App\Models\Category;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function mockSeriesCompleteSettings(bool $tmdbAutoLookupOnImport): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->tmdb_auto_lookup_on_import = $tmdbAutoLookupOnImport;

    app()->instance(GeneralSettings::class, $mock);
}

beforeEach(function () {
    Bus::fake();
    Event::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'name' => 'TMDB AutoFetch Test',
        'auto_fetch_series_metadata' => false,
    ]);
});

it('does not dispatch FetchTmdbIds when TMDB is enabled but no series exist', function () {
    // When no enabled series are in the DB, the mini-pipeline returns early.
    // There is nothing to look up, so FetchTmdbIds must not be dispatched.
    mockSeriesCompleteSettings(tmdbAutoLookupOnImport: true);

    $job = new ProcessM3uImportSeriesComplete(
        playlist: $this->playlist,
        batchNo: 'test-batch',
    );

    $job->handle(app(GeneralSettings::class));

    Bus::assertNotDispatched(FetchTmdbIds::class);
});

it('dispatches FetchTmdbIds immediately when TMDB is enabled, series exist, and episode metadata sync is disabled', function () {
    // With no SeriesMetadata phase, the mini-pipeline's first phase is SeriesTmdb,
    // so startRun() dispatches FetchTmdbIds right away.
    mockSeriesCompleteSettings(tmdbAutoLookupOnImport: true);

    $category = Category::factory()->for($this->playlist)->for($this->user)->create();
    Series::factory()->for($this->playlist)->for($this->user)->for($category)->create(['enabled' => true]);

    $job = new ProcessM3uImportSeriesComplete(
        playlist: $this->playlist->fresh(),
        batchNo: 'test-batch',
    );

    $job->handle(app(GeneralSettings::class));

    Bus::assertDispatched(FetchTmdbIds::class, function (FetchTmdbIds $dispatched): bool {
        return $dispatched->seriesPlaylistId === $this->playlist->id
            && $dispatched->user?->id === $this->user->id
            && $dispatched->sendCompletionNotification === false;
    });
});

it('does not dispatch FetchTmdbIds when the global auto-lookup setting is disabled', function () {
    mockSeriesCompleteSettings(tmdbAutoLookupOnImport: false);

    $job = new ProcessM3uImportSeriesComplete(
        playlist: $this->playlist,
        batchNo: 'test-batch',
    );

    $job->handle(app(GeneralSettings::class));

    Bus::assertNotDispatched(FetchTmdbIds::class);
});

it('dispatches ProcessM3uImportSeries with a syncRunId when TMDB and episode metadata sync are both enabled', function () {
    // SeriesMetadata is the first pipeline phase, so ProcessM3uImportSeries is dispatched
    // immediately. FetchTmdbIds comes later once CheckSeriesImportProgress hands off to
    // the pipeline via completePhase(SeriesMetadata).
    mockSeriesCompleteSettings(tmdbAutoLookupOnImport: true);

    $this->playlist->update(['auto_fetch_series_metadata' => true]);

    $category = Category::factory()->for($this->playlist)->for($this->user)->create();
    Series::factory()->for($this->playlist)->for($this->user)->for($category)->create(['enabled' => true]);

    $job = new ProcessM3uImportSeriesComplete(
        playlist: $this->playlist->fresh(),
        batchNo: 'test-batch',
    );

    $job->handle(app(GeneralSettings::class));

    Bus::assertDispatched(ProcessM3uImportSeries::class, function (ProcessM3uImportSeries $dispatched): bool {
        return $dispatched->playlist->id === $this->playlist->id
            && $dispatched->syncRunId !== null;
    });
    Bus::assertNotDispatched(FetchTmdbIds::class);
});

it('dispatches nothing when no series exist even with TMDB and episode metadata enabled', function () {
    // If somehow ProcessM3uImportSeriesComplete fires with an empty series table
    // (e.g. all series disabled), the mini-pipeline returns early — there is nothing
    // to process, so no jobs should be dispatched.
    mockSeriesCompleteSettings(tmdbAutoLookupOnImport: true);

    $this->playlist->update(['auto_fetch_series_metadata' => true]);

    // No Series records in DB.
    $job = new ProcessM3uImportSeriesComplete(
        playlist: $this->playlist->fresh(),
        batchNo: 'test-batch',
    );

    $job->handle(app(GeneralSettings::class));

    Bus::assertNotDispatched(FetchTmdbIds::class);
    Bus::assertNotDispatched(ProcessM3uImportSeries::class);
});

it('dispatches ProcessM3uImportSeries when auto_fetch_series_metadata is enabled and enabled series exist', function () {
    mockSeriesCompleteSettings(tmdbAutoLookupOnImport: false);

    $this->playlist->update(['auto_fetch_series_metadata' => true]);

    $category = Category::factory()->for($this->playlist)->for($this->user)->create();
    Series::factory()->for($this->playlist)->for($this->user)->for($category)->create([
        'enabled' => true,
    ]);

    $job = new ProcessM3uImportSeriesComplete(
        playlist: $this->playlist->fresh(),
        batchNo: 'test-batch',
    );

    $job->handle(app(GeneralSettings::class));

    Bus::assertDispatched(ProcessM3uImportSeries::class, function (ProcessM3uImportSeries $dispatched): bool {
        return $dispatched->playlist->id === $this->playlist->id
            && $dispatched->force === true;
    });
});

it('does not dispatch ProcessM3uImportSeries when auto_fetch_series_metadata is disabled', function () {
    mockSeriesCompleteSettings(tmdbAutoLookupOnImport: false);

    $this->playlist->update(['auto_fetch_series_metadata' => false]);

    $category = Category::factory()->for($this->playlist)->for($this->user)->create();
    Series::factory()->for($this->playlist)->for($this->user)->for($category)->create([
        'enabled' => true,
    ]);

    $job = new ProcessM3uImportSeriesComplete(
        playlist: $this->playlist->fresh(),
        batchNo: 'test-batch',
    );

    $job->handle(app(GeneralSettings::class));

    Bus::assertNotDispatched(ProcessM3uImportSeries::class);
});

it('does not dispatch ProcessM3uImportSeries when no enabled series exist', function () {
    mockSeriesCompleteSettings(tmdbAutoLookupOnImport: false);

    $this->playlist->update(['auto_fetch_series_metadata' => true]);

    // No series in DB — first-time import, chunks haven't run yet.

    $job = new ProcessM3uImportSeriesComplete(
        playlist: $this->playlist->fresh(),
        batchNo: 'test-batch',
    );

    $job->handle(app(GeneralSettings::class));

    Bus::assertNotDispatched(ProcessM3uImportSeries::class);
});
