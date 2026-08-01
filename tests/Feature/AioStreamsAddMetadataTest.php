<?php

use App\Livewire\AioStreamsBrowse;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);

    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);

    Http::fake([
        '*/stream/movie/tt1.json' => Http::response(['streams' => []]),
    ]);
});

it('populates full metadata (not just plot) when adding a movie to the library', function () {
    Http::fake([
        '*/meta/movie/tt1.json' => Http::response([
            'meta' => [
                'id' => 'tt1',
                'name' => 'Test Movie',
                'poster' => 'https://x/poster.jpg',
                'background' => 'https://x/backdrop.jpg',
                'description' => 'A thrilling test movie.',
                'genres' => ['Action', 'Sci-Fi'],
                'imdbRating' => '8.4',
                'runtime' => '128 min',
                'director' => ['Jane Director'],
                'cast' => ['Actor One', ['name' => 'Actor Two']],
                'releaseInfo' => '2024',
            ],
        ]),
        '*/stream/movie/tt1.json' => Http::response(['streams' => []]),
    ]);

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'movie', 'tt1')
        ->call('addMovieToLibrary');

    $channel = Channel::where('aio_item_id', 'tt1')->firstOrFail();

    expect($channel->rating)->toEqual(8.4)
        ->and((float) $channel->rating_5based)->toBe(4.2)
        ->and($channel->info['genre'])->toBe('Action, Sci-Fi')
        ->and($channel->info['director'])->toBe('Jane Director')
        ->and($channel->info['cast'])->toBe('Actor One, Actor Two')
        ->and($channel->info['episode_run_time'])->toBe(128)
        ->and($channel->info['movie_image'])->toBe('https://x/poster.jpg')
        ->and($channel->info['backdrop_path'])->toBe(['https://x/backdrop.jpg'])
        ->and($channel->info['plot'])->toBe('A thrilling test movie.')
        ->and($channel->can_merge)->toBeFalsy()
        ->and($channel->probe_enabled)->toBeFalse();
});

it('populates full metadata when adding a series to the library', function () {
    Http::fake([
        '*/meta/series/tt2.json' => Http::response([
            'meta' => [
                'id' => 'tt2',
                'name' => 'Test Series',
                'poster' => 'https://x/poster.jpg',
                'background' => 'https://x/backdrop.jpg',
                'description' => 'A gripping test series.',
                'genres' => ['Drama'],
                'imdbRating' => '9.0',
                'director' => ['Show Runner'],
                'cast' => ['Lead Actor'],
                'releaseInfo' => '2023',
                'videos' => [],
            ],
        ]),
    ]);

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'series', 'tt2')
        ->call('addSeriesToLibrary');

    $series = Series::where('aio_item_id', 'tt2')->firstOrFail();

    expect($series->genre)->toBe('Drama')
        ->and($series->director)->toBe('Show Runner')
        ->and($series->cast)->toBe('Lead Actor')
        ->and($series->plot)->toBe('A gripping test series.')
        ->and($series->cover)->toBe('https://x/poster.jpg')
        ->and($series->backdrop_path)->toBe(['https://x/backdrop.jpg']);
});

