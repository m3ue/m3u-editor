<?php

use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

/**
 * Direct tests for TmdbService::collectDynamicGroupResults() — fills the gap
 * flagged back in Phase 4 (no test coverage existed for this method, which is
 * exactly why the Trending bug went undetected until CJ hit it in production).
 *
 * Phase 6 added `$page` support to `getTrending()` and made the `trending`
 * case in `collectDynamicGroupResults()` actually loop `pages` pages like
 * every other paginated source. These tests pin both behaviors down.
 */
beforeEach(function () {
    // Real TmdbService needs a GeneralSettings with a tmdb_api_key to pass
    // isConfigured(). Inject a stub settings object — mirrors
    // GuestActorFilmographyTest's pattern.
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = 'fake-api-key';
    $settings->tmdb_language = 'en-US';
    $settings->tmdb_rate_limit = 40;
    app()->instance(GeneralSettings::class, $settings);

    // waitForRateLimit() uses RateLimiter. Stub it so it doesn't fire on a
    // Redis-backed limiter in a test env that may not have Redis.
    RateLimiter::shouldReceive('tooManyAttempts')->andReturnFalse();
    RateLimiter::shouldReceive('hit')->andReturn(1);

    // getTrending() uses Cache::remember with a key that includes $page, but
    // we still flush between tests so a cached page 1 doesn't bleed into a
    // later test expecting a fresh HTTP call.
    Cache::flush();

    Http::preventStrayRequests();
});

/**
 * Build a fake trending page response shaped like TMDB's real one.
 *
 * @param  array<int>  $ids  TMDB item IDs to seed on this page.
 * @return array<string, mixed>
 */
function trendingPageResponse(array $ids, string $mediaType = 'movie'): array
{
    return [
        'page' => 1, // overwritten by sequence index below in tests
        'results' => array_map(fn (int $id) => [
            'id' => $id,
            'title' => "Title {$id}",
            'name' => "Title {$id}",
            'media_type' => $mediaType,
            'overview' => '',
            'poster_path' => null,
            'backdrop_path' => null,
            'release_date' => '2026-01-01',
            'first_air_date' => '2026-01-01',
            'vote_average' => 7.5,
        ], $ids),
        'total_pages' => 1,
        'total_results' => count($ids),
    ];
}

// ────────────────────────────────────────────────────────────────────────────
// Phase 6 — getTrending() now respects $page
// ────────────────────────────────────────────────────────────────────────────

it('getTrending() requests page=1 by default', function () {
    Http::fake([
        'api.themoviedb.org/3/trending/movie/week*' => Http::response(trendingPageResponse([101, 102]), 200),
    ]);

    $service = app(TmdbService::class);
    $results = $service->getTrending('movie', 'week');

    expect($results)->toHaveCount(2)
        ->and($results[0]['tmdb_id'])->toBe(101);

    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $params);

        return ($request->url() === 'https://api.themoviedb.org/3/trending/movie/week'
            || str_contains($request->url(), '/trending/movie/week'))
            && ($params['page'] ?? null) === '1';
    });
});

it('getTrending() actually forwards $page to the TMDB API (the bug)', function () {
    // This is the regression test for Phase 6's root bug: pre-fix, the method
    // signature was `getTrending(string $mediaType, string $timeWindow)` with
    // NO page parameter — the call always hit page 1 even when callers thought
    // they were paginating. Pin this down: requesting page 2 must hit page 2.
    Http::fake([
        'api.themoviedb.org/3/trending/movie/week*' => Http::response(trendingPageResponse([201, 202]), 200),
    ]);

    $service = app(TmdbService::class);
    $results = $service->getTrending('movie', 'week', 2);

    expect($results)->toHaveCount(2)
        ->and($results[0]['tmdb_id'])->toBe(201);

    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $params);

        return ($params['page'] ?? null) === '2';
    });
});

it('getTrending() cache key includes $page so different pages do not collide', function () {
    // Calling page=1 then page=2 must produce TWO separate HTTP requests, not
    // a single cached one. Pre-fix this would have been a single-page cache;
    // post-fix the per-page key isolates them.
    Http::fake([
        'api.themoviedb.org/3/trending/movie/week*' => Http::sequence()
            ->push(trendingPageResponse([301]), 200)
            ->push(trendingPageResponse([302]), 200),
    ]);

    $service = app(TmdbService::class);
    $page1 = $service->getTrending('movie', 'week', 1);
    $page2 = $service->getTrending('movie', 'week', 2);

    expect($page1[0]['tmdb_id'])->toBe(301)
        ->and($page2[0]['tmdb_id'])->toBe(302);

    Http::assertSentCount(2);
});

