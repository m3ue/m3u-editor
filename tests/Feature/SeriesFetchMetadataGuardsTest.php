<?php

/**
 * Regression tests for issue #1482.
 *
 * A Series with no source_series_id (a malformed provider row that predates the
 * ingest guard added in ProcessM3uImportSeriesChunk) used to reach
 * XtreamService::getSeriesInfo(string $seriesId) with null, throwing a TypeError
 * that escaped fetchMetadata()'s catch(\Exception) block, killed the batch job,
 * and stranded the series_metadata pipeline phase forever ("stuck processing").
 *
 * Also covers the episodes.title varchar(255) overflow: a provider title that
 * doesn't match the "SxxExx - Title" pattern falls back to the raw provider
 * title, which can exceed the column limit and fail the whole episode upsert.
 */

use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function xtreamPlaylistForSeries(User $user, array $overrides = []): Playlist
{
    return Playlist::withoutEvents(fn (): Playlist => Playlist::factory()->for($user)->create(array_merge([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://xtream.test',
            'username' => 'user',
            'password' => 'pass',
        ],
    ], $overrides)));
}

it('skips the provider fetch and returns true for a series with no source_series_id', function () {
    $user = User::factory()->create();
    $playlist = xtreamPlaylistForSeries($user);
    $series = Series::factory()->for($playlist)->for($user)->create([
        'source_series_id' => null,
        'is_custom' => false,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        '*action=get_series_info*' => Http::response(['error' => 'should never be called'], 500),
    ]);

    expect($series->fetchMetadata())->toBeTrue();
    Http::assertNothingSent();
});

it('clamps an oversized episode title so the upsert does not fail the whole series', function () {
    $user = User::factory()->create();
    $playlist = xtreamPlaylistForSeries($user);
    $series = Series::factory()->for($playlist)->for($user)->create([
        'source_series_id' => '999',
        'is_custom' => false,
    ]);

    $longTitle = 'Some Show - S01E01'.str_repeat('-1234', 100).' - The Finale';
    expect(strlen($longTitle))->toBeGreaterThan(255);

    Http::preventStrayRequests();
    Http::fake([
        '*action=get_series_info*' => Http::response([
            'info' => ['name' => $series->name],
            'seasons' => [],
            'episodes' => [
                '1' => [
                    [
                        'id' => 111,
                        'episode_num' => 1,
                        'title' => $longTitle,
                        'container_extension' => 'mp4',
                        'info' => [],
                    ],
                ],
            ],
        ]),
    ]);

    $result = $series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false);

    expect($result)->toBeTrue();

    $episode = $series->episodes()->first();
    expect($episode)->not->toBeNull();
    expect(strlen($episode->title))->toBeLessThanOrEqual(255);
});
