<?php

use App\Jobs\FetchTmdbIds;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);

    // Mock TMDB settings
    $this->mock(GeneralSettings::class, function ($mock) {
        $mock->shouldReceive('getAttribute')->with('tmdb_api_key')->andReturn('fake-api-key');
        $mock->shouldReceive('getAttribute')->with('tmdb_language')->andReturn('en-US');
        $mock->shouldReceive('getAttribute')->with('tmdb_rate_limit')->andReturn(40);
        $mock->shouldReceive('getAttribute')->with('tmdb_confidence_threshold')->andReturn(80);
        $mock->tmdb_api_key = 'fake-api-key';
        $mock->tmdb_language = 'en-US';
        $mock->tmdb_rate_limit = 40;
        $mock->tmdb_confidence_threshold = 80;
    });
});

it('fetches cast, director, and trailer for VOD movies', function () {
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
        'https://api.themoviedb.org/3/movie/603*' => Http::response([
            'id' => 603,
            'title' => 'The Matrix',
            'overview' => 'A computer hacker learns about the true nature of reality.',
            'poster_path' => '/poster.jpg',
            'backdrop_path' => '/backdrop.jpg',
            'release_date' => '1999-03-30',
            'vote_average' => 8.7,
            'genres' => [
                ['id' => 28, 'name' => 'Action'],
                ['id' => 878, 'name' => 'Science Fiction'],
            ],
            'credits' => [
                'cast' => [
                    ['name' => 'Keanu Reeves', 'character' => 'Neo'],
                    ['name' => 'Laurence Fishburne', 'character' => 'Morpheus'],
                    ['name' => 'Carrie-Anne Moss', 'character' => 'Trinity'],
                ],
                'crew' => [
                    ['name' => 'Lana Wachowski', 'job' => 'Director'],
                    ['name' => 'Lilly Wachowski', 'job' => 'Director'],
                    ['name' => 'Joel Silver', 'job' => 'Producer'],
                ],
            ],
            'videos' => [
                'results' => [
                    [
                        'key' => 'vKQi3bBA1wc',
                        'site' => 'YouTube',
                        'type' => 'Trailer',
                    ],
                ],
            ],
        ], 200),
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'is_vod' => true,
        'title' => 'The Matrix',
        'year' => 1999,
        'info' => [],
    ]);

    $job = new FetchTmdbIds(
        vodChannelIds: [$channel->id],
        seriesIds: null,
        overwriteExisting: false,
        user: $this->user,
    );

    $job->handle(app(TmdbService::class));

    $channel->refresh();

    expect($channel->info['cast'])->toBe('Keanu Reeves, Laurence Fishburne, Carrie-Anne Moss')
        ->and($channel->info['director'])->toBe('Lana Wachowski, Lilly Wachowski')
        ->and($channel->info['youtube_trailer'])->toBe('https://www.youtube.com/watch?v=vKQi3bBA1wc');
});

it('fetches cast, director, and trailer for TV series', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/tv*' => Http::response([
            'results' => [
                [
                    'id' => 1396,
                    'name' => 'Breaking Bad',
                    'first_air_date' => '2008-01-20',
                    'popularity' => 200.5,
                ],
            ],
        ], 200),
        'https://api.themoviedb.org/3/tv/1396/external_ids*' => Http::response([
            'tvdb_id' => 81189,
            'imdb_id' => 'tt0903747',
        ], 200),
        'https://api.themoviedb.org/3/tv/1396*' => Http::response([
            'id' => 1396,
            'name' => 'Breaking Bad',
            'overview' => 'A high school chemistry teacher turned methamphetamine producer.',
            'poster_path' => '/poster.jpg',
            'backdrop_path' => '/backdrop.jpg',
            'first_air_date' => '2008-01-20',
            'vote_average' => 9.5,
            'genres' => [
                ['id' => 18, 'name' => 'Drama'],
                ['id' => 80, 'name' => 'Crime'],
            ],
            'credits' => [
                'cast' => [
                    ['name' => 'Bryan Cranston', 'character' => 'Walter White'],
                    ['name' => 'Aaron Paul', 'character' => 'Jesse Pinkman'],
                    ['name' => 'Anna Gunn', 'character' => 'Skyler White'],
                ],
                'crew' => [
                    ['name' => 'Vince Gilligan', 'job' => 'Director'],
                    ['name' => 'Michelle MacLaren', 'job' => 'Director'],
                    ['name' => 'Rian Johnson', 'job' => 'Producer'],
                ],
            ],
            'videos' => [
                'results' => [
                    [
                        'key' => 'HhesaQXLuRY',
                        'site' => 'YouTube',
                        'type' => 'Trailer',
                    ],
                ],
            ],
            'number_of_seasons' => 5,
            'number_of_episodes' => 62,
        ], 200),
        'https://api.themoviedb.org/3/tv/1396/season/*' => Http::response([
            'episodes' => [],
        ], 200),
    ]);

    $series = Series::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Breaking Bad',
        'release_date' => '2008-01-20',
        'metadata' => [],
    ]);

    $job = new FetchTmdbIds(
        vodChannelIds: null,
        seriesIds: [$series->id],
        overwriteExisting: false,
        user: $this->user,
    );

    $job->handle(app(TmdbService::class));

    $series->refresh();

    expect($series->cast)->toBe('Bryan Cranston, Aaron Paul, Anna Gunn')
        ->and($series->director)->toBe('Vince Gilligan, Michelle MacLaren')
        ->and($series->youtube_trailer)->toBe('https://www.youtube.com/watch?v=HhesaQXLuRY');
});
