<?php

/**
 * Playlist preserve_series_metadata / preserve_vod_metadata (#1654): with it on, provider syncs
 * keep the metadata stored on existing movies, series, seasons and episodes (manual edits or
 * TMDB), while new content still imports and playback fields keep updating.
 */

use App\Jobs\ProcessM3uVodImportChunk;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Group;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function preserveMetadataPlaylist(User $user, array $overrides = []): Playlist
{
    return Playlist::withoutEvents(fn (): Playlist => Playlist::factory()->for($user)->create(array_merge([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://provider.test',
            'username' => 'user',
            'password' => 'pass',
        ],
    ], $overrides)));
}

function preserveMetadataSeriesResponse(array $episodes, array $info = []): array
{
    return [
        'info' => array_merge([
            'name' => 'Provider Name',
            'plot' => 'Provider plot',
            'cover' => 'http://provider.test/cover.jpg',
            'genre' => 'Provider Genre',
            'last_modified' => (string) now()->addHour()->timestamp,
        ], $info),
        'seasons' => [
            ['id' => 101, 'season_number' => 1, 'name' => 'Provider Season', 'cover' => 'http://provider.test/season.jpg'],
        ],
        'episodes' => ['1' => $episodes],
    ];
}

function preserveMetadataEpisode(int $id, int $number, array $info = [], ?string $added = '1700000000'): array
{
    return [
        'id' => $id,
        'episode_num' => $number,
        'title' => "S01E0{$number} - Provider Episode {$number}",
        'container_extension' => 'mkv',
        'added' => $added,
        'info' => $info,
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('series', function () {
    beforeEach(function () {
        $this->playlist = preserveMetadataPlaylist($this->user, ['preserve_series_metadata' => true]);
        $this->category = Category::factory()->for($this->user)->for($this->playlist)->create();
        $this->series = Series::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'category_id' => $this->category->id,
            'source_series_id' => 123,
            'enabled' => true,
            'name' => 'My Name',
            'plot' => 'My plot',
            'cover' => 'http://local.test/cover.jpg',
            'genre' => null,
            'metadata' => ['plot' => 'My plot', 'last_modified' => '1'],
            'last_metadata_fetch' => now()->subDay(),
            'last_modified' => now()->subDays(2),
        ]);
        $this->season = Season::create([
            'season_number' => 1,
            'name' => 'My Season',
            'cover' => 'http://local.test/season.jpg',
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'series_id' => $this->series->id,
            'category_id' => $this->category->id,
            'import_batch_no' => 'batch-0',
        ]);
        $this->episode = Episode::create([
            'title' => 'My Episode Title',
            'import_batch_no' => 'batch-0',
            'source_episode_id' => 1001,
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'series_id' => $this->series->id,
            'season_id' => $this->season->id,
            'episode_num' => 1,
            'season' => 1,
            'added' => '1600000000',
            'url' => 'http://provider.test/old.mkv',
            'container_extension' => 'mp4',
            'info' => ['plot' => 'My episode plot', 'movie_image' => 'http://local.test/still.jpg', 'duration_secs' => 100, 'season' => 9],
        ]);
    });

    it('keeps stored series, season and episode data while importing new episodes', function () {
        $storedAdded = $this->episode->added;
        Http::preventStrayRequests();
        Http::fake(['provider.test/player_api.php*' => Http::response(preserveMetadataSeriesResponse([
            preserveMetadataEpisode(1001, 1, ['plot' => 'Provider episode plot', 'duration_secs' => 1500]),
            preserveMetadataEpisode(1002, 2, ['plot' => 'New episode plot']),
        ]))]);

        expect($this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false))->toBeTrue();

        $series = $this->series->refresh();
        expect($series->name)->toBe('My Name')
            ->and($series->plot)->toBe('My plot')
            ->and($series->cover)->toBe('http://local.test/cover.jpg')
            ->and($series->genre)->toBe('Provider Genre')
            ->and($series->metadata['plot'])->toBe('My plot')
            ->and($series->last_modified->gt(now()))->toBeTrue()
            ->and($series->metadata['last_modified'])->not->toBe('1');

        $season = $this->season->refresh();
        expect($season->name)->toBe('My Season')
            ->and($season->getRawOriginal('cover'))->toBe('http://local.test/season.jpg');

        $episode = $this->episode->refresh();
        expect($episode->title)->toBe('My Episode Title')
            ->and($episode->getRawOriginal('added'))->toBe('1600000000')
            ->and($episode->info['plot'])->toBe('My episode plot')
            ->and($episode->info['movie_image'])->toBe('http://local.test/still.jpg')
            ->and($episode->info['duration_secs'])->toBe(1500)
            ->and($episode->info['season'])->toBe(1)
            ->and($episode->container_extension)->toBe('mkv')
            ->and($episode->url)->not->toBe('http://provider.test/old.mkv');

        $newEpisode = Episode::where('source_episode_id', 1002)->sole();
        expect($newEpisode->title)->toBe('Provider Episode 2')
            ->and($newEpisode->info['plot'])->toBe('New episode plot');
    });

    it('lets an explicit overwrite replace the stored data', function () {
        Http::fake(['provider.test/player_api.php*' => Http::response(preserveMetadataSeriesResponse([
            preserveMetadataEpisode(1001, 1, ['plot' => 'Provider episode plot']),
        ]))]);

        $this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false, overwrite: true);

        expect($this->series->refresh()->name)->toBe('Provider Name')
            ->and($this->season->refresh()->name)->toBe('Provider Season')
            ->and($this->episode->refresh()->title)->toBe('Provider Episode 1')
            ->and($this->episode->info['plot'])->toBe('Provider episode plot');
    });

    it('imports provider data normally on the first fetch', function () {
        $this->series->update(['last_metadata_fetch' => null]);
        Http::fake(['provider.test/player_api.php*' => Http::response(preserveMetadataSeriesResponse([
            preserveMetadataEpisode(1001, 1, ['plot' => 'Provider episode plot']),
        ]))]);

        $this->series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false);

        expect($this->series->refresh()->name)->toBe('Provider Name')
            ->and($this->episode->refresh()->title)->toBe('Provider Episode 1');
    });

    it('leaves provider behavior unchanged when the setting is off', function () {
        $this->playlist->update(['preserve_series_metadata' => false]);
        $storedAdded = $this->episode->added;
        Http::fake(['provider.test/player_api.php*' => Http::response(preserveMetadataSeriesResponse([
            preserveMetadataEpisode(1001, 1, ['plot' => 'Provider episode plot']),
        ]))]);

        $this->series->fresh()->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false);

        $episode = $this->episode->refresh();
        expect($this->series->refresh()->name)->toBe('Provider Name')
            ->and($episode->title)->toBe('Provider Episode 1')
            ->and($episode->added)->not->toEqual($storedAdded)
            ->and($episode->info['plot'])->toBe('Provider episode plot')
            ->and($episode->info['movie_image'])->toBeNull();
    });

    it('keeps TMDB episode data the provider sends blank, even with the setting off', function () {
        $this->playlist->update(['preserve_series_metadata' => false]);
        $this->episode->update([
            'tmdb_id' => 555,
            'info' => ['plot' => 'TMDB plot', 'movie_image' => 'https://image.tmdb.org/still.jpg', 'releasedate' => '2020-01-01'],
        ]);
        Http::fake(['provider.test/player_api.php*' => Http::response(preserveMetadataSeriesResponse([
            preserveMetadataEpisode(1001, 1, ['duration_secs' => 1500]),
        ]))]);

        $this->series->fresh()->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false);

        $info = $this->episode->refresh()->info;
        expect($info['plot'])->toBe('TMDB plot')
            ->and($info['movie_image'])->toBe('https://image.tmdb.org/still.jpg')
            ->and($info['releasedate'])->toBe('2020-01-01')
            ->and($info['duration_secs'])->toBe(1500);
    });
});

