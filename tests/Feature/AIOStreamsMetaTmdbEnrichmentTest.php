<?php

use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use App\Services\AIOStreamsService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.stremio.meta_addons', [
        'cinemeta' => 'https://v3-cinemeta.strem.io',
        'kitsu' => 'https://anime-kitsu.strem.fun',
        'tmdb' => '',
    ]);

    $settings = new GeneralSettings;
    $settings->tmdb_api_key = 'fake-api-key';
    $settings->tmdb_language = 'en-US';
    $settings->tmdb_rate_limit = 40;
    $settings->aiostreams_rate_limit = 20;
    app()->instance(GeneralSettings::class, $settings);

    RateLimiter::shouldReceive('tooManyAttempts')->andReturnFalse();
    RateLimiter::shouldReceive('hit')->andReturn(1);
    RateLimiter::shouldReceive('availableIn')->andReturn(0);

    Cache::flush();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/abc/manifest.json',
        'playlist_id' => $this->playlist->id,
        'aiostreams_meta_id_prefixes' => ['*'],
    ]);
    $this->playlist->update(['aiostreams_integration_id' => $this->integration->id]);
});

function fakeTmdbMovie(): array
{
    return [
        'id' => 603,
        'title' => 'The Matrix',
        'imdb_id' => 'tt0133093',
        'overview' => 'A computer hacker learns the truth.',
        'runtime' => 136,
        'genres' => [['name' => 'Action'], ['name' => 'Science Fiction']],
        'credits' => [
            'cast' => [
                ['id' => 6384, 'name' => 'Keanu Reeves', 'character' => 'Neo', 'profile_path' => '/keanu.jpg'],
                ['id' => 2975, 'name' => 'Laurence Fishburne', 'character' => 'Morpheus', 'profile_path' => null],
            ],
            'crew' => [['job' => 'Director', 'name' => 'Lana Wachowski']],
        ],
        'images' => [
            'logos' => [
                ['file_path' => '/matrix-logo.png', 'iso_639_1' => 'en', 'vote_average' => 5.0],
            ],
        ],
        'videos' => ['results' => []],
        'external_ids' => ['imdb_id' => 'tt0133093'],
    ];
}

function fakeTmdbSeries(): array
{
    return [
        'id' => 1399,
        'name' => 'Game of Thrones',
        'overview' => 'Nine noble families fight for control.',
        'genres' => [['name' => 'Sci-Fi & Fantasy']],
        'number_of_seasons' => 2,
        'credits' => [
            'cast' => [
                ['id' => 22970, 'name' => 'Peter Dinklage', 'character' => 'Tyrion Lannister', 'profile_path' => '/peter.jpg'],
            ],
            'crew' => [],
        ],
        'images' => [
            'logos' => [
                ['file_path' => '/got-logo.png', 'iso_639_1' => 'en', 'vote_average' => 8.0],
            ],
        ],
        'videos' => ['results' => []],
        'external_ids' => ['imdb_id' => 'tt0944947'],
        'seasons' => [
            [
                'season_number' => 1,
                'name' => 'Season 1',
                'overview' => 'The first season.',
                'episode_count' => 10,
                'air_date' => '2011-04-17',
                'poster_path' => '/got-s1.jpg',
            ],
            [
                'season_number' => 2,
                'name' => 'Season 2',
                'overview' => '',
                'episode_count' => 10,
                'air_date' => '2012-04-01',
                'poster_path' => null,
            ],
        ],
    ];
}

it('enriches a movie meta object with TMDB cast_list and clearlogo', function () {
    Http::fake([
        'aiostreams.test/abc/meta/movie/tmdb:603.json*' => Http::response([
            'meta' => ['id' => 'tmdb:603', 'type' => 'movie', 'name' => 'The Matrix'],
        ], 200),
        'api.themoviedb.org/3/movie/603*' => Http::response(fakeTmdbMovie(), 200),
    ]);

    $meta = AIOStreamsService::make($this->integration)->fetchMeta('movie', 'tmdb:603')['meta'];

    expect($meta['name'])->toBe('The Matrix');
    expect($meta['clearlogo'])->toBe('https://image.tmdb.org/t/p/w500/matrix-logo.png');
    expect($meta['cast_list'])->toHaveCount(2);
    expect($meta['cast_list'][0])->toMatchArray([
        'id' => 6384,
        'name' => 'Keanu Reeves',
        'character' => 'Neo',
        'photo' => 'https://image.tmdb.org/t/p/w185/keanu.jpg',
    ]);
    expect($meta)->not->toHaveKey('seasons');
});

