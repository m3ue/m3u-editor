<?php

use App\Jobs\FetchTmdbIds;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function mockTmdbSettings(bool $autoCreateGroups = false): void
{
    test()->mock(GeneralSettings::class, function ($mock) use ($autoCreateGroups) {
        $mock->shouldReceive('getAttribute')->with('tmdb_api_key')->andReturn('fake-api-key');
        $mock->shouldReceive('getAttribute')->with('tmdb_language')->andReturn('en-US');
        $mock->shouldReceive('getAttribute')->with('tmdb_rate_limit')->andReturn(40);
        $mock->shouldReceive('getAttribute')->with('tmdb_confidence_threshold')->andReturn(80);
        $mock->shouldReceive('getAttribute')->with('tmdb_auto_create_groups')->andReturn($autoCreateGroups);
        $mock->tmdb_api_key = 'fake-api-key';
        $mock->tmdb_language = 'en-US';
        $mock->tmdb_rate_limit = 40;
        $mock->tmdb_confidence_threshold = 80;
        $mock->tmdb_auto_create_groups = $autoCreateGroups;
    });
}

beforeEach(function () {
    Event::fake();
    Cache::swap(new Repository(new ArrayStore));

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);

    // Mock TMDB settings without saving to avoid missing properties error
    mockTmdbSettings();
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

    $job = new TestableFetchTmdbIds(
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

    $job = new TestableFetchTmdbIds(
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

    $job = new TestableFetchTmdbIds(
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

    $job = new TestableFetchTmdbIds(
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

    $job = new TestableFetchTmdbIds(
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

    $job = new TestableFetchTmdbIds(
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

    $job = new TestableFetchTmdbIds(
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

it('does not overwrite VOD group when already set, but populates genre on first fetch', function () {
    Http::fake([
        'https://api.themoviedb.org/3/movie/603/external_ids*' => Http::response([
            'imdb_id' => 'tt0133093',
        ], 200),
        'https://api.themoviedb.org/3/movie/603*' => Http::response([
            'id' => 603,
            'title' => 'The Matrix',
            'overview' => 'A computer hacker learns about the true nature of reality.',
            'poster_path' => '/matrix.jpg',
            'genres' => [
                ['name' => 'Action'],
                ['name' => 'Sci-Fi'],
            ],
        ], 200),
    ]);

    $libraryGroup = Group::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Movies',
        'name_internal' => 'Movies',
        'type' => 'vod',
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'is_vod' => true,
        'title' => 'The Matrix',
        'tmdb_id' => 603,
        'group' => 'Movies',
        'group_internal' => 'Movies',
        'group_id' => $libraryGroup->id,
        'last_metadata_fetch' => null, // Never enriched by TMDB
        'info' => ['tmdb_id' => 603],
    ]);

    $job = new TestableFetchTmdbIds(
        vodChannelIds: [$channel->id],
        seriesIds: null,
        overwriteExisting: false,
        user: $this->user,
    );

    $job->handle(app(TmdbService::class));

    $channel->refresh();

    // Group should remain unchanged (already a non-Uncategorized value)
    expect($channel->group)->toBe('Movies')
        ->and($channel->group_internal)->toBe('Movies')
        ->and($channel->info['genre'])->toContain('Action');
});

it('does not overwrite series category when already set, but populates genre on first fetch', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/tv*' => Http::response([
            'results' => [
                [
                    'id' => 1396,
                    'name' => 'Breaking Bad',
                    'first_air_date' => '2008-01-20',
                    'popularity' => 95.0,
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
            'overview' => 'A high school chemistry teacher turned drug lord.',
            'poster_path' => '/breakingbad.jpg',
            'genres' => [
                ['name' => 'Drama'],
                ['name' => 'Crime'],
            ],
            'first_air_date' => '2008-01-20',
        ], 200),
    ]);

    $libraryCategory = Category::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'TV Shows',
        'name_internal' => 'TV Shows',
    ]);

    $series = Series::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Breaking Bad',
        'category_id' => $libraryCategory->id,
        'source_category_id' => $libraryCategory->id,
        'metadata' => [],
        'genre' => null, // No genre yet, should be populated from TMDB
        'last_metadata_fetch' => null, // Never enriched by TMDB
    ]);

    $job = new TestableFetchTmdbIds(
        vodChannelIds: null,
        seriesIds: [$series->id],
        overwriteExisting: false,
        user: $this->user,
    );

    $job->handle(app(TmdbService::class));

    $series->refresh();

    // Category should remain unchanged (already a non-Uncategorized value)
    $updatedCategory = Category::find($series->category_id);
    expect($updatedCategory)->not->toBeNull()
        ->and($updatedCategory->name)->toBe('TV Shows')
        ->and($series->genre)->toContain('Drama');
});