describe('vod', function () {
    beforeEach(function () {
        $this->tempJobsDb = sys_get_temp_dir().'/jobs_test_'.uniqid('', true).'.sqlite';
        touch($this->tempJobsDb);
        config(['database.connections.jobs.database' => $this->tempJobsDb]);
        DB::purge('jobs');
        (require database_path('migrations/2025_02_13_215803_create_jobs_table.php'))->up();
    });

    afterEach(function () {
        DB::purge('jobs');
        config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);
        @unlink($this->tempJobsDb);
    });

    it('keeps stored info and added date when the provider details are refreshed', function () {
        $playlist = preserveMetadataPlaylist($this->user, ['preserve_vod_metadata' => true]);
        $channel = Channel::factory()->for($playlist)->for($this->user)->create([
            'is_vod' => true,
            'source_id' => 'vod-1',
            'last_metadata_fetch' => now()->subDay(),
            'info' => ['plot' => 'My plot', 'genre' => null],
            'movie_data' => ['stream_id' => 1, 'added' => '1600000000'],
        ]);

        $xtream = new class
        {
            public function getVodInfo(string $vodId, int $timeout = 60): array
            {
                return [
                    'info' => ['plot' => 'Provider plot', 'genre' => 'Drama'],
                    'movie_data' => ['stream_id' => 1, 'added' => '1700000000', 'container_extension' => 'mkv'],
                ];
            }
        };

        expect($channel->fetchMetadata($xtream, refresh: true, skipTmdb: true))->toBeTrue();

        $channel->refresh();
        expect($channel->info['plot'])->toBe('My plot')
            ->and($channel->info['genre'])->toBe('Drama')
            ->and($channel->movie_data['added'])->toBe('1600000000')
            ->and($channel->movie_data['container_extension'])->toBe('mkv');
    });

    it('keeps the stored rating, year and added date on existing movies during import', function (bool $preserve) {
        $playlist = preserveMetadataPlaylist($this->user, ['preserve_vod_metadata' => $preserve]);
        $group = Group::factory()->for($playlist)->for($this->user)->create(['type' => 'vod']);
        $channel = Channel::factory()->for($playlist)->for($this->user)->create([
            'is_vod' => true,
            'source_id' => '100',
            'group_id' => $group->id,
            'url' => 'http://provider.test/old.mkv',
            'rating' => '9.9',
            'year' => 1999,
            'source_added_at' => '2020-01-01 00:00:00',
        ]);

        $job = Job::create([
            'title' => 'VOD import',
            'batch_no' => 'batch-1',
            'payload' => [[
                'name' => $channel->name,
                'title' => $channel->title,
                'url' => 'http://provider.test/new.mkv',
                'source_id' => '100',
                'playlist_id' => $playlist->id,
                'user_id' => $this->user->id,
                'is_vod' => true,
                'rating' => '5.0',
                'year' => 2001,
                'source_added_at' => '2024-01-01 00:00:00',
            ]],
            'variables' => [
                'groupId' => $group->id,
                'groupName' => $group->name,
                'playlistId' => $playlist->id,
                'type' => 'vod',
                'autoSort' => false,
            ],
        ]);

        (new ProcessM3uVodImportChunk([$job->id], 1))->handle();

        $channel->refresh();
        expect($channel->url)->toBe('http://provider.test/new.mkv')
            ->and((string) $channel->rating)->toBe($preserve ? '9.9' : '5.0')
            ->and((int) $channel->year)->toBe($preserve ? 1999 : 2001)
            ->and($channel->source_added_at->year)->toBe($preserve ? 2020 : 2024);
    })->with(['preserve on' => true, 'preserve off' => false]);
});