it('populates season poster (falling back to the series poster) and episode metadata when adding an episode', function () {
    Http::fake([
        '*/meta/series/tt3.json' => Http::response([
            'meta' => [
                'id' => 'tt3',
                'name' => 'Test Series',
                'poster' => 'https://x/series-poster.jpg',
                'videos' => [
                    [
                        'season' => 1,
                        'episode' => 1,
                        'title' => 'Pilot',
                        'overview' => 'The one where it all begins.',
                        'thumbnail' => 'https://x/ep1-thumb.jpg',
                        'released' => '2020-01-01T00:00:00.000Z',
                    ],
                ],
            ],
        ]),
        '*/stream/series/tt3:1:1.json' => Http::response(['streams' => []]),
    ]);

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'series', 'tt3')
        ->call('addEpisodeToLibrary', 1, 1);

    $series = Series::where('aio_item_id', 'tt3')->firstOrFail();
    $season = $series->seasons()->where('season_number', 1)->firstOrFail();
    $episode = Episode::where('aio_item_id', 'tt3:1:1')->firstOrFail();

    expect($season->cover)->toBe('https://x/series-poster.jpg')
        ->and($season->cover_big)->toBe('https://x/series-poster.jpg')
        ->and($episode->title)->toBe('Pilot')
        ->and($episode->plot)->toBe('The one where it all begins.')
        ->and($episode->cover)->toBe('https://x/ep1-thumb.jpg')
        ->and($episode->info['plot'])->toBe('The one where it all begins.')
        ->and($episode->info['movie_image'])->toBe('https://x/ep1-thumb.jpg')
        ->and($episode->info['cover_big'])->toBe('https://x/ep1-thumb.jpg')
        ->and($episode->enabled)->toBeTrue()
        ->and($episode->aio_resolution_status)->toBe('pending')
        ->and($episode->probe_enabled)->toBeFalse();
});

it('adds a future/unaired episode disabled and scheduled, without resolving it', function () {
    Http::fake([
        '*/meta/series/tt4.json' => Http::response([
            'meta' => [
                'id' => 'tt4',
                'name' => 'Test Series',
                'poster' => 'https://x/series-poster.jpg',
                'videos' => [
                    [
                        'season' => 1,
                        'episode' => 1,
                        'title' => 'Future Episode',
                        'released' => now()->addYear()->toIso8601String(),
                    ],
                ],
            ],
        ]),
    ]);

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'series', 'tt4')
        ->call('addEpisodeToLibrary', 1, 1);

    $episode = Episode::where('aio_item_id', 'tt4:1:1')->firstOrFail();

    expect($episode->enabled)->toBeFalse()
        ->and($episode->aio_resolution_status)->toBe('scheduled');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/stream/series/tt4'));
});

it('populates imdb_id from the item id when adding a movie to the library', function () {
    Http::fake([
        '*/meta/movie/tt1.json' => Http::response([
            'meta' => ['id' => 'tt1', 'name' => 'Test Movie'],
        ]),
    ]);

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'movie', 'tt1')
        ->call('addMovieToLibrary');

    $channel = Channel::where('aio_item_id', 'tt1')->firstOrFail();

    expect($channel->imdb_id)->toBe('tt1')
        ->and($channel->tmdb_id)->toBeNull();
});

it('populates tmdb_id from a tmdb-prefixed item id, falling back to meta cross-reference fields', function () {
    Http::fake([
        '*/meta/movie/tmdb:603.json' => Http::response([
            'meta' => ['id' => 'tmdb:603', 'name' => 'Test Movie', 'imdb_id' => 'tt0133093'],
        ]),
        '*/stream/movie/tmdb:603.json' => Http::response(['streams' => []]),
    ]);

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'movie', 'tmdb:603')
        ->call('addMovieToLibrary');

    $channel = Channel::where('aio_item_id', 'tmdb:603')->firstOrFail();

    expect($channel->tmdb_id)->toBe(603)
        ->and($channel->imdb_id)->toBe('tt0133093');
});

it('populates imdb_id/tmdb_id from meta links when neither is present in the item id', function () {
    Http::fake([
        '*/meta/series/kitsu:1.json' => Http::response([
            'meta' => [
                'id' => 'kitsu:1',
                'name' => 'Test Series',
                'videos' => [],
                'links' => [
                    ['name' => 'tt9999999', 'category' => 'imdb', 'url' => 'https://imdb.com/title/tt9999999'],
                    ['name' => '12345', 'category' => 'tmdb', 'url' => 'https://themoviedb.org/tv/12345'],
                ],
            ],
        ]),
    ]);

    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'series', 'kitsu:1')
        ->call('addSeriesToLibrary');

    $series = Series::where('aio_item_id', 'kitsu:1')->firstOrFail();

    expect($series->imdb_id)->toBe('tt9999999')
        ->and($series->tmdb_id)->toBe(12345);
});