it('enriches episodes when series has complete metadata but episodes lack tmdb_id', function () {
    Http::fake([
        // getAllSeasons call
        'https://api.themoviedb.org/3/tv/224372?*' => Http::response([
            'seasons' => [
                ['season_number' => 1, 'episode_count' => 2],
            ],
        ], 200),
        // getSeasonDetails call
        'https://api.themoviedb.org/3/tv/224372/season/1*' => Http::response([
            'season_number' => 1,
            'poster_path' => '/season1.jpg',
            'episodes' => [
                [
                    'id' => 4360857,
                    'episode_number' => 1,
                    'name' => 'The Hedge Knight',
                    'overview' => 'Dunk and Egg arrive at the tourney.',
                    'air_date' => '2025-04-06',
                    'still_path' => '/hedge_knight.jpg',
                    'vote_average' => 8.5,
                    'runtime' => 62,
                ],
                [
                    'id' => 5329379,
                    'episode_number' => 2,
                    'name' => 'Hard Salt Beef',
                    'overview' => 'Dunk faces the consequences.',
                    'air_date' => '2025-04-13',
                    'still_path' => '/hard_salt_beef.jpg',
                    'vote_average' => 8.2,
                    'runtime' => 58,
                ],
            ],
        ], 200),
    ]);

    $series = Series::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'A Knight of the Seven Kingdoms',
        'tmdb_id' => 224372,
        'plot' => 'A century before the events of Game of Thrones...',
        'cover' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
        'last_metadata_fetch' => now()->subDay(),
        'metadata' => ['tmdb_id' => 224372],
    ]);

    $season = Season::factory()->create([
        'series_id' => $series->id,
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'season_number' => 1,
    ]);

    // Episodes with filename-based titles and no TMDB data
    $ep1 = Episode::factory()->create([
        'series_id' => $series->id,
        'season_id' => $season->id,
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'title' => 'The Hedge Knight WEBDL-1080p Proper',
        'season' => 1,
        'episode_num' => 1,
        'tmdb_id' => null,
        'cover' => null,
        'plot' => null,
        'info' => [],
    ]);

    $ep2 = Episode::factory()->create([
        'series_id' => $series->id,
        'season_id' => $season->id,
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'title' => 'Hard Salt Beef WEBDL-1080p',
        'season' => 1,
        'episode_num' => 2,
        'tmdb_id' => null,
        'cover' => null,
        'plot' => null,
        'info' => [],
    ]);

    $job = new TestableFetchTmdbIds(
        seriesIds: [$series->id],
        overwriteExisting: false,
        user: $this->user,
    );

    $job->handle(app(TmdbService::class));

    $ep1->refresh();
    $ep2->refresh();

    // Episode titles should be updated from TMDB (stripping quality tags)
    expect($ep1->title)->toBe('The Hedge Knight')
        ->and($ep2->title)->toBe('Hard Salt Beef');

    // Dedicated tmdb_id column should be set
    expect($ep1->tmdb_id)->toBe(4360857)
        ->and($ep2->tmdb_id)->toBe(5329379);

    // Dedicated plot column should be set
    expect($ep1->plot)->toBe('Dunk and Egg arrive at the tourney.')
        ->and($ep2->plot)->toBe('Dunk faces the consequences.');

    // Cover should be set from TMDB still_path
    expect($ep1->cover)->toBe('https://image.tmdb.org/t/p/original/hedge_knight.jpg')
        ->and($ep2->cover)->toBe('https://image.tmdb.org/t/p/original/hard_salt_beef.jpg');

    // Info array should also contain the metadata
    expect($ep1->info['tmdb_id'])->toBe(4360857)
        ->and($ep1->info['movie_image'])->toBe('https://image.tmdb.org/t/p/original/hedge_knight.jpg')
        ->and($ep1->info['plot'])->toBe('Dunk and Egg arrive at the tourney.')
        ->and($ep1->info['releasedate'])->toBe('2025-04-06')
        ->and($ep1->info['rating'])->toBe(8.5)
        ->and($ep1->info['duration_secs'])->toBe(3720);
});

