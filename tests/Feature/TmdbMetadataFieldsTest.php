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
                    ['id' => 6384, 'name' => 'Keanu Reeves', 'character' => 'Neo', 'profile_path' => '/keanu.jpg'],
                    ['id' => 2975, 'name' => 'Laurence Fishburne', 'character' => 'Morpheus', 'profile_path' => null],
                    ['id' => 530, 'name' => 'Carrie-Anne Moss', 'character' => 'Trinity', 'profile_path' => '/carrie.jpg'],
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
            'release_dates' => [
                'results' => [
                    ['iso_3166_1' => 'US', 'release_dates' => [['certification' => 'R', 'type' => 3]]],
                ],
            ],
            'keywords' => [
                'keywords' => [
                    ['id' => 825, 'name' => 'saving the world'],
                    ['id' => 1701, 'name' => 'dystopia'],
                ],
            ],
            'production_companies' => [
                ['id' => 79, 'name' => 'Village Roadshow Pictures', 'logo_path' => '/vr.png', 'origin_country' => 'US'],
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
        ->and($channel->info['youtube_trailer'])->toBe('https://www.youtube.com/watch?v=vKQi3bBA1wc')
        ->and($channel->info['mpaa_rating'])->toBe('R')
        ->and($channel->info['tmdb_certification'])->toBe('R')
        ->and($channel->info['studios'])->toEqual([
            ['id' => 79, 'name' => 'Village Roadshow Pictures', 'logo' => 'https://image.tmdb.org/t/p/w300/vr.png'],
        ])
        // The generic age field is provider-owned: get_vod_info falls back at read time instead.
        ->and($channel->info)->not->toHaveKey('age')
        ->and($channel->info['tmdb_keywords'])->toBe(['saving the world', 'dystopia'])
        // info is a Postgres jsonb column, which does not preserve object key
        // order - toEqual (loose ==) checks values while ignoring key order.
        ->and($channel->info['cast_list'])->toEqual([
            ['id' => 6384, 'name' => 'Keanu Reeves', 'character' => 'Neo', 'photo' => 'https://image.tmdb.org/t/p/w185/keanu.jpg'],
            ['id' => 2975, 'name' => 'Laurence Fishburne', 'character' => 'Morpheus', 'photo' => null],
            ['id' => 530, 'name' => 'Carrie-Anne Moss', 'character' => 'Trinity', 'photo' => 'https://image.tmdb.org/t/p/w185/carrie.jpg'],
        ]);
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
                    ['id' => 17419, 'name' => 'Bryan Cranston', 'character' => 'Walter White', 'profile_path' => '/bc.jpg'],
                    ['id' => 84433, 'name' => 'Aaron Paul', 'character' => 'Jesse Pinkman', 'profile_path' => null],
                    ['id' => 134531, 'name' => 'Anna Gunn', 'character' => 'Skyler White', 'profile_path' => '/ag.jpg'],
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
            'networks' => [
                ['id' => 174, 'name' => 'AMC', 'logo_path' => '/amc.png', 'origin_country' => 'US'],
            ],
            'content_ratings' => [
                'results' => [
                    ['iso_3166_1' => 'DE', 'rating' => '16'],
                    ['iso_3166_1' => 'US', 'rating' => 'TV-MA'],
                ],
            ],
            'keywords' => [
                // TV uses `results`, not `keywords`.
                'results' => [
                    ['id' => 1701, 'name' => 'Drug Dealer'],
                    ['id' => 825, 'name' => 'saving the world'],
                ],
            ],
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
        // A stale rating from an earlier fetch - only TMDB writes it, so it's refreshed.
        'metadata' => ['content_rating' => 'TV-14'],
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
        ->and($series->youtube_trailer)->toBe('https://www.youtube.com/watch?v=HhesaQXLuRY')
        ->and($series->metadata['content_rating'])->toBe('TV-MA')
        ->and($series->metadata['networks'])->toEqual([
            ['id' => 174, 'name' => 'AMC', 'logo' => 'https://image.tmdb.org/t/p/w300/amc.png'],
        ])
        ->and($series->metadata['tmdb_keywords'])->toBe(['drug dealer', 'saving the world'])
        // metadata is a Postgres jsonb column, which does not preserve object key
        // order - toEqual (loose ==) checks values while ignoring key order.
        ->and($series->metadata['cast_list'])->toEqual([
            ['id' => 17419, 'name' => 'Bryan Cranston', 'character' => 'Walter White', 'photo' => 'https://image.tmdb.org/t/p/w185/bc.jpg'],
            ['id' => 84433, 'name' => 'Aaron Paul', 'character' => 'Jesse Pinkman', 'photo' => null],
            ['id' => 134531, 'name' => 'Anna Gunn', 'character' => 'Skyler White', 'photo' => 'https://image.tmdb.org/t/p/w185/ag.jpg'],
        ]);
});

it('keeps media server studios on a VOD when TMDB has no production companies', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/movie*' => Http::response([
            'results' => [['id' => 603, 'title' => 'The Matrix', 'release_date' => '1999-03-30', 'popularity' => 85.5]],
        ], 200),
        'https://api.themoviedb.org/3/movie/603/external_ids*' => Http::response(['imdb_id' => 'tt0133093'], 200),
        'https://api.themoviedb.org/3/movie/603*' => Http::response([
            'id' => 603,
            'title' => 'The Matrix',
            'release_date' => '1999-03-30',
            'production_companies' => [],
        ], 200),
    ]);

    $serverStudios = [['id' => null, 'name' => 'Warner Bros. Pictures', 'logo' => null]];
    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'is_vod' => true,
        'title' => 'The Matrix',
        'year' => 1999,
        'info' => ['studios' => $serverStudios],
    ]);

    (new FetchTmdbIds(vodChannelIds: [$channel->id], seriesIds: null, overwriteExisting: false, user: $this->user))
        ->handle(app(TmdbService::class));

    $info = $channel->refresh()->info;

    expect($info['studios'])->toEqual($serverStudios)
        ->and($info)->toHaveKey('tmdb_certification');
});

it('keeps media server networks on a series when TMDB has none', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/tv*' => Http::response([
            'results' => [['id' => 1396, 'name' => 'Breaking Bad', 'first_air_date' => '2008-01-20', 'popularity' => 90.0]],
        ], 200),
        'https://api.themoviedb.org/3/tv/1396/external_ids*' => Http::response(['tvdb_id' => 81189], 200),
        'https://api.themoviedb.org/3/tv/1396/season/*' => Http::response(['episodes' => []], 200),
        'https://api.themoviedb.org/3/tv/1396*' => Http::response([
            'id' => 1396,
            'name' => 'Breaking Bad',
            'first_air_date' => '2008-01-20',
            'networks' => [],
        ], 200),
    ]);

    $serverNetworks = [['id' => null, 'name' => 'AMC', 'logo' => null]];
    $series = Series::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Breaking Bad',
        'release_date' => '2008-01-20',
        'metadata' => ['networks' => $serverNetworks],
    ]);

    (new FetchTmdbIds(vodChannelIds: null, seriesIds: [$series->id], overwriteExisting: false, user: $this->user))
        ->handle(app(TmdbService::class));

    $metadata = $series->refresh()->metadata;

    expect($metadata['networks'])->toEqual($serverNetworks)
        ->and($metadata)->toHaveKey('content_rating');
});
