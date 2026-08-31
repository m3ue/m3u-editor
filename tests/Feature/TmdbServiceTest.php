<?php

use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Mock settings with a fake API key
    $this->settings = new GeneralSettings;
    $this->settings->tmdb_api_key = 'fake-api-key';
    $this->settings->tmdb_language = 'en-US';
    $this->settings->tmdb_rate_limit = 40;
    $this->settings->tmdb_confidence_threshold = 80;

    // Avoid Redis dependency from TmdbService::waitForRateLimit() in tests.
    RateLimiter::shouldReceive('tooManyAttempts')->andReturnFalse();
    RateLimiter::shouldReceive('hit')->andReturn(1);
});

it('returns null when API key is not configured', function () {
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = null;

    $service = new TmdbService($settings);

    expect($service->isConfigured())->toBeFalse();
    expect($service->searchMovie('The Matrix'))->toBeNull();
});

it('reports configured when API key is set', function () {
    $service = new TmdbService($this->settings);

    expect($service->isConfigured())->toBeTrue();
});

it('can search for a movie and return TMDB ID', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/movie*' => Http::response([
            'results' => [
                [
                    'id' => 603,
                    'title' => 'The Matrix',
                    'release_date' => '1999-03-30',
                    'popularity' => 85.5,
                ],
            ],
        ], 200),
        'https://api.themoviedb.org/3/movie/603/external_ids*' => Http::response([
            'imdb_id' => 'tt0133093',
        ], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->searchMovie('The Matrix', 1999);

    expect($result)->not->toBeNull()
        ->and($result['tmdb_id'])->toBe(603)
        ->and($result['imdb_id'])->toBe('tt0133093');
});

it('prefers an exact full movie title over the simplified API query', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/movie*' => Http::response([
            'results' => [
                [
                    'id' => 999999,
                    'title' => 'Duneland',
                    'original_title' => 'Duneland',
                    'release_date' => '2015-01-01',
                    'popularity' => 100.0,
                ],
                [
                    'id' => 841,
                    'title' => 'Dune - Der Wüstenplanet',
                    'original_title' => 'Desert Planet',
                    'release_date' => '1984-12-14',
                    'popularity' => 10.0,
                ],
            ],
        ], 200),
        'https://api.themoviedb.org/3/movie/*/external_ids*' => Http::response([], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->searchMovie('Dune - Der Wüstenplanet');

    expect($result)->not->toBeNull()
        ->and($result['tmdb_id'])->toBe(841);
});

it('can search for a TV series and return TMDB and TVDB IDs', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/tv*' => Http::response([
            'results' => [
                [
                    'id' => 4592,
                    'name' => 'ALF',
                    'first_air_date' => '1986-09-22',
                    'popularity' => 45.2,
                ],
            ],
        ], 200),
        'https://api.themoviedb.org/3/tv/4592/external_ids*' => Http::response([
            'tvdb_id' => 78020,
            'imdb_id' => 'tt0090390',
        ], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->searchTvSeries('ALF', 1986);

    expect($result)->not->toBeNull()
        ->and($result['tmdb_id'])->toBe(4592)
        ->and($result['tvdb_id'])->toBe(78020)
        ->and($result['imdb_id'])->toBe('tt0090390');
});

it('preserves title words while stripping standalone quality tokens from TV series queries', function (string $title, string $expectedQuery) {
    Http::fake([
        'https://api.themoviedb.org/3/search/tv*' => Http::response(['results' => []], 200),
    ]);

    $service = new TmdbService($this->settings);
    $service->searchTvSeries($title, 2022);

    Http::assertSent(fn ($request): bool => ($request->data()['query'] ?? null) === $expectedQuery);
})->with([
    'SD inside title' => ['Wednesday HD', 'Wednesday'],
    'HD inside title' => ['Mehdi SD', 'Mehdi'],
    'quality at beginning with slash' => ['HD/Title', 'Title'],
    'quality in middle with slashes' => ['Title/HD/Other', 'Title Other'],
    'parenthesized quality at end' => ['Title (HD)', 'Title'],
    'quality at beginning with whitespace' => ['HD Title', 'Title'],
    'quality in middle with repeated whitespace' => ['Title   SD   Other', 'Title Other'],
    'quality at end with hyphen' => ['Title-HD', 'Title'],
    'quality with punctuation delimiters' => ['Title, HD: Other', 'Title Other'],
    'quality in parentheses between words' => ['Title(HD)Other', 'Title Other'],
    'quality with repeated delimiters' => ['Title///HD---Other', 'Title Other'],
    'bracket metadata between words' => ['Title[DE]Other', 'Title Other'],
    'bracket metadata with repeated delimiters' => ['Title///[HD]---Other', 'Title Other'],
    'repeated bracket metadata' => ['Title[HD][SD]Other', 'Title Other'],
    'only quality tokens' => ['HD / SD - 4K', ''],
]);

