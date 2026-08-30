<?php

use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->settings = new GeneralSettings;
    $this->settings->tmdb_api_key = 'synthetic-api-key';
    $this->settings->tmdb_language = 'de-DE';
    $this->settings->tmdb_rate_limit = 40;
    $this->settings->tmdb_confidence_threshold = 80;

    RateLimiter::shouldReceive('tooManyAttempts')->andReturnFalse();
    RateLimiter::shouldReceive('hit')->andReturn(1);
});

function syntheticMovieCandidates(int $count = 12): array
{
    return array_map(fn (int $id): array => [
        'id' => $id,
        'title' => "Synthetic Movie {$id}",
        'original_title' => "Original Movie {$id}",
        'release_date' => '2024-01-01',
        'overview' => "Synthetic overview {$id}",
    ], range(1, $count));
}

it('returns bounded movie candidates in response order with the existing request contract', function (int $limit, int $expectedCount) {
    Http::fake([
        'https://api.themoviedb.org/3/search/movie*' => Http::response([
            'results' => syntheticMovieCandidates(),
        ]),
    ]);

    $candidates = (new TmdbService($this->settings))->searchMovieCandidates('Synthetic [HD] 2024', 2024, $limit);

    expect($candidates)->toHaveCount($expectedCount)
        ->and(array_column($candidates, 'tmdb_id'))->toBe(range(1, $expectedCount))
        ->and($candidates[0])->toMatchArray([
            'tmdb_id' => 1,
            'title' => 'Synthetic Movie 1',
            'original_title' => 'Original Movie 1',
            'release_date' => '2024-01-01',
            'overview' => 'Synthetic overview 1',
        ]);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.themoviedb.org/3/search/movie')
        && $request->data() === [
            'api_key' => 'synthetic-api-key',
            'query' => 'Synthetic',
            'language' => 'de-DE',
            'include_adult' => false,
            'year' => 2024,
        ]);
})->with([
    'one' => [1, 1],
    'default bound' => [5, 5],
    'maximum' => [10, 10],
    'zero clamps to one' => [0, 1],
    'negative clamps to one' => [-5, 1],
    'above maximum clamps to ten' => [20, 10],
]);

it('returns five TV candidates by default with TV year and normalized query parameters', function () {
    $results = array_map(fn (int $id): array => [
        'id' => $id,
        'name' => "Synthetic Series {$id}",
        'original_name' => "Original Series {$id}",
        'first_air_date' => '2021-02-03',
        'overview' => "Synthetic series overview {$id}",
    ], range(1, 7));
    Http::fake([
        'https://api.themoviedb.org/3/search/tv*' => Http::response(['results' => $results]),
    ]);

    $candidates = (new TmdbService($this->settings))->searchTvSeriesCandidates('Synthetic [HD] Series', 2021);

    expect($candidates)->toHaveCount(5)
        ->and(array_column($candidates, 'tmdb_id'))->toBe([1, 2, 3, 4, 5])
        ->and($candidates[0])->toMatchArray([
            'tmdb_id' => 1,
            'name' => 'Synthetic Series 1',
            'original_name' => 'Original Series 1',
            'first_air_date' => '2021-02-03',
            'overview' => 'Synthetic series overview 1',
        ]);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.themoviedb.org/3/search/tv')
        && $request->data() === [
            'api_key' => 'synthetic-api-key',
            'query' => 'Synthetic Series',
            'language' => 'de-DE',
            'include_adult' => false,
            'first_air_date_year' => 2021,
        ]);
});