it('resolves an imdb id to tmdb and adds shaped season metadata for a series', function () {
    Http::fake([
        'aiostreams.test/abc/meta/series/tt0944947.json*' => Http::response([
            'meta' => ['id' => 'tt0944947', 'type' => 'series', 'name' => 'Game of Thrones'],
        ], 200),
        'api.themoviedb.org/3/find/tt0944947*' => Http::response([
            'tv_results' => [['id' => 1399, 'popularity' => 100]],
            'movie_results' => [],
        ], 200),
        'api.themoviedb.org/3/tv/1399*' => Http::response(fakeTmdbSeries(), 200),
    ]);

    $meta = AIOStreamsService::make($this->integration)->fetchMeta('series', 'tt0944947')['meta'];

    expect($meta['clearlogo'])->toBe('https://image.tmdb.org/t/p/w500/got-logo.png');
    expect($meta['cast_list'][0]['name'])->toBe('Peter Dinklage');
    expect($meta['seasons'])->toHaveCount(2);
    expect($meta['seasons'][0])->toMatchArray([
        'season_number' => 1,
        'name' => 'Season 1',
        'overview' => 'The first season.',
        'episode_count' => 10,
        'air_date' => '2011-04-17',
        'cover_big' => 'https://image.tmdb.org/t/p/w500/got-s1.jpg',
    ]);
    // Season 2 has no poster and a blank overview - both dropped by array_filter.
    expect($meta['seasons'][1])->not->toHaveKey('cover_big');
    expect($meta['seasons'][1])->not->toHaveKey('overview');
});

it('shapes TMDB recommendations into related items on a movie meta', function () {
    $movie = fakeTmdbMovie();
    $movie['recommendations'] = [
        'results' => [
            ['id' => 680, 'title' => 'Pulp Fiction', 'poster_path' => '/pulp.jpg'],
            ['id' => 155, 'title' => 'The Dark Knight', 'poster_path' => null],
        ],
    ];

    Http::fake([
        'aiostreams.test/abc/meta/movie/tmdb:603.json*' => Http::response([
            'meta' => ['id' => 'tmdb:603', 'type' => 'movie', 'name' => 'The Matrix'],
        ], 200),
        'api.themoviedb.org/3/movie/603*' => Http::response($movie, 200),
    ]);

    $meta = AIOStreamsService::make($this->integration)->fetchMeta('movie', 'tmdb:603')['meta'];

    expect($meta['related'])->toHaveCount(2);
    expect($meta['related'][0])->toMatchArray([
        'id' => 'tmdb:680',
        'type' => 'movie',
        'name' => 'Pulp Fiction',
        'poster' => 'https://image.tmdb.org/t/p/w342/pulp.jpg',
    ]);
    // No poster - key dropped rather than emitted null.
    expect($meta['related'][1])->not->toHaveKey('poster');
});

it('omits related when TMDB returns no recommendations', function () {
    Http::fake([
        'aiostreams.test/abc/meta/movie/tmdb:603.json*' => Http::response([
            'meta' => ['id' => 'tmdb:603', 'type' => 'movie', 'name' => 'The Matrix'],
        ], 200),
        'api.themoviedb.org/3/movie/603*' => Http::response(fakeTmdbMovie(), 200),
    ]);

    $meta = AIOStreamsService::make($this->integration)->fetchMeta('movie', 'tmdb:603')['meta'];

    expect($meta)->not->toHaveKey('related');
});

it('does not enrich or call TMDB when the integration toggle is off', function () {
    $this->integration->update(['aiostreams_tmdb_enrich' => false]);

    Http::fake([
        'aiostreams.test/abc/meta/movie/tmdb:603.json*' => Http::response([
            'meta' => ['id' => 'tmdb:603', 'type' => 'movie', 'name' => 'The Matrix'],
        ], 200),
    ]);

    $meta = AIOStreamsService::make($this->integration)->fetchMeta('movie', 'tmdb:603')['meta'];

    expect($meta)->not->toHaveKey('cast_list');
    expect($meta)->not->toHaveKey('clearlogo');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'themoviedb.org'));
});

it('does not enrich when no TMDB API key is configured', function () {
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = null;
    $settings->aiostreams_rate_limit = 20;
    app()->instance(GeneralSettings::class, $settings);

    Http::fake([
        'aiostreams.test/abc/meta/movie/tmdb:603.json*' => Http::response([
            'meta' => ['id' => 'tmdb:603', 'type' => 'movie', 'name' => 'The Matrix'],
        ], 200),
    ]);

    $meta = AIOStreamsService::make($this->integration)->fetchMeta('movie', 'tmdb:603')['meta'];

    expect($meta)->not->toHaveKey('cast_list');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'themoviedb.org'));
});

it('routes enriched cast photos and clearlogo through the logo proxy when enabled', function () {
    $this->playlist->update(['enable_logo_proxy' => true]);

    Http::fake([
        'aiostreams.test/abc/meta/movie/tmdb:603.json*' => Http::response([
            'meta' => ['id' => 'tmdb:603', 'type' => 'movie', 'name' => 'The Matrix'],
        ], 200),
        'api.themoviedb.org/3/movie/603*' => Http::response(fakeTmdbMovie(), 200),
    ]);

    $response = $this->get("/{$this->user->name}/{$this->playlist->uuid}/aiostreams/{$this->integration->id}/meta/movie/tmdb:603.json");

    $response->assertOk();
    expect($response->json('meta.clearlogo'))->toContain('/logo-proxy/');
    expect($response->json('meta.cast_list.0.photo'))->toContain('/logo-proxy/');
    // A cast member with no TMDB photo stays null (not a proxied placeholder here).
    expect($response->json('meta.cast_list.1.photo'))->toBeNull();
});