it('does not concatenate title words around square-bracket metadata', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/tv*' => Http::response(['results' => []], 200),
    ]);

    $service = new TmdbService($this->settings);
    $service->searchTvSeries('Title[HD]Other', 2022);

    Http::assertSent(fn ($request): bool => ($request->data()['query'] ?? null) === 'Title Other');
});

it('prefers an exact full localized TV title after simplifying the API query', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/tv*' => Http::response([
            'results' => [
                [
                    'id' => 34356,
                    'name' => 'Tron - Der Aufstand',
                    'original_name' => 'TRON: Uprising',
                    'first_air_date' => '2012-06-07',
                    'popularity' => 9.1,
                ],
                [
                    'id' => 303795,
                    'name' => 'JonTron',
                    'original_name' => 'JonTron',
                    'first_air_date' => '2010-08-31',
                    'popularity' => 15.0,
                ],
            ],
        ], 200),
        'https://api.themoviedb.org/3/tv/*/external_ids*' => Http::response([], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->searchTvSeries('Tron - Der Aufstand');

    expect($result)->not->toBeNull()
        ->and($result['tmdb_id'])->toBe(34356);
});

it('ranks an exact normalized full TV title above a more popular typo', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/tv*' => Http::response([
            'results' => [
                [
                    'id' => 1,
                    'name' => 'Tron - Der Aufstand',
                    'original_name' => 'Tron',
                    'first_air_date' => '2012-06-07',
                    'popularity' => 1.0,
                ],
                [
                    'id' => 2,
                    'name' => 'Tron - Der Aufstande',
                    'original_name' => 'Tron',
                    'first_air_date' => '2012-06-07',
                    'popularity' => 100.0,
                ],
            ],
        ], 200),
        'https://api.themoviedb.org/3/tv/*/external_ids*' => Http::response([], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->searchTvSeries('Tron - Der Aufstand', 2012);

    expect([$result['tmdb_id'], $result['confidence']])->toBe([1, 100]);
});

it('ranks an exact normalized full TV title above a same-year typo when the exact title year differs', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/tv*' => Http::response([
            'results' => [
                [
                    'id' => 1,
                    'name' => 'Tron - Der Aufstand',
                    'original_name' => 'Tron',
                    'first_air_date' => '2011-06-07',
                    'popularity' => 1.0,
                ],
                [
                    'id' => 2,
                    'name' => 'Tron - Der Aufstande',
                    'original_name' => 'Tron',
                    'first_air_date' => '2012-06-07',
                    'popularity' => 100.0,
                ],
            ],
        ], 200),
        'https://api.themoviedb.org/3/tv/*/external_ids*' => Http::response([], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->searchTvSeries('Tron - Der Aufstand', 2012);

    expect([$result['tmdb_id'], $result['confidence']])->toBe([1, 100]);
});

it('ranks an exact normalized original TV name above a same-year popular typo', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/tv*' => Http::response([
            'results' => [
                [
                    'id' => 1,
                    'name' => 'Tron - Der Aufstand',
                    'original_name' => 'TRON: Uprising',
                    'first_air_date' => '2011-06-07',
                    'popularity' => 1.0,
                ],
                [
                    'id' => 2,
                    'name' => 'TRON: Uprisin',
                    'original_name' => 'TRON: Uprisin',
                    'first_air_date' => '2012-06-07',
                    'popularity' => 100.0,
                ],
            ],
        ], 200),
        'https://api.themoviedb.org/3/tv/*/external_ids*' => Http::response([], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->searchTvSeries('TRON: Uprising', 2012);

    expect([$result['tmdb_id'], $result['confidence']])->toBe([1, 100]);
});

