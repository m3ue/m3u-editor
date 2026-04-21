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
    $mock->shouldReceive('getAttribute')
        ->with('tmdb_auto_lookup_on_import')
        ->andReturn($tmdbAutoLookupOnImport);
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

it('does not dispatch FetchTmdbIds when episode metadata sync will run afterwards', function () {
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

    // CheckSeriesImportProgress handles the TMDB dispatch at the end of the
    // metadata sync flow — firing here too would duplicate work.
    Bus::assertNotDispatched(FetchTmdbIds::class);
});

it('still dispatches FetchTmdbIds when metadata toggle is on but no series are enabled', function () {
    mockSeriesCompleteSettings(tmdbAutoLookupOnImport: true);

    $this->playlist->update(['auto_fetch_series_metadata' => true]);

    $category = Category::factory()->for($this->playlist)->for($this->user)->create();
    Series::factory()->for($this->playlist)->for($this->user)->for($category)->create([
        'enabled' => false,
    ]);

    $job = new ProcessM3uImportSeriesComplete(
        playlist: $this->playlist->fresh(),
        batchNo: 'test-batch',
    );

    $job->handle(app(GeneralSettings::class));

    Bus::assertDispatched(FetchTmdbIds::class);
});
