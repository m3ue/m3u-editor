<?php

use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->settings = new GeneralSettings;
    $this->settings->tmdb_api_key = 'fake-api-key';
    $this->settings->tmdb_language = 'en-US';
    $this->settings->tmdb_rate_limit = 40;

    Cache::flush();
    RateLimiter::shouldReceive('tooManyAttempts')->andReturnFalse();
    RateLimiter::shouldReceive('hit')->andReturn(1);
    Log::spy();
    Http::preventStrayRequests();
});

function seasonResponse(int $seasonNumber): array
{
    return [
        'id' => 900000 + $seasonNumber,
        'season_number' => $seasonNumber,
        'name' => "Season {$seasonNumber}",
        'overview' => 'A synthetic season.',
        'air_date' => '2024-01-01',
        'poster_path' => '/season-poster.jpg',
        'episodes' => [
            [
                'id' => 800000 + $seasonNumber,
                'episode_number' => 1,
                'name' => 'Pilot',
                'overview' => 'A synthetic episode.',
                'air_date' => '2024-01-01',
                'still_path' => '/episode-still.jpg',
                'vote_average' => 7.0,
                'vote_count' => 1,
                'runtime' => 45,
            ],
        ],
    ];
}

function tmdbSeasonService(object $settings): TmdbService
{
    return new TmdbService($settings);
}

it('returns a validated found result for a numbered season including specials', function (int $seasonNumber) {
    Http::fake([
        'https://api.themoviedb.org/3/tv/123/season/*' => Http::response(seasonResponse($seasonNumber)),
    ]);

    $result = tmdbSeasonService($this->settings)->getSeasonDetailsResult(123, $seasonNumber);

    expect($result['status'])->toBe('found')
        ->and($result['payload']['season_number'])->toBe($seasonNumber)
        ->and($result['payload']['season_id'])->toBe(900000 + $seasonNumber);
})->with([0, 1, 3, 7]);

it('caches validated found results by request context', function () {
    Http::fake([
        'https://api.themoviedb.org/3/tv/123/season/3*' => Http::response(seasonResponse(3)),
    ]);

    $service = tmdbSeasonService($this->settings);

    expect($service->getSeasonDetailsResult(123, 3)['status'])->toBe('found')
        ->and($service->getSeasonDetailsResult(123, 3)['status'])->toBe('found');

    Http::assertSentCount(1);
});

it('returns and caches not found only for the documented resource-not-found response', function () {
    Http::fake([
        'https://api.themoviedb.org/3/tv/123/season/7*' => Http::response([
            'status_code' => 34,
            'status_message' => 'The resource you requested could not be found.',
        ], 404),
    ]);

    $service = tmdbSeasonService($this->settings);

    expect($service->getSeasonDetailsResult(123, 7)['status'])->toBe('not_found')
        ->and($service->getSeasonDetailsResult(123, 7)['status'])->toBe('not_found');

    Http::assertSentCount(1);
});

it('does not turn other failures into not found', function (int $status, array $body) {
    Http::fake([
        'https://api.themoviedb.org/3/tv/123/season/7*' => Http::response($body, $status),
    ]);

    $result = tmdbSeasonService($this->settings)->getSeasonDetailsResult(123, 7);

    expect($result['status'])->toBe('error');
})->with([
    'unauthorized' => [401, ['status_code' => 7, 'status_message' => 'Invalid API key.']],
    'forbidden' => [403, ['status_code' => 3, 'status_message' => 'Authentication failed.']],
    'rate limited' => [429, ['status_code' => 25, 'status_message' => 'Your request count is over the allowed limit.']],
    'server error' => [500, ['status_code' => 1, 'status_message' => 'Internal error.']],
    'proxy 404' => [404, ['status_code' => 7, 'status_message' => 'Invalid API key.']],
    'empty 404' => [404, []],
]);

it('treats status code 34 as not found regardless of the message wording', function () {
    Http::fake([
        'https://api.themoviedb.org/3/tv/123/season/7*' => Http::response([
            'status_code' => 34,
            'status_message' => 'A reworded not-found message.',
        ], 404),
    ]);

    expect(tmdbSeasonService($this->settings)->getSeasonDetailsResult(123, 7)['status'])->toBe('not_found');
});

