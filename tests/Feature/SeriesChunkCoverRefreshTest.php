<?php

/**
 * Some providers sign series cover URLs with a short expiry, so the cover saved on first
 * import stops loading after a few days. ProcessM3uImportSeriesChunk takes the fresh URL from
 * the listing for series already in the DB, but only replaces covers from the same image host.
 */

use App\Jobs\ProcessM3uImportSeriesChunk;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Run one chunk per listing, in order, as successive syncs of the same category.
 *
 * @param  list<list<array<string, mixed>>>  $listings
 */
function runCoverRefreshSyncs(Playlist $playlist, array $listings, ?Closure $betweenSyncs = null): void
{
    $sequence = Http::sequence();
    foreach ($listings as $listing) {
        $sequence->push(json_encode($listing), 200);
    }
    Http::fake(['xtream.test/player_api.php*' => $sequence]);

    foreach (array_keys($listings) as $run) {
        if ($run > 0 && $betweenSyncs) {
            $betweenSyncs();
        }

        (new ProcessM3uImportSeriesChunk(
            payload: ['playlistId' => $playlist->id, 'categoryId' => 1, 'categoryName' => 'Drama'],
            batchCount: 1,
            batchNo: "batch-{$run}",
            index: 0,
        ))->handle();
    }
}

beforeEach(function () {
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for(User::factory()->create())->create([
        'xtream' => true,
        'xtream_config' => ['url' => 'http://xtream.test', 'username' => 'user', 'password' => 'pass'],
    ]));
});

it('replaces an expired provider cover with the fresh one from the listing', function () {
    runCoverRefreshSyncs($this->playlist, [
        [['series_id' => 10, 'name' => 'Show', 'cover' => 'https://img.provider.test/series/old-token/10/cover']],
        [['series_id' => 10, 'name' => 'Show', 'cover' => 'https://img.provider.test/series/new-token/10/cover']],
    ]);

    expect(Series::where('source_series_id', 10)->value('cover'))
        ->toBe('https://img.provider.test/series/new-token/10/cover');
});

it('keeps a cover from another host, such as TMDB', function () {
    runCoverRefreshSyncs($this->playlist, [
        [['series_id' => 11, 'name' => 'Show', 'cover' => 'https://img.provider.test/series/old-token/11/cover']],
        [['series_id' => 11, 'name' => 'Show', 'cover' => 'https://img.provider.test/series/new-token/11/cover']],
    ], fn () => Series::where('source_series_id', 11)->update(['cover' => 'https://image.tmdb.org/t/p/w500/poster.jpg']));

    expect(Series::where('source_series_id', 11)->value('cover'))
        ->toBe('https://image.tmdb.org/t/p/w500/poster.jpg');
});

it('keeps a TMDB cover even when the provider also serves its covers from TMDB', function () {
    runCoverRefreshSyncs($this->playlist, [
        [['series_id' => 14, 'name' => 'Show', 'cover' => 'https://image.tmdb.org/t/p/w600_and_h900_bestv2/provider.jpg']],
        [['series_id' => 14, 'name' => 'Show', 'cover' => 'https://image.tmdb.org/t/p/w600_and_h900_bestv2/provider.jpg']],
    ], fn () => Series::where('source_series_id', 14)->update(['cover' => 'https://image.tmdb.org/t/p/w500/manual-match.jpg']));

    expect(Series::where('source_series_id', 14)->value('cover'))
        ->toBe('https://image.tmdb.org/t/p/w500/manual-match.jpg');
});

it('fills a blank cover from the listing', function () {
    runCoverRefreshSyncs($this->playlist, [
        [['series_id' => 12, 'name' => 'Show']],
        [['series_id' => 12, 'name' => 'Show', 'cover' => 'https://img.provider.test/series/token/12/cover']],
    ]);

    expect(Series::where('source_series_id', 12)->value('cover'))
        ->toBe('https://img.provider.test/series/token/12/cover');
});

it('keeps the stored cover when the listing has no usable cover', function (mixed $listingCover) {
    runCoverRefreshSyncs($this->playlist, [
        [['series_id' => 13, 'name' => 'Show', 'cover' => 'https://img.provider.test/series/token/13/cover']],
        [['series_id' => 13, 'name' => 'Show', 'cover' => $listingCover]],
    ]);

    expect(Series::where('source_series_id', 13)->value('cover'))
        ->toBe('https://img.provider.test/series/token/13/cover');
})->with([
    'blank' => [''],
    'null' => [null],
    'not a url' => ['cover.jpg'],
]);
