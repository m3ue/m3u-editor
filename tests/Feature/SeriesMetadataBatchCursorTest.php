<?php

/**
 * A successful fetch removes a series from the needsMetadataRefresh() set, so batches
 * selected by offset skipped whatever slid below the offset, while the run still
 * reported 100%. Batches now select by id cursor (issue #1814).
 */

use App\Enums\Status;
use App\Jobs\CheckSeriesImportProgress;
use App\Jobs\ProcessM3uImportSeriesEpisodes;
use App\Models\Category;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The real chain, including the checker and any follow-up chain, runs inline.
    config(['queue.default' => 'sync']);
    Notification::fake();

    $settings = Mockery::mock(GeneralSettings::class)->shouldIgnoreMissing();
    $settings->tmdb_auto_lookup_on_import = false;
    $settings->stream_file_sync_enabled = false;
    app()->instance(GeneralSettings::class, $settings);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create([
        'xtream' => true,
        'xtream_config' => ['url' => 'http://provider.test', 'username' => 'u', 'password' => 'p'],
    ]));
    $this->category = Category::factory()->for($this->user)->for($this->playlist)->create();

    Http::fake(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response([
            'info' => ['name' => 'Series', 'last_modified' => now()->subDays(2)->timestamp],
            'seasons' => [['id' => 1, 'season_number' => 1, 'name' => 'Season 1']],
            'episodes' => ['1' => [[
                'id' => 1000 + (int) ($query['series_id'] ?? 0),
                'episode_num' => 1,
                'title' => 'S01E01 - Pilot',
                'container_extension' => 'mkv',
                'info' => [],
            ]]],
        ]);
    });
});

function cursorMakeSeries(int $count, int $firstSourceId = 1): void
{
    Series::factory()->count($count)
        ->sequence(fn ($sequence) => ['source_series_id' => $firstSourceId + $sequence->index])
        ->create([
            'user_id' => test()->user->id,
            'playlist_id' => test()->playlist->id,
            'category_id' => test()->category->id,
            'enabled' => true,
            'last_metadata_fetch' => null,
            'last_modified' => now()->subDay(),
        ]);
}

function cursorRunSync(bool $overwriteExisting = false): void
{
    (new ProcessM3uImportSeriesEpisodes(
        notify: false,
        playlist_id: test()->playlist->id,
        overwrite_existing: $overwriteExisting,
        user_id: test()->user->id,
        sync_stream_files: false,
    ))->handle(app(GeneralSettings::class));
}

function cursorFetchedSourceIds(): array
{
    return Http::recorded()
        ->map(fn (array $pair) => (string) $pair[0]->url())
        ->filter(fn (string $url) => str_contains($url, 'get_series_info'))
        ->map(function (string $url): int {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return (int) $query['series_id'];
        })
        ->sort()
        ->values()
        ->all();
}

it('fetches every stale series across batches and only then completes', function () {
    cursorMakeSeries(101);

    cursorRunSync();

    expect(cursorFetchedSourceIds())->toBe(range(1, 101))
        ->and(Series::whereNull('last_metadata_fetch')->count())->toBe(0);

    $this->playlist->refresh();
    expect($this->playlist->status)->toBe(Status::Completed)
        ->and((int) $this->playlist->series_progress)->toBe(100);
});

it('skips series that are already fresh in a partially fresh catalog', function () {
    cursorMakeSeries(150);
    // Interleaved through the id range: already fetched, with episodes, so not eligible.
    Series::whereIn('source_series_id', range(1, 150, 10))->each(function (Series $series) {
        $series->fetchMetadata(sync: false, dispatchTmdb: false);
    });
    $fetchedBeforeRun = count(cursorFetchedSourceIds());

    cursorRunSync();

    expect(count(cursorFetchedSourceIds()) - $fetchedBeforeRun)->toBe(150 - count(range(1, 150, 10)))
        ->and(Series::whereNull('last_metadata_fetch')->count())->toBe(0);
});

it('still processes every series on a forced refresh', function () {
    cursorMakeSeries(101);
    cursorRunSync();
    $afterFirstRun = count(cursorFetchedSourceIds());

    cursorRunSync(overwriteExisting: true);

    expect(count(cursorFetchedSourceIds()) - $afterFirstRun)->toBe(101);
});

it('resumes the next chain from the cursor rather than the start of the catalog', function () {
    cursorMakeSeries(3);
    $first = Series::orderBy('id')->first();

    (new CheckSeriesImportProgress(
        currentOffset: 1,
        totalSeries: 3,
        notify: false,
        playlist_id: $this->playlist->id,
        user_id: $this->user->id,
        sync_stream_files: false,
        lastSeriesId: $first->id,
    ))->handle(app(GeneralSettings::class));

    expect(cursorFetchedSourceIds())->toBe([2, 3]);
});

it('completes instead of looping when nothing past the cursor is still eligible', function () {
    cursorMakeSeries(2);
    $last = Series::orderByDesc('id')->first();

    (new CheckSeriesImportProgress(
        currentOffset: 1,
        totalSeries: 5,
        notify: false,
        playlist_id: $this->playlist->id,
        user_id: $this->user->id,
        sync_stream_files: false,
        lastSeriesId: $last->id,
    ))->handle(app(GeneralSettings::class));

    expect(cursorFetchedSourceIds())->toBe([])
        ->and($this->playlist->refresh()->status)->toBe(Status::Completed);
});