it('ignores a non-array cache entry instead of returning it', function () {
    Cache::put('tmdb_season_result_v1_123_3_en-US', null, now()->addHour());

    Http::fake([
        'https://api.themoviedb.org/3/tv/123/season/3*' => Http::response(seasonResponse(3)),
    ]);

    expect(tmdbSeasonService($this->settings)->getSeasonDetailsResult(123, 3)['status'])->toBe('found');

    Http::assertSentCount(1);
});

it('does not cache errors', function () {
    Http::fake([
        'https://api.themoviedb.org/3/tv/123/season/7*' => Http::response(['status_code' => 25], 429),
    ]);

    $service = tmdbSeasonService($this->settings);

    expect($service->getSeasonDetailsResult(123, 7)['status'])->toBe('error')
        ->and($service->getSeasonDetailsResult(123, 7)['status'])->toBe('error');

    Http::assertSentCount(2);
});

it('returns error for connection failures', function () {
    Http::fake([
        'https://api.themoviedb.org/3/tv/123/season/7*' => Http::failedConnection(),
    ]);

    expect(tmdbSeasonService($this->settings)->getSeasonDetailsResult(123, 7)['status'])->toBe('error');
});

it('returns error for malformed season payloads', function (array $payload) {
    Http::fake([
        'https://api.themoviedb.org/3/tv/123/season/7*' => Http::response($payload),
    ]);

    expect(tmdbSeasonService($this->settings)->getSeasonDetailsResult(123, 7)['status'])->toBe('error');
})->with([
    'missing season id' => [['season_number' => 7, 'episodes' => []]],
    'missing season number' => [['id' => 900007, 'episodes' => []]],
    'wrong season number' => [['id' => 900007, 'season_number' => 6, 'episodes' => []]],
    'invalid episodes shape' => [['id' => 900007, 'season_number' => 7, 'episodes' => 'not-a-list']],
]);

it('returns error for invalid JSON', function () {
    Http::fake([
        'https://api.themoviedb.org/3/tv/123/season/7*' => Http::response('{"invalid":', 200, ['Content-Type' => 'application/json']),
    ]);

    expect(tmdbSeasonService($this->settings)->getSeasonDetailsResult(123, 7)['status'])->toBe('error');
});

it('rejects invalid inputs without an HTTP request', function (int $tmdbId, int $seasonNumber) {
    $result = tmdbSeasonService($this->settings)->getSeasonDetailsResult($tmdbId, $seasonNumber);

    expect($result['status'])->toBe('error');
    Http::assertNothingSent();
})->with([[0, 0], [-1, 0], [123, -1]]);

it('keeps the legacy season lookup request and result compatible while exposing the result contract', function () {
    Http::fake(function (Request $request) {
        if (str_starts_with($request->url(), 'https://api.themoviedb.org/3/tv/123/season/3')
            && ($request->data()['append_to_response'] ?? null) === 'videos') {
            return Http::response([
                ...seasonResponse(3),
                'videos' => ['results' => [['id' => 'synthetic-video']]],
            ]);
        }

        return Http::response([
            'status_code' => 34,
            'status_message' => 'The resource you requested could not be found.',
        ], 404);
    });

    $service = tmdbSeasonService($this->settings);

    expect($service->getSeasonDetails(123, 3))->toMatchArray([
        'season_id' => 900003,
        'season_number' => 3,
        'poster_path' => '/season-poster.jpg',
    ])->and($service->getSeasonDetails(123, 7))->toBeNull();

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.themoviedb.org/3/tv/123/season/3')
        && ($request->data()['append_to_response'] ?? null) === 'videos');
});

it('maps found results with the same shape as the legacy lookup', function () {
    Http::fake([
        'https://api.themoviedb.org/3/tv/123/season/3*' => Http::response(seasonResponse(3)),
    ]);

    $service = tmdbSeasonService($this->settings);

    expect($service->getSeasonDetailsResult(123, 3)['payload'])->toBe($service->getSeasonDetails(123, 3));
});