it('enriches episodes even when series is skipped due to complete metadata', function () {
    Http::fake([
        // getAllSeasons call
        'https://api.themoviedb.org/3/tv/1396?*' => Http::response([
            'seasons' => [
                ['season_number' => 1, 'episode_count' => 1],
            ],
        ], 200),
        // getSeasonDetails call
        'https://api.themoviedb.org/3/tv/1396/season/1*' => Http::response([
            'season_number' => 1,
            'poster_path' => '/bb_s1.jpg',
            'episodes' => [
                [
                    'id' => 62085,
                    'episode_number' => 1,
                    'name' => 'Pilot',
                    'overview' => 'Walter White begins his descent.',
                    'air_date' => '2008-01-20',
                    'still_path' => '/bb_pilot.jpg',
                    'vote_average' => 8.8,
                    'runtime' => 58,
                ],
            ],
        ], 200),
    ]);

    // Series with complete metadata (would normally be skipped entirely)
    $series = Series::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Breaking Bad',
        'tmdb_id' => 1396,
        'plot' => 'A high school chemistry teacher turned drug lord.',
        'cover' => 'https://image.tmdb.org/t/p/w500/breakingbad.jpg',
        'last_metadata_fetch' => now()->subDay(),
        'metadata' => ['tmdb_id' => 1396],
    ]);

    $season = Season::factory()->create([
        'series_id' => $series->id,
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'season_number' => 1,
    ]);

    // Episode with no TMDB enrichment
    $episode = Episode::factory()->create([
        'series_id' => $series->id,
        'season_id' => $season->id,
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'title' => 'Episode 1',
        'season' => 1,
        'episode_num' => 1,
        'tmdb_id' => null,
        'cover' => null,
        'plot' => null,
        'info' => [],
    ]);

    $job = new TestableFetchTmdbIds(
        seriesIds: [$series->id],
        overwriteExisting: false,
        user: $this->user,
    );

    $job->handle(app(TmdbService::class));

    $episode->refresh();
    $series->refresh();

    // Series metadata should remain unchanged (still skipped for re-processing)
    expect($series->tmdb_id)->toBe(1396)
        ->and($series->plot)->toBe('A high school chemistry teacher turned drug lord.');

    // But the episode should be enriched with TMDB data
    expect($episode->title)->toBe('Pilot')
        ->and($episode->tmdb_id)->toBe(62085)
        ->and($episode->plot)->toBe('Walter White begins his descent.')
        ->and($episode->cover)->toBe('https://image.tmdb.org/t/p/original/bb_pilot.jpg');
});

it('re-enriches series genre when it is a library name placeholder', function () {
    // This test requires auto_create_groups to be enabled
    mockTmdbSettings(true);

    Http::fake([
        'https://api.themoviedb.org/3/tv/1396?*' => Http::response([
            'id' => 1396,
            'name' => 'Breaking Bad',
            'genres' => [
                ['name' => 'Drama'],
                ['name' => 'Crime'],
            ],
            'seasons' => [],
        ], 200),
        // getSeasonDetails should not be called since there are no seasons with episodes needing enrichment
    ]);

    $series = Series::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Breaking Bad',
        'tmdb_id' => 1396,
        'plot' => 'A high school chemistry teacher turned drug lord.',
        'cover' => 'https://image.tmdb.org/t/p/w500/breakingbad.jpg',
        'genre' => 'tv', // Library name placeholder — should be replaced
        'last_metadata_fetch' => now()->subDay(),
        'metadata' => ['tmdb_id' => 1396],
    ]);

    $libraryCategory = Category::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'tv',
        'name_internal' => 'tv',
    ]);
    $series->update(['category_id' => $libraryCategory->id]);

    $job = new TestableFetchTmdbIds(
        seriesIds: [$series->id],
        overwriteExisting: false,
        user: $this->user,
    );

    $job->handle(app(TmdbService::class));

    $series->refresh();

    // Genre should be updated from 'tv' to TMDB genres
    expect($series->genre)->toContain('Drama')
        ->and($series->genre)->toContain('Crime');

    // Category should be updated to the primary TMDB genre
    $updatedCategory = Category::find($series->category_id);
    expect($updatedCategory->name)->toBe('Drama');
});