// ────────────────────────────────────────────────────────────────────────────
// Phase 6 — collectDynamicGroupResults() trending case loops pages
// ────────────────────────────────────────────────────────────────────────────

it('collectDynamicGroupResults() makes 3 distinct trending calls when pages=3', function () {
    // Distinct fake pages keyed off the page query param — proves the loop is
    // hitting each page, not caching page 1 and reusing it for pages 2-3.
    Http::fake([
        'api.themoviedb.org/3/trending/movie/week*' => Http::sequence()
            ->push(trendingPageResponse([401, 402, 403]), 200) // page 1
            ->push(trendingPageResponse([404, 405, 406]), 200) // page 2
            ->push(trendingPageResponse([407, 408, 409]), 200), // page 3
    ]);

    $service = app(TmdbService::class);
    $results = $service->collectDynamicGroupResults('vod', 'trending', ['pages' => 3]);

    // 3 pages × 3 items = 9 results, all merged.
    expect($results)->toHaveCount(9)
        ->and(array_column($results, 'tmdb_id'))->toBe([401, 402, 403, 404, 405, 406, 407, 408, 409]);

    // Exactly 3 HTTP requests — proves the loop runs pages times.
    Http::assertSentCount(3);

    // All 3 page numbers must appear in the actual request URLs.
    $pageNumbers = [];
    Http::assertSent(function ($request) use (&$pageNumbers) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $params);
        $pageNumbers[] = (int) ($params['page'] ?? 0);

        return true;
    });
    expect($pageNumbers)->toEqualCanonicalizing([1, 2, 3]);
});

it('collectDynamicGroupResults() loops pages for series/trending (tv media type)', function () {
    Http::fake([
        'api.themoviedb.org/3/trending/tv/week*' => Http::sequence()
            ->push(trendingPageResponse([501], 'tv'), 200)
            ->push(trendingPageResponse([502], 'tv'), 200),
    ]);

    $service = app(TmdbService::class);
    $results = $service->collectDynamicGroupResults('series', 'trending', ['pages' => 2]);

    expect($results)->toHaveCount(2)
        ->and(array_column($results, 'tmdb_id'))->toBe([501, 502])
        ->and($results[0]['media_type'])->toBe('tv')
        ->and($results[1]['media_type'])->toBe('tv');

    Http::assertSentCount(2);

    // Sanity: every request hit the TV endpoint, not movies.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/trending/tv/');
    });
});

it('collectDynamicGroupResults() defaults to 3 pages when pages param is absent (backwards compat)', function () {
    Http::fake([
        'api.themoviedb.org/3/trending/movie/week*' => Http::sequence()
            ->push(trendingPageResponse([601]), 200)
            ->push(trendingPageResponse([602]), 200)
            ->push(trendingPageResponse([603]), 200),
    ]);

    $service = app(TmdbService::class);
    $results = $service->collectDynamicGroupResults('vod', 'trending', []); // no pages key

    expect($results)->toHaveCount(3);
    Http::assertSentCount(3); // default is 3 (unchanged from pre-fix behavior, but now actually loops)
});

it('collectDynamicGroupResults() caps pages at MAX_DYNAMIC_GROUP_PAGES = 5', function () {
    Http::fake([
        'api.themoviedb.org/3/trending/movie/week*' => Http::sequence()
            ->push(trendingPageResponse([701]), 200)
            ->push(trendingPageResponse([702]), 200)
            ->push(trendingPageResponse([703]), 200)
            ->push(trendingPageResponse([704]), 200)
            ->push(trendingPageResponse([705]), 200)
            // If the cap is broken, the 6th push would fire.
            ->push(trendingPageResponse([706]), 200),
    ]);

    $service = app(TmdbService::class);
    $results = $service->collectDynamicGroupResults('vod', 'trending', ['pages' => 99]); // way over cap

    expect($results)->toHaveCount(5);
    Http::assertSentCount(5);
});

it('collectDynamicGroupResults() trending respects time_window param (day vs week)', function () {
    Http::fake([
        'api.themoviedb.org/3/trending/movie/day*' => Http::response(trendingPageResponse([801]), 200),
        'api.themoviedb.org/3/trending/movie/week*' => Http::response(trendingPageResponse([802]), 200),
    ]);

    $service = app(TmdbService::class);

    $dayResults = $service->collectDynamicGroupResults('vod', 'trending', ['pages' => 1, 'time_window' => 'day']);
    $weekResults = $service->collectDynamicGroupResults('vod', 'trending', ['pages' => 1, 'time_window' => 'week']);

    expect($dayResults[0]['tmdb_id'])->toBe(801)
        ->and($weekResults[0]['tmdb_id'])->toBe(802);
});
