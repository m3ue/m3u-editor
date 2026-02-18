<?php

use App\Jobs\FetchTmdbIds;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);

    // Mock TMDB settings without saving to avoid missing properties error
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

it('can fetch TMDB ID for a VOD channel', function () {
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
            'poster_path' => '/matrix.jpg',
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

    expect($channel->info['tmdb_id'])->toBe(603)
        ->and($channel->info['imdb_id'])->toBe('tt0133093');
});

it('can fetch TMDB and TVDB IDs for a series', function () {
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
        'https://api.themoviedb.org/3/tv/4592*' => Http::response([
            'id' => 4592,
            'name' => 'ALF',
            'overview' => 'An alien lifestyle.',
            'poster_path' => '/alf.jpg',
        ], 200),
    ]);

    $series = Series::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'ALF',
        'release_date' => '1986-09-22',
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

    expect($series->metadata['tmdb_id'])->toBe(4592)
        ->and($series->metadata['tvdb_id'])->toBe(78020)
        ->and($series->metadata['imdb_id'])->toBe('tt0090390');
});

it('skips items that already have IDs and metadata when overwrite is false', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/movie*' => Http::response([
            'results' => [
                [
                    'id' => 999,
                    'title' => 'Different Movie',
                    'release_date' => '2020-01-01',
                    'popularity' => 50.0,
                ],
            ],
        ], 200),
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'is_vod' => true,
        'title' => 'The Matrix',
        'tmdb_id' => 603, // Already has ID
        'info' => [
            'tmdb_id' => 603,
            'plot' => 'A computer hacker learns about the true nature of reality.',
            'cover_big' => 'https://image.tmdb.org/t/p/w500/matrix.jpg',
        ], // Already has ID and metadata
    ]);

    $job = new FetchTmdbIds(
        vodChannelIds: [$channel->id],
        seriesIds: null,
        overwriteExisting: false,
        user: $this->user,
    );

    $job->handle(app(TmdbService::class));

    $channel->refresh();

    // Should still have original ID, not updated (skipped because metadata exists)
    expect($channel->tmdb_id)->toBe(603);
});

it('processes items with IDs but missing metadata to populate them', function () {
    Http::fake([
        'https://api.themoviedb.org/3/movie/603/external_ids*' => Http::response([
            'imdb_id' => 'tt0133093',
        ], 200),
        'https://api.themoviedb.org/3/movie/603*' => Http::response([
            'id' => 603,
            'imdb_id' => 'tt0133093',
            'title' => 'The Matrix',
            'overview' => 'A computer hacker learns about the true nature of reality.',
            'poster_path' => '/matrix.jpg',
            'backdrop_path' => '/matrix_backdrop.jpg',
            'release_date' => '1999-03-30',
            'genres' => [
                ['name' => 'Action'],
                ['name' => 'Sci-Fi'],
            ],
            'credits' => [
                'cast' => [
                    ['name' => 'Keanu Reeves'],
                    ['name' => 'Laurence Fishburne'],
                ],
                'crew' => [
                    ['name' => 'Lana Wachowski', 'job' => 'Director'],
                ],
            ],
            'videos' => [
                'results' => [
                    ['site' => 'YouTube', 'type' => 'Trailer', 'key' => 'abc123'],
                ],
            ],
        ], 200),
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'is_vod' => true,
        'title' => 'The Matrix',
        'tmdb_id' => 603, // Has ID but missing metadata
        'logo' => '', // Empty logo (local media)
        'logo_internal' => '', // Empty logo_internal (local media)
        'info' => [
            'tmdb_id' => 603,
            'genre' => 'Uncategorized',
            // plot and cover_big are empty - metadata should be fetched
        ],
    ]);

    $job = new FetchTmdbIds(
        vodChannelIds: [$channel->id],
        seriesIds: null,
        overwriteExisting: false,
        user: $this->user,
    );

    $job->handle(app(TmdbService::class));

    $channel->refresh();

    // Should have metadata populated now
    expect($channel->tmdb_id)->toBe(603)
        ->and($channel->imdb_id)->toBe('tt0133093')
        ->and($channel->info['plot'])->toBe('A computer hacker learns about the true nature of reality.')
        ->and($channel->info['cover_big'])->toBe('https://image.tmdb.org/t/p/w500/matrix.jpg')
        ->and($channel->info['genre'])->toBe('Action, Sci-Fi')
        ->and($channel->info['cast'])->toContain('Keanu Reeves')
        ->and($channel->logo)->toBe('https://image.tmdb.org/t/p/w500/matrix.jpg')
        ->and($channel->logo_internal)->toBe('https://image.tmdb.org/t/p/w500/matrix.jpg')
        ->and($channel->last_metadata_fetch)->not->toBeNull();
});

it('overwrites existing IDs when overwrite is true', function () {
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
        ], 200),
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'is_vod' => true,
        'title' => 'The Matrix',
        'year' => 1999,
        'info' => ['tmdb_id' => 999], // Has wrong ID
    ]);

    $job = new FetchTmdbIds(
        vodChannelIds: [$channel->id],
        seriesIds: null,
        overwriteExisting: true,
        user: $this->user,
    );

    $job->handle(app(TmdbService::class));

    $channel->refresh();

    // Should be updated with correct ID
    expect($channel->info['tmdb_id'])->toBe(603);
});

it('handles items with no match gracefully', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/movie*' => Http::response([
            'results' => [],
        ], 200),
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'is_vod' => true,
        'title' => 'Some Nonexistent Movie XYZ123',
        'info' => [],
    ]);

    $job = new FetchTmdbIds(
        vodChannelIds: [$channel->id],
        seriesIds: null,
        overwriteExisting: false,
        user: $this->user,
    );

    // Should not throw an exception
    $job->handle(app(TmdbService::class));

    $channel->refresh();

    // Should not have any ID set
    expect($channel->info)->not->toHaveKey('tmdb_id');
});

it('splits large lookups into batched chunk jobs', function () {
    Channel::factory()
        ->count(5)
        ->create([
            'playlist_id' => $this->playlist->id,
            'user_id' => $this->user->id,
            'is_vod' => true,
            'enabled' => true,
            'info' => [],
        ]);

    Bus::fake();

    $job = new FetchTmdbIds(
        allVodPlaylists: true,
        overwriteExisting: false,
        user: $this->user,
    );

    $job->batchChunkSize = 2;

    $job->handle(app(TmdbService::class));

    Bus::assertBatched(function ($batch) {
        return count($batch->jobs) === 3;
    });
});
