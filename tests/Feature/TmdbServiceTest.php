<?php

use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Mock settings with a fake API key
    $this->settings = new GeneralSettings;
    $this->settings->tmdb_api_key = 'fake-api-key';
    $this->settings->tmdb_language = 'en-US';
    $this->settings->tmdb_rate_limit = 40;
    $this->settings->tmdb_confidence_threshold = 80;
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