it('re-enriches VOD genre when it is a library name placeholder', function () {
    // This test requires auto_create_groups to be enabled
    mockTmdbSettings(true);

    Http::fake([
        'https://api.themoviedb.org/3/movie/603*' => Http::response([
            'id' => 603,
            'title' => 'The Matrix',
            'overview' => 'A computer hacker learns about the true nature of reality.',
            'poster_path' => '/matrix.jpg',
            'genres' => [
                ['name' => 'Action'],
                ['name' => 'Sci-Fi'],
            ],
        ], 200),
    ]);

    $libraryGroup = Group::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Movies',
        'name_internal' => 'Movies',
        'type' => 'vod',
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'is_vod' => true,
        'title' => 'The Matrix',
        'tmdb_id' => 603,
        'group' => 'Movies',
        'group_internal' => 'Movies',
        'group_id' => $libraryGroup->id,
        'last_metadata_fetch' => now()->subDay(),
        'info' => [
            'tmdb_id' => 603,
            'plot' => 'A computer hacker learns about the true nature of reality.',
            'cover_big' => 'https://image.tmdb.org/t/p/w500/matrix.jpg',
            'genre' => 'Movies', // Library name placeholder
        ],
    ]);

    $job = new TestableFetchTmdbIds(
        vodChannelIds: [$channel->id],
        seriesIds: null,
        overwriteExisting: false,
        user: $this->user,
    );

    $job->handle(app(TmdbService::class));

    $channel->refresh();

    // Group should be updated from 'Movies' to TMDB primary genre
    $actionGroup = Group::where('name', 'Action')->where('playlist_id', $this->playlist->id)->first();
    expect($channel->group)->toBe('Action')
        ->and($channel->group_internal)->toBe('Action')
        ->and($channel->group_id)->toBe($actionGroup->id);

    // Info genre should be updated to TMDB genres
    expect($channel->info['genre'])->toContain('Action');
});

it('skips genre re-enrichment when genre is already a TMDB genre', function () {
    Http::fake([
        'https://api.themoviedb.org/3/tv/1396?*' => Http::response([
            'id' => 1396,
            'name' => 'Breaking Bad',
            'genres' => [
                ['name' => 'Drama'],
                ['name' => 'Crime'],
            ],
            'seasons' => [],
        ], 200),
    ]);

    $series = Series::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Breaking Bad',
        'tmdb_id' => 1396,
        'plot' => 'A high school chemistry teacher turned drug lord.',
        'cover' => 'https://image.tmdb.org/t/p/w500/breakingbad.jpg',
        'genre' => 'Drama', // Single word but IS a valid TMDB genre — should NOT be replaced
        'last_metadata_fetch' => now()->subDay(),
        'metadata' => ['tmdb_id' => 1396],
    ]);

    $dramaCategory = Category::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Drama',
        'name_internal' => 'Drama',
    ]);
    $series->update(['category_id' => $dramaCategory->id]);

    $job = new TestableFetchTmdbIds(
        seriesIds: [$series->id],
        overwriteExisting: false,
        user: $this->user,
    );

    $job->handle(app(TmdbService::class));

    $series->refresh();

    // Genre should remain as 'Drama' (it's a valid TMDB genre, not a placeholder)
    expect($series->genre)->toBe('Drama')
        ->and($series->category_id)->toBe($dramaCategory->id);
});

it('skips episode enrichment when all episodes already have tmdb_id', function () {
    Http::fake();

    $series = Series::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Breaking Bad',
        'tmdb_id' => 1396,
        'plot' => 'A high school chemistry teacher turned drug lord.',
        'cover' => 'https://image.tmdb.org/t/p/w500/breakingbad.jpg',
        'genre' => 'Drama, Crime',
        'last_metadata_fetch' => now()->subDay(),
        'metadata' => ['tmdb_id' => 1396],
    ]);

    $season = Season::factory()->create([
        'series_id' => $series->id,
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'season_number' => 1,
    ]);

    // Episode already enriched
    Episode::factory()->create([
        'series_id' => $series->id,
        'season_id' => $season->id,
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'title' => 'Pilot',
        'season' => 1,
        'episode_num' => 1,
        'tmdb_id' => 62085,
        'cover' => 'https://image.tmdb.org/t/p/original/bb_pilot.jpg',
        'plot' => 'Walter White begins his descent.',
    ]);

    $job = new TestableFetchTmdbIds(
        seriesIds: [$series->id],
        overwriteExisting: false,
        user: $this->user,
    );

    $job->handle(app(TmdbService::class));

    // No HTTP calls should have been made (series and episodes both skipped)
    Http::assertNothingSent();
});