it('rejects malformed movie and TV candidate IDs without follow-up requests', function (string $mediaType, mixed $id) {
    $isMovie = $mediaType === 'movie';
    $result = $isMovie
        ? [
            'id' => $id,
            'title' => 'Synthetic Movie',
            'original_title' => 'Original Movie',
            'release_date' => '2024-01-01',
            'overview' => 'Synthetic movie overview',
        ]
        : [
            'id' => $id,
            'name' => 'Synthetic Series',
            'original_name' => 'Original Series',
            'first_air_date' => '2021-02-03',
            'overview' => 'Synthetic series overview',
        ];
    $searchUrl = "https://api.themoviedb.org/3/search/{$mediaType}";

    Http::preventStrayRequests();
    Http::fake([
        "{$searchUrl}*" => Http::response(
            json_encode(['results' => [$result]], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            headers: ['Content-Type' => 'application/json'],
        ),
    ]);

    $service = new TmdbService($this->settings);
    $candidates = $isMovie
        ? $service->searchMovieCandidates('Synthetic Movie')
        : $service->searchTvSeriesCandidates('Synthetic Series');

    expect($candidates)->toBe([]);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), $searchUrl));
})->with([
    'movie integer-like float' => ['movie', 123.0],
    'movie fractional float' => ['movie', 1.5],
    'movie integer numeric string' => ['movie', '123'],
    'movie decimal numeric string' => ['movie', '1.5'],
    'movie exponent numeric string' => ['movie', '1e3'],
    'movie null' => ['movie', null],
    'movie true' => ['movie', true],
    'movie false' => ['movie', false],
    'movie array' => ['movie', [123]],
    'movie object' => ['movie', (object) ['id' => 123]],
    'movie zero' => ['movie', 0],
    'movie negative integer' => ['movie', -1],
    'TV integer-like float' => ['tv', 123.0],
    'TV fractional float' => ['tv', 1.5],
    'TV integer numeric string' => ['tv', '123'],
    'TV decimal numeric string' => ['tv', '1.5'],
    'TV exponent numeric string' => ['tv', '1e3'],
    'TV null' => ['tv', null],
    'TV true' => ['tv', true],
    'TV false' => ['tv', false],
    'TV array' => ['tv', [123]],
    'TV object' => ['tv', (object) ['id' => 123]],
    'TV zero' => ['tv', 0],
    'TV negative integer' => ['tv', -1],
]);

it('returns an empty candidate list for unconfigured, blank, failed, malformed, and exceptional searches', function () {
    $unconfigured = new GeneralSettings;
    $unconfigured->tmdb_api_key = null;
    Http::fake();

    expect((new TmdbService($unconfigured))->searchMovieCandidates('Synthetic'))->toBe([])
        ->and((new TmdbService($this->settings))->searchMovieCandidates('   '))->toBe([]);
    Http::assertNothingSent();

    Http::fake(['*' => Http::response(['results' => []], 200)]);
    expect((new TmdbService($this->settings))->searchMovieCandidates('Synthetic'))->toBe([]);

    Http::fake(['*' => Http::response(['results' => []], 503)]);
    expect((new TmdbService($this->settings))->searchTvSeriesCandidates('Synthetic'))->toBe([]);

    Http::fake(['*' => Http::response(['results' => 'not-a-list'], 200)]);
    expect((new TmdbService($this->settings))->searchMovieCandidates('Synthetic'))->toBe([]);

    Http::fake(['*' => Http::response(['results' => [['id' => 1, 'title' => 'Incomplete']]], 200)]);
    expect((new TmdbService($this->settings))->searchMovieCandidates('Synthetic'))->toBe([]);

    Http::fake(fn () => throw new RuntimeException('synthetic failure'));
    expect((new TmdbService($this->settings))->searchTvSeriesCandidates('Synthetic'))->toBe([]);
});

it('preserves the existing single-result movie and TV search behavior', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/movie*' => Http::response(['results' => [[
            'id' => 301,
            'title' => 'Synthetic Movie',
            'original_title' => 'Synthetic Movie',
            'release_date' => '2024-01-01',
        ]]]),
        'https://api.themoviedb.org/3/movie/301/external_ids*' => Http::response(['imdb_id' => 'tt0000301']),
        'https://api.themoviedb.org/3/search/tv*' => Http::response(['results' => [[
            'id' => 401,
            'name' => 'Synthetic Series',
            'original_name' => 'Synthetic Series',
            'first_air_date' => '2021-01-01',
        ]]]),
        'https://api.themoviedb.org/3/tv/401/external_ids*' => Http::response([
            'tvdb_id' => 501,
            'imdb_id' => 'tt0000401',
        ]),
    ]);

    $service = new TmdbService($this->settings);

    expect($service->searchMovie('Synthetic Movie', 2024))->toMatchArray([
        'tmdb_id' => 301,
        'imdb_id' => 'tt0000301',
    ])->and($service->searchTvSeries('Synthetic Series', 2021))->toMatchArray([
        'tmdb_id' => 401,
        'tvdb_id' => 501,
        'imdb_id' => 'tt0000401',
    ]);
});
