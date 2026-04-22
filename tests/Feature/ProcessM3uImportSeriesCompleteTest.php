<?php

use App\Jobs\FetchTmdbIds;
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

it('dispatches FetchTmdbIds when the global auto-lookup setting is enabled', function () {
    mockSeriesCompleteSettings(tmdbAutoLookupOnImport: true);

    $job = new ProcessM3uImportSeriesComplete(
        playlist: $this->playlist,
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

it('dispatches FetchTmdbIds even when episode metadata sync is also enabled', function () {
    // On subsequent imports with auto_fetch_series_metadata on, CheckSeriesImportProgress
    // will also dispatch FetchTmdbIds. We always dispatch here too; the second run is a
    // near no-op because overwriteExisting defaults to false.
    mockSeriesCompleteSettings(tmdbAutoLookupOnImport: true);

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

    Bus::assertDispatched(FetchTmdbIds::class);
});

it('dispatches FetchTmdbIds on a first-time import when no series exist yet at cleanup time', function () {
    // On the very first sync, ProcessM3uImportComplete runs before series chunks
    // populate the DB, so it skips dispatching ProcessM3uImportSeries. Without this
    // dispatch, TMDB IDs would never be assigned. We simulate that scenario here by
    // having auto_fetch_series_metadata enabled but zero series in the DB.
    mockSeriesCompleteSettings(tmdbAutoLookupOnImport: true);

    $this->playlist->update(['auto_fetch_series_metadata' => true]);

    // No Series records — mirrors the state of a brand-new playlist's first import.
    $job = new ProcessM3uImportSeriesComplete(
        playlist: $this->playlist->fresh(),
        batchNo: 'test-batch',
    );

    $job->handle(app(GeneralSettings::class));

    Bus::assertDispatched(FetchTmdbIds::class);
});