it('does not create groups from TMDB genres when auto_create_groups is disabled', function () {
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
            'genres' => [
                ['name' => 'Action'],
                ['name' => 'Sci-Fi'],
            ],
        ], 200),
    ]);

    $group = Group::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Uncategorized',
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'group_id' => $group->id,
        'is_vod' => true,
        'title' => 'The Matrix',
        'year' => 1999,
        'group' => 'Uncategorized',
        'group_internal' => 'Uncategorized',
        'info' => [],
    ]);

    $groupCountBefore = Group::count();

    $job = new TestableFetchTmdbIds(
        vodChannelIds: [$channel->id],
        overwriteExisting: false,
        user: $this->user,
    );

    $job->handle(app(TmdbService::class));

    $channel->refresh();

    // TMDB IDs should still be fetched
    expect($channel->tmdb_id)->toBe(603);

    // No new groups should have been created
    expect(Group::count())->toBe($groupCountBefore);

    // Channel group should remain unchanged
    expect($channel->group)->toBe('Uncategorized');
});

it('creates groups from TMDB genres when auto_create_groups is enabled', function () {
    // Override the mock to enable auto_create_groups
    mockTmdbSettings(true);

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
            'genres' => [
                ['name' => 'Action'],
                ['name' => 'Sci-Fi'],
            ],
        ], 200),
    ]);

    $group = Group::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Uncategorized',
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'group_id' => $group->id,
        'is_vod' => true,
        'title' => 'The Matrix',
        'year' => 1999,
        'group' => 'Uncategorized',
        'group_internal' => 'Uncategorized',
        'info' => [],
    ]);

    $job = new TestableFetchTmdbIds(
        vodChannelIds: [$channel->id],
        overwriteExisting: false,
        user: $this->user,
    );

    $job->handle(app(TmdbService::class));

    $channel->refresh();

    // TMDB IDs should be fetched
    expect($channel->tmdb_id)->toBe(603);

    // A new 'Action' group should have been created
    expect(Group::where('name', 'Action')->where('playlist_id', $this->playlist->id)->exists())->toBeTrue();

    // Channel should be assigned to the new group
    expect($channel->group)->toBe('Action');
});

it('does not create categories for series when auto_create_groups is disabled', function () {
    Http::fake([
        'https://api.themoviedb.org/3/search/tv*' => Http::response([
            'results' => [
                [
                    'id' => 1399,
                    'name' => 'Game of Thrones',
                    'first_air_date' => '2011-04-17',
                    'popularity' => 90.0,
                ],
            ],
        ], 200),
        'https://api.themoviedb.org/3/tv/1399/external_ids*' => Http::response([
            'tvdb_id' => 121361,
            'imdb_id' => 'tt0944947',
        ], 200),
        'https://api.themoviedb.org/3/tv/1399*' => Http::response([
            'id' => 1399,
            'name' => 'Game of Thrones',
            'overview' => 'Seven noble families fight for control of the mythical land of Westeros.',
            'poster_path' => '/got.jpg',
            'genres' => [
                ['name' => 'Drama'],
                ['name' => 'Fantasy'],
            ],
        ], 200),
    ]);

    $series = Series::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Game of Thrones',
        'genre' => 'Uncategorized',
        'category_id' => null,
        'metadata' => [],
    ]);

    $categoryCountBefore = Category::count();

    $job = new TestableFetchTmdbIds(
        seriesIds: [$series->id],
        overwriteExisting: false,
        user: $this->user,
    );

    $job->handle(app(TmdbService::class));

    $series->refresh();

    // TMDB IDs should still be fetched
    expect($series->tmdb_id)->toBe(1399);

    // No new categories should have been created
    expect(Category::count())->toBe($categoryCountBefore);

    // Series category should remain unset
    expect($series->category_id)->toBeNull();
});

