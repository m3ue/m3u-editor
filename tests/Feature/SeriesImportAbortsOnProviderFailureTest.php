<?php

/**
 * Regression tests for issue #1482: a provider failure or malformed row during
 * series import must never let a partial result land. Landing a partial import
 * is how ProcessM3uImportComplete::seriesCleanup() ends up deleting series that
 * simply failed to fetch this run - the "my series vanished and reappeared" bug.
 */

use App\Jobs\ProcessM3uImportSeriesChunk;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function makeSeriesImportPlaylist(User $user): Playlist
{
    return Playlist::withoutEvents(fn (): Playlist => Playlist::factory()->for($user)->create([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://xtream.test',
            'username' => 'user',
            'password' => 'pass',
        ],
    ]));
}

it('throws instead of silently skipping a category when get_series fails', function () {
    $user = User::factory()->create();
    $playlist = makeSeriesImportPlaylist($user);

    Http::preventStrayRequests();
    Http::fake([
        '*action=get_series*' => Http::response('Internal Server Error', 500),
    ]);

    $job = new ProcessM3uImportSeriesChunk(
        payload: ['categoryId' => 5, 'categoryName' => 'Broken Category', 'playlistId' => $playlist->id],
        batchCount: 1,
        batchNo: 'batch-1',
        index: 0,
    );

    // Http::throw() already raises on the 500 before our own !ok() guard runs -
    // either way, the category must not be silently skipped.
    expect(fn () => $job->handle())->toThrow(Exception::class);
    expect(Series::where('playlist_id', $playlist->id)->count())->toBe(0);
});

it('throws instead of silently skipping a category when get_series returns a non-JSON body', function () {
    $user = User::factory()->create();
    $playlist = makeSeriesImportPlaylist($user);

    Http::preventStrayRequests();
    Http::fake([
        '*action=get_series*' => Http::response('<html>not json</html>', 200),
    ]);

    $job = new ProcessM3uImportSeriesChunk(
        payload: ['categoryId' => 5, 'categoryName' => 'Broken Category', 'playlistId' => $playlist->id],
        batchCount: 1,
        batchNo: 'batch-1',
        index: 0,
    );

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    expect(Series::where('playlist_id', $playlist->id)->count())->toBe(0);
});

it('drops a series entry with no series_id but still imports the rest of the category', function () {
    $user = User::factory()->create();
    $playlist = makeSeriesImportPlaylist($user);

    Http::preventStrayRequests();
    Http::fake([
        '*action=get_series*' => Http::response([
            ['series_id' => null, 'name' => 'Malformed Entry'],
            ['series_id' => 501, 'name' => 'Good Series'],
        ]),
    ]);

    $job = new ProcessM3uImportSeriesChunk(
        payload: ['categoryId' => 5, 'categoryName' => 'Mixed Category', 'playlistId' => $playlist->id],
        batchCount: 1,
        batchNo: 'batch-1',
        index: 0,
    );

    $job->handle();

    $series = Series::where('playlist_id', $playlist->id)->get();
    expect($series)->toHaveCount(1);
    expect($series->first()->name)->toBe('Good Series');
    expect((string) $series->first()->source_series_id)->toBe('501');
});