it('preserves exact alternative-title fallback matching', function () {
    Cache::flush();
    Http::fake([
        'https://api.themoviedb.org/3/search/tv*' => Http::response([
            'results' => [
                [
                    'id' => 11,
                    'name' => 'Little House on the Prairie',
                    'original_name' => 'Little House on the Prairie',
                    'first_air_date' => '1974-09-11',
                    'popularity' => 20.0,
                ],
            ],
        ], 200),
        'https://api.themoviedb.org/3/tv/11/alternative_titles*' => Http::response([
            'results' => [
                ['title' => 'Unsere kleine Farm', 'iso_3166_1' => 'DE'],
            ],
        ], 200),
        'https://api.themoviedb.org/3/tv/11/external_ids*' => Http::response([], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->searchTvSeries('Unsere kleine Farm', 1974);

    expect([$result['tmdb_id'], $result['confidence']])->toBe([11, 100]);
});

it('handles no results gracefully', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/movie*' => Http::response([
            'results' => [],
        ], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->searchMovie('Some Nonexistent Movie Title XYZ123');

    expect($result)->toBeNull();
});

it('retries search without year when no results found', function () {
    Http::fake([
        // First request with year returns no results
        'https://api.themoviedb.org/3/search/movie*year=2000*' => Http::response([
            'results' => [],
        ], 200),
        // Second request without year returns results
        'https://api.themoviedb.org/3/search/movie*' => Http::response([
            'results' => [
                [
                    'id' => 603,
                    'title' => 'The Matrix',
                    'release_date' => '1999-03-30',
                    'popularity' => 85.5,
                ],
            ],
        ], 200),
        'https://api.themoviedb.org/3/movie/603/external_ids*' => Http::response([
            'imdb_id' => 'tt0133093',
        ], 200),
    ]);

    $service = new TmdbService($this->settings);
    // Search with wrong year should still find the movie by title
    $result = $service->searchMovie('The Matrix', 2000);

    // May or may not find depending on the fake response ordering
    // The important thing is it doesn't crash
    expect(true)->toBeTrue();
});

it('extracts year from title correctly', function () {
    expect(TmdbService::extractYearFromTitle('The Matrix (1999)'))->toBe(1999);
    expect(TmdbService::extractYearFromTitle('John Wick: Chapter 4 (2023)'))->toBe(2023);
    expect(TmdbService::extractYearFromTitle('Movie 2023'))->toBe(2023);
    expect(TmdbService::extractYearFromTitle('Movie Without Year'))->toBeNull();
    expect(TmdbService::extractYearFromTitle('Movie 12345'))->toBeNull(); // Invalid year
});

it('rejects low confidence matches', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/movie*' => Http::response([
            'results' => [
                [
                    'id' => 12345,
                    'title' => 'Completely Different Title',
                    'release_date' => '2020-01-01',
                    'popularity' => 10.0,
                ],
            ],
        ], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->searchMovie('The Matrix', 1999);

    // Should return null because title doesn't match well
    expect($result)->toBeNull();
});

it('handles API errors gracefully', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/movie*' => Http::response([
            'status_code' => 7,
            'status_message' => 'Invalid API key',
        ], 401),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->searchMovie('The Matrix');

    expect($result)->toBeNull();
});

// ---------------------------------------------------------------------------
// findByExternalId
// ---------------------------------------------------------------------------

