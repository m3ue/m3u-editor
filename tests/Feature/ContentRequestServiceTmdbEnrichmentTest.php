<?php

use App\Models\ArrIntegration;
use App\Models\Playlist;
use App\Models\User;
use App\Services\ContentRequestService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = 'fake-api-key';
    $settings->tmdb_language = 'en-US';
    $settings->tmdb_rate_limit = 40;
    app()->instance(GeneralSettings::class, $settings);

    RateLimiter::shouldReceive('tooManyAttempts')->andReturnFalse();
    RateLimiter::shouldReceive('hit')->andReturn(1);
    RateLimiter::shouldReceive('availableIn')->andReturn(0);

    Cache::flush();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
});

it('enriches a Radarr movie result with TMDB cast_list, clearlogo, and director', function () {
    ArrIntegration::factory()->radarr()->guestEnabled()->create(['user_id' => $this->user->id]);

    Http::fake([
        '*/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 603, 'title' => 'The Matrix', 'year' => 1999],
        ], 200),
        'api.themoviedb.org/3/movie/603*' => Http::response([
            'id' => 603,
            'title' => 'The Matrix',
            'runtime' => 136,
            'credits' => [
                'cast' => [
                    ['id' => 6384, 'name' => 'Keanu Reeves', 'character' => 'Neo', 'profile_path' => '/keanu.jpg'],
                ],
                'crew' => [['job' => 'Director', 'name' => 'Lana Wachowski']],
            ],
            'images' => [
                'logos' => [
                    ['file_path' => '/matrix-logo.png', 'iso_639_1' => 'en', 'vote_average' => 5.0],
                ],
            ],
            'videos' => ['results' => []],
        ], 200),
    ]);

    $result = app(ContentRequestService::class)->search($this->playlist, 'the matrix');
    $movie = $result['results'][0];

    expect($movie['clearlogo'])->toBe('https://image.tmdb.org/t/p/w500/matrix-logo.png');
    expect($movie['cast_list'])->toHaveCount(1);
    expect($movie['cast_list'][0])->toMatchArray([
        'id' => 6384,
        'name' => 'Keanu Reeves',
        'character' => 'Neo',
        'photo' => 'https://image.tmdb.org/t/p/w185/keanu.jpg',
    ]);
    expect($movie['director'])->toBe(['Lana Wachowski']);
    expect($movie['runtime'])->toBe(136);
});

it('resolves a Sonarr series result via tvdb id and normalizes the comma-joined director string', function () {
    ArrIntegration::factory()->sonarr()->guestEnabled()->create(['user_id' => $this->user->id]);

    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            ['tvdbId' => 121361, 'title' => 'Game of Thrones', 'seasons' => []],
        ], 200),
        'api.themoviedb.org/3/find/121361*' => Http::response([
            'tv_results' => [['id' => 1399, 'popularity' => 100]],
            'movie_results' => [],
        ], 200),
        'api.themoviedb.org/3/tv/1399*' => Http::response([
            'id' => 1399,
            'name' => 'Game of Thrones',
            'credits' => [
                'cast' => [
                    ['id' => 22970, 'name' => 'Peter Dinklage', 'character' => 'Tyrion Lannister', 'profile_path' => '/peter.jpg'],
                ],
                'crew' => [
                    ['job' => 'Director', 'name' => 'Alan Taylor'],
                    ['job' => 'Director', 'name' => 'Brian Kirk'],
                ],
            ],
            'images' => [
                'logos' => [
                    ['file_path' => '/got-logo.png', 'iso_639_1' => 'en', 'vote_average' => 8.0],
                ],
            ],
            'videos' => ['results' => []],
            'external_ids' => [],
        ], 200),
    ]);

    $result = app(ContentRequestService::class)->search($this->playlist, 'game of thrones');
    $series = $result['results'][0];

    expect($series['clearlogo'])->toBe('https://image.tmdb.org/t/p/w500/got-logo.png');
    expect($series['cast_list'][0]['name'])->toBe('Peter Dinklage');
    expect($series['director'])->toBe(['Alan Taylor', 'Brian Kirk']);
});

it('degrades gracefully when TMDB is not configured', function () {
    app()->instance(GeneralSettings::class, new GeneralSettings);
    ArrIntegration::factory()->radarr()->guestEnabled()->create(['user_id' => $this->user->id]);

    Http::fake([
        '*/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 603, 'title' => 'The Matrix', 'year' => 1999],
        ], 200),
    ]);

    $result = app(ContentRequestService::class)->search($this->playlist, 'the matrix');
    $movie = $result['results'][0];

    expect($movie['cast_list'])->toBe([]);
    expect($movie['clearlogo'])->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'themoviedb.org'));
});