it('creates categories for series when auto_create_groups is enabled', function () {
    mockTmdbSettings(true);

    Http::fake([
        'https://api.themoviedb.org/3/search/tv*' => Http::response([
            'results' => [
                [
                    'id' => 1399,
                    'name' => 'Game of Thrones',
                    'first_air_date' => '2011-04-17',
                    'popularity' => 90.0,
                ],
            ],
        ], 200),
        'https://api.themoviedb.org/3/tv/1399/external_ids*' => Http::response([
            'tvdb_id' => 121361,
            'imdb_id' => 'tt0944947',
        ], 200),
        'https://api.themoviedb.org/3/tv/1399*' => Http::response([
            'id' => 1399,
            'name' => 'Game of Thrones',
            'overview' => 'Seven noble families fight for control of the mythical land of Westeros.',
            'poster_path' => '/got.jpg',
            'genres' => [
                ['name' => 'Drama'],
                ['name' => 'Fantasy'],
            ],
            'seasons' => [],
        ], 200),
    ]);

    $series = Series::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Game of Thrones',
        'genre' => 'Uncategorized',
        'category_id' => null,
        'metadata' => [],
    ]);

    $job = new TestableFetchTmdbIds(
        seriesIds: [$series->id],
        overwriteExisting: false,
        user: $this->user,
    );

    $job->handle(app(TmdbService::class));

    $series->refresh();

    // TMDB IDs should be fetched
    expect($series->tmdb_id)->toBe(1399);

    // A new 'Drama' category should have been created
    expect(Category::where('name', 'Drama')->where('playlist_id', $this->playlist->id)->exists())->toBeTrue();

    // Series should be assigned to the primary TMDB genre category
    $category = Category::where('name', 'Drama')->where('playlist_id', $this->playlist->id)->first();
    expect($series->category_id)->toBe($category->id)
        ->and($series->source_category_id)->toBe($category->id);
});

it('excludes VOD channels that were attempted but had no match from query when overwrite is false', function () {
    // This channel was already attempted but no TMDB match was found
    Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'is_vod' => true,
        'enabled' => true,
        'title' => 'Obscure Movie With No Match',
        'tmdb_id' => null,
        'imdb_id' => null,
        'last_metadata_fetch' => now(),
    ]);

    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('isConfigured')->andReturn(true);
    $tmdb->shouldNotReceive('searchMovie');
    $tmdb->shouldNotReceive('getMovieDetails');

    $job = new TestableFetchTmdbIds(
        vodPlaylistId: $this->playlist->id,
        overwriteExisting: false,
        user: $this->user,
    );
    $job->handle($tmdb);
});

it('includes VOD channels with tmdb_id but missing metadata even when overwrite is false', function () {
    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('isConfigured')->andReturn(true);
    $tmdb->shouldReceive('getMovieDetails')->with(603)->andReturn([
        'tmdb_id' => 603,
        'overview' => 'A computer hacker learns about the true nature of reality.',
        'poster_url' => 'https://image.tmdb.org/t/p/w500/matrix.jpg',
    ]);

    // Has tmdb_id and last_metadata_fetch but missing plot and cover
    $needsMetadata = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'is_vod' => true,
        'enabled' => true,
        'title' => 'The Matrix',
        'tmdb_id' => 603,
        'last_metadata_fetch' => now(),
        'info' => [],
    ]);

    $job = new TestableFetchTmdbIds(
        vodPlaylistId: $this->playlist->id,
        overwriteExisting: false,
        user: $this->user,
    );
    $job->handle($tmdb);

    $needsMetadata->refresh();
    expect($needsMetadata->info)->toHaveKey('plot');
});

it('excludes series that were attempted but had no match from query when overwrite is false', function () {
    // This series was already attempted but no TMDB/TVDB match was found
    Series::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'name' => 'Obscure Series With No Match',
        'tmdb_id' => null,
        'tvdb_id' => null,
        'imdb_id' => null,
        'last_metadata_fetch' => now(),
    ]);

    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('isConfigured')->andReturn(true);
    $tmdb->shouldNotReceive('searchTvSeries');
    $tmdb->shouldNotReceive('getTvSeriesDetails');

    $job = new TestableFetchTmdbIds(
        seriesPlaylistId: $this->playlist->id,
        overwriteExisting: false,
        user: $this->user,
    );
    $job->handle($tmdb);
});

class TestableFetchTmdbIds extends FetchTmdbIds
{
    protected function sendCompletionNotification(): void {}

    protected function notifyUser(string $title, string $body, string $type = 'success'): void {}
}