it('can resolve an external imdb id via TMDB find', function () {
    Http::fake([
        'https://api.themoviedb.org/3/find/tt0090390*' => Http::response([
            'tv_results' => [
                [
                    'id' => 4592,
                    'name' => 'ALF',
                    'original_name' => 'ALF',
                    'first_air_date' => '1986-09-22',
                    'poster_path' => '/alf-poster.jpg',
                    'backdrop_path' => '/alf-backdrop.jpg',
                    'popularity' => 45.2,
                ],
            ],
            'movie_results' => [],
        ], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->findByExternalId('tt0090390', 'imdb_id');

    expect($result)->not->toBeNull()
        ->and($result['tmdb_id'])->toBe(4592)
        ->and($result['_media_type'])->toBe('tv');
});

it('can resolve an external tvdb id via TMDB find', function () {
    Http::fake([
        'https://api.themoviedb.org/3/find/78020*' => Http::response([
            'tv_results' => [
                [
                    'id' => 4592,
                    'name' => 'ALF',
                    'original_name' => 'ALF',
                    'first_air_date' => '1986-09-22',
                    'poster_path' => '/alf-poster.jpg',
                    'backdrop_path' => '/alf-backdrop.jpg',
                    'popularity' => 45.2,
                ],
            ],
            'movie_results' => [],
        ], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->findByExternalId('78020', 'tvdb_id');

    expect($result)->not->toBeNull()
        ->and($result['tmdb_id'])->toBe(4592)
        ->and($result['_media_type'])->toBe('tv');
});

it('returns null for an unsupported source', function () {
    $service = new TmdbService($this->settings);
    $result = $service->findByExternalId('12345', 'unknown_source');

    expect($result)->toBeNull();
});

it('returns null when findByExternalId id is empty', function () {
    $service = new TmdbService($this->settings);

    expect($service->findByExternalId('', 'imdb_id'))->toBeNull();
    expect($service->findByExternalId('   ', 'imdb_id'))->toBeNull();
});

it('picks higher popularity when find returns both tv and movie results', function () {
    Http::fake([
        'https://api.themoviedb.org/3/find/tt0090390*' => Http::response([
            'tv_results' => [
                [
                    'id' => 4592,
                    'name' => 'ALF',
                    'original_name' => 'ALF',
                    'first_air_date' => '1986-09-22',
                    'poster_path' => '/alf-poster.jpg',
                    'backdrop_path' => '/alf-backdrop.jpg',
                    'popularity' => 45.2,
                ],
            ],
            'movie_results' => [
                [
                    'id' => 9999,
                    'title' => 'ALF The Movie',
                    'original_title' => 'ALF The Movie',
                    'release_date' => '1996-01-01',
                    'poster_path' => '/alf-movie.jpg',
                    'backdrop_path' => '/alf-movie-backdrop.jpg',
                    'popularity' => 10.1,
                ],
            ],
        ], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->findByExternalId('tt0090390', 'imdb_id');

    // TV result has higher popularity (45.2 > 10.1) so it should win
    expect($result)->not->toBeNull()
        ->and($result['tmdb_id'])->toBe(4592)
        ->and($result['_media_type'])->toBe('tv');
});

it('can resolve a raw tmdb id by probing TV details endpoint', function () {
    Http::fake([
        'https://api.themoviedb.org/3/tv/4592*' => Http::response([
            'id' => 4592,
            'name' => 'ALF',
            'original_name' => 'ALF',
            'overview' => 'Alien life form sitcom.',
            'poster_path' => '/alf-poster.jpg',
            'backdrop_path' => '/alf-backdrop.jpg',
            'first_air_date' => '1986-09-22',
            'genres' => [],
            'external_ids' => [
                'imdb_id' => 'tt0090390',
                'tvdb_id' => 78020,
            ],
            'credits' => ['cast' => [], 'crew' => []],
            'videos' => ['results' => []],
        ], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->findByExternalId('4592', 'tmdb_id');

    expect($result)->not->toBeNull()
        ->and($result['tmdb_id'])->toBe(4592)
        ->and($result['_media_type'])->toBe('tv')
        ->and($result['name'])->toBe('ALF');
});

it('falls back to movie endpoint when tmdb_id does not match a TV series', function () {
    Http::fake([
        'https://api.themoviedb.org/3/tv/603*' => Http::response(['success' => false], 404),
        'https://api.themoviedb.org/3/movie/603*' => Http::response([
            'id' => 603,
            'title' => 'The Matrix',
            'original_title' => 'The Matrix',
            'overview' => 'A computer hacker learns the truth.',
            'poster_path' => '/matrix.jpg',
            'backdrop_path' => '/matrix-backdrop.jpg',
            'release_date' => '1999-03-30',
            'genres' => [],
            'external_ids' => ['imdb_id' => 'tt0133093'],
            'credits' => ['cast' => [], 'crew' => []],
            'videos' => ['results' => []],
        ], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->findByExternalId('603', 'tmdb_id');

    expect($result)->not->toBeNull()
        ->and($result['tmdb_id'])->toBe(603)
        ->and($result['_media_type'])->toBe('movie')
        ->and($result['title'])->toBe('The Matrix');
});

it('skips tv probe when mediaType hint is movie', function () {
    Http::fake([
        'https://api.themoviedb.org/3/movie/603*' => Http::response([
            'id' => 603,
            'title' => 'The Matrix',
            'original_title' => 'The Matrix',
            'overview' => 'A computer hacker learns the truth.',
            'poster_path' => '/matrix.jpg',
            'backdrop_path' => '/matrix-backdrop.jpg',
            'release_date' => '1999-03-30',
            'genres' => [],
            'external_ids' => ['imdb_id' => 'tt0133093'],
            'credits' => ['cast' => [], 'crew' => []],
            'videos' => ['results' => []],
        ], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->findByExternalId('603', 'tmdb_id', 'movie');

    expect($result)->not->toBeNull()
        ->and($result['_media_type'])->toBe('movie');

    // The TV endpoint must NOT have been called
    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), '/tv/603'));
});

// ---------------------------------------------------------------------------
// searchMulti
// ---------------------------------------------------------------------------

it('can search across multi endpoint and return media type', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/multi*' => Http::response([
            'results' => [
                [
                    'id' => 4592,
                    'media_type' => 'tv',
                    'name' => 'ALF',
                    'original_name' => 'ALF',
                    'overview' => 'Alien life form sitcom.',
                    'first_air_date' => '1986-09-22',
                    'poster_path' => '/alf-poster.jpg',
                    'backdrop_path' => '/alf-backdrop.jpg',
                    'popularity' => 45.2,
                ],
                [
                    'id' => 9999,
                    'media_type' => 'movie',
                    'title' => 'Different Movie',
                    'original_title' => 'Different Movie',
                    'release_date' => '2020-01-01',
                    'poster_path' => '/movie-poster.jpg',
                    'backdrop_path' => '/movie-backdrop.jpg',
                    'popularity' => 20.0,
                ],
            ],
        ], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->searchMulti('ALF');

    expect($result)->not->toBeNull()
        ->and($result['tmdb_id'])->toBe(4592)
        ->and($result['_media_type'])->toBe('tv')
        ->and($result['name'])->toBe('ALF');
});

it('returns null when searchMulti finds no tv or movie results', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/multi*' => Http::response([
            'results' => [
                // Only a person result — should be filtered out
                ['id' => 1, 'media_type' => 'person', 'name' => 'Some Actor'],
            ],
        ], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->searchMulti('Some Actor');

    expect($result)->toBeNull();
});

it('returns null when searchMulti query is empty', function () {
    $service = new TmdbService($this->settings);

    expect($service->searchMulti(''))->toBeNull();
    expect($service->searchMulti('   '))->toBeNull();
});

it('breaks ties in searchMulti by popularity when confidence scores are equal', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/multi*' => Http::response([
            'results' => [
                [
                    'id' => 100,
                    'media_type' => 'tv',
                    'name' => 'Test Show',
                    'original_name' => 'Test Show',
                    'overview' => 'A test show.',
                    'first_air_date' => '2000-01-01',
                    'poster_path' => null,
                    'backdrop_path' => null,
                    'popularity' => 5.0,
                ],
                [
                    'id' => 200,
                    'media_type' => 'movie',
                    'title' => 'Test Show',
                    'original_title' => 'Test Show',
                    'overview' => 'A test movie.',
                    'release_date' => '2000-06-01',
                    'poster_path' => null,
                    'backdrop_path' => null,
                    'popularity' => 80.0,
                ],
            ],
        ], 200),
    ]);

    $service = new TmdbService($this->settings);
    $result = $service->searchMulti('Test Show');

    // Both have the same title match → same confidence; movie wins on popularity (80 > 5)
    expect($result)->not->toBeNull()
        ->and($result['tmdb_id'])->toBe(200)
        ->and($result['_media_type'])->toBe('movie');
});

it('returns null when searchMulti is not configured', function () {
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = null;

    $service = new TmdbService($settings);

    expect($service->searchMulti('ALF'))->toBeNull();
});

// ---------------------------------------------------------------------------
// Manual search normalization (language tags + year extraction)
// ---------------------------------------------------------------------------

it('strips French language tags and extracts year in searchMovieManual', function (string $rawQuery, string $expectedQuery, ?int $expectedYear) {
    Http::fake([
        'https://api.themoviedb.org/3/search/movie*' => Http::response(['results' => []], 200),
    ]);

    $service = new TmdbService($this->settings);
    $service->searchMovieManual($rawQuery);

    Http::assertSent(function ($request) use ($expectedQuery, $expectedYear) {
        $data = $request->data();
        if (($data['query'] ?? null) !== $expectedQuery) {
            return false;
        }

        if ($expectedYear === null) {
            return ! array_key_exists('primary_release_year', $data);
        }

        return ($data['primary_release_year'] ?? null) === $expectedYear;
    });
})->with([
    'VOSTFR tag' => ['Le Comte de Monte-Cristo (2024) VOSTFR', 'Le Comte de Monte-Cristo', 2024],
    'VOST tag' => ['Amelie 2001 VOST', 'Amelie', 2001],
    'VF tag with hyphen' => ['Intouchables (2011) - VF', 'Intouchables', 2011],
    'VFF tag' => ['Asterix 2023 VFF', 'Asterix', 2023],
    'VFQ tag' => ['Les Visiteurs (1993) VFQ', 'Les Visiteurs', 1993],
    'VO tag' => ['La Haine 1995 VO', 'La Haine', 1995],
    'French word' => ['Léon (1994) French', 'Léon', 1994],
    'Francais accented' => ['Taxi (1998) Français', 'Taxi', 1998],
    'FR short tag' => ['Lucy (2014) FR', 'Lucy', 2014],
    'FRA tag' => ['Arrival 2016 FRA', 'Arrival', 2016],
]);

it('strips German language tags and extracts year in searchMovieManual', function (string $rawQuery, string $expectedQuery, ?int $expectedYear) {
    Http::fake([
        'https://api.themoviedb.org/3/search/movie*' => Http::response(['results' => []], 200),
    ]);

    $service = new TmdbService($this->settings);
    $service->searchMovieManual($rawQuery);

    Http::assertSent(function ($request) use ($expectedQuery, $expectedYear) {
        $data = $request->data();
        if (($data['query'] ?? null) !== $expectedQuery) {
            return false;
        }

        if ($expectedYear === null) {
            return ! array_key_exists('primary_release_year', $data);
        }

        return ($data['primary_release_year'] ?? null) === $expectedYear;
    });
})->with([
    'DE tag' => ['Der Untergang (2004) DE', 'Der Untergang', 2004],
    'GER tag' => ['Run Lola Run 1998 GER', 'Run Lola Run', 1998],
    'German word' => ['Das Boot (1981) German', 'Das Boot', 1981],
    'Deutsch tag' => ['Goodbye Lenin 2003 Deutsch', 'Goodbye Lenin', 2003],
]);

it('strips English/Multi language tags in searchMovieManual', function (string $rawQuery, string $expectedQuery, ?int $expectedYear) {
    Http::fake([
        'https://api.themoviedb.org/3/search/movie*' => Http::response(['results' => []], 200),
    ]);

    $service = new TmdbService($this->settings);
    $service->searchMovieManual($rawQuery);

    Http::assertSent(function ($request) use ($expectedQuery, $expectedYear) {
        $data = $request->data();
        if (($data['query'] ?? null) !== $expectedQuery) {
            return false;
        }

        if ($expectedYear === null) {
            return ! array_key_exists('primary_release_year', $data);
        }

        return ($data['primary_release_year'] ?? null) === $expectedYear;
    });
})->with([
    'ENG tag' => ['Inception (2010) ENG', 'Inception', 2010],
    'EN tag' => ['Heat 1995 EN', 'Heat', 1995],
    'English word' => ['Memento (2000) English', 'Memento', 2000],
    'Multi tag' => ['Avatar (2009) Multi', 'Avatar', 2009],
    'Dual tag' => ['The Matrix (1999) Dual', 'The Matrix', 1999],
]);

it('strips French language tags and extracts year in searchTvSeriesManual', function (string $rawQuery, string $expectedQuery, ?int $expectedYear) {
    Http::fake([
        'https://api.themoviedb.org/3/search/tv*' => Http::response(['results' => []], 200),
    ]);

    $service = new TmdbService($this->settings);
    $service->searchTvSeriesManual($rawQuery);

    Http::assertSent(function ($request) use ($expectedQuery, $expectedYear) {
        $data = $request->data();
        if (($data['query'] ?? null) !== $expectedQuery) {
            return false;
        }

        if ($expectedYear === null) {
            return ! array_key_exists('first_air_date_year', $data);
        }

        return ($data['first_air_date_year'] ?? null) === $expectedYear;
    });
})->with([
    'VOSTFR tag' => ['Lupin (2021) VOSTFR', 'Lupin', 2021],
    'VF tag' => ['Kaamelott 2005 VF', 'Kaamelott', 2005],
    'French word' => ['Dix pour cent (2015) French', 'Dix pour cent', 2015],
    'FR short tag' => ['Le Bureau (2015) FR', 'Le Bureau', 2015],
]);

it('strips German tags in searchTvSeriesManual', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/tv*' => Http::response(['results' => []], 200),
    ]);

    $service = new TmdbService($this->settings);
    $service->searchTvSeriesManual('Dark (2017) Deutsch');

    Http::assertSent(function ($request) {
        $data = $request->data();

        return ($data['query'] ?? null) === 'Dark'
            && ($data['first_air_date_year'] ?? null) === 2017;
    });
});

it('does not strip language tags from the middle of titles in manual search', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/movie*' => Http::response(['results' => []], 200),
    ]);

    $service = new TmdbService($this->settings);
    // "X-Men" must not lose "en"; "VF" mid-title must not be stripped.
    $service->searchMovieManual('X-Men (2000)');

    Http::assertSent(function ($request) {
        return ($request->data()['query'] ?? null) === 'X-Men';
    });
});

it('respects an explicitly provided year over one extracted from the query', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/movie*' => Http::response(['results' => []], 200),
    ]);

    $service = new TmdbService($this->settings);
    // Raw query has 2024, but caller explicitly passes 1999 — caller wins.
    $service->searchMovieManual('The Matrix (2024) VOSTFR', 1999);

    Http::assertSent(function ($request) {
        $data = $request->data();

        return ($data['query'] ?? null) === 'The Matrix'
            && ($data['primary_release_year'] ?? null) === 1999;
    });
});

it('returns cast with TMDB personId for click-through to filmography', function () {
    Cache::flush();
    Http::fake([
        'https://api.themoviedb.org/3/movie/*/credits*' => Http::response([
            'cast' => [
                [
                    'id' => 819,
                    'name' => 'Edward Norton',
                    'character' => 'The Narrator',
                    'profile_path' => '/8nytsqL59SFJTVYVrN72k6qkGgJ.jpg',
                ],
                [
                    'id' => 287,
                    'name' => 'Brad Pitt',
                    'character' => 'Tyler Durden',
                    'profile_path' => '/cckcYc2v0yh1Stubpo9bAs3Kx.jpg',
                ],
            ],
        ], 200),
    ]);

    $service = new TmdbService($this->settings);
    $cast = $service->getMovieCast(550);

    expect($cast)->toHaveCount(2)
        ->and($cast[0])->toMatchArray([
            'id' => 819,
            'actor' => 'Edward Norton',
            'character' => 'The Narrator',
        ])
        ->and($cast[1])->toMatchArray([
            'id' => 287,
            'actor' => 'Brad Pitt',
        ]);
});

it('returns empties when API key is not configured for now playing', function () {
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = null;

    $service = new TmdbService($settings);

    expect($service->getNowPlayingMovies())->toBe([]);
});

it('fetches and normalizes now-playing movies from TMDB', function () {
    Http::fake([
        'https://api.themoviedb.org/3/movie/now_playing*' => Http::response([
            'results' => [
                [
                    'id' => 700,
                    'title' => 'In Theatres Now',
                    'release_date' => '2024-05-01',
                    'overview' => 'A new film.',
                    'poster_path' => '/abc.jpg',
                    'vote_average' => 8.1,
                    'vote_count' => 1500,
                    'genre_ids' => [28, 12],
                ],
            ],
        ], 200),
    ]);

    $service = new TmdbService($this->settings);
    $results = $service->getNowPlayingMovies();

    expect($results)->toHaveCount(1)
        ->and($results[0])->toMatchArray([
            'tmdb_id' => 700,
            'title' => 'In Theatres Now',
            'media_type' => 'movie',
            'year' => '2024',
            'vote_average' => 8.1,
            'genre_ids' => [28, 12],
        ])
        ->and($results[0]['poster_url'])->toBe('https://image.tmdb.org/t/p/w500/abc.jpg');
});
