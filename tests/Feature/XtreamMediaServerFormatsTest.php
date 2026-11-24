<?php

use App\Interfaces\MediaServer;
use App\Jobs\FetchTmdbIds;
use App\Jobs\SyncMediaServer;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Services\MediaServerService;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use App\Support\ReleaseDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $auth = PlaylistAuth::create([
        'name' => 'Formats',
        'username' => 'formats_user',
        'password' => 'formats_pass',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($auth);

    $this->integration = MediaServerIntegration::create([
        'name' => 'Jellyfin',
        'type' => 'jellyfin',
        'host' => '10.0.0.2',
        'port' => 8096,
        'api_key' => 'k',
        'enabled' => true,
        'ssl' => false,
        'genre_handling' => 'primary',
        'import_movies' => true,
        'import_series' => true,
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $this->series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'release_date' => '1999-09-22T00:00:00.0000000Z',
        'metadata' => [],
        'last_modified' => now(),
    ]);
    $this->season = Season::factory()->create([
        'series_id' => $this->series->id,
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'season_number' => 1,
        'cover_big' => null,
    ]);
});

function formatsApi(string $action, array $params = []): string
{
    return route('xtream.api.player').'?'.http_build_query([
        'username' => 'formats_user', 'password' => 'formats_pass', 'action' => $action, ...$params,
    ]);
}

function formatsEpisode(object $test, array $attributes): Episode
{
    return Episode::factory()->create([
        'user_id' => $test->user->id,
        'playlist_id' => $test->playlist->id,
        'series_id' => $test->series->id,
        'season_id' => $test->season->id,
        'season' => 1,
        'enabled' => true,
        ...$attributes,
    ]);
}

function formatsSyncEpisode(object $test, array $data, string $image = 'http://server/img'): Episode
{
    $service = Mockery::mock(MediaServer::class);
    $service->shouldReceive('getContainerExtension')->andReturn('mkv');
    $service->shouldReceive('getStreamUrl')->andReturn('http://server/stream.mkv');
    $service->shouldReceive('getImageUrl')->andReturn($image);
    $service->shouldReceive('ticksToSeconds')->andReturn(1200);

    $job = new SyncMediaServer($test->integration->id);
    (new ReflectionProperty($job, 'batchNo'))->setValue($job, 'batch-1');
    (new ReflectionMethod($job, 'syncEpisode'))->invoke(
        $job, $test->integration, $test->playlist, $service, $test->series, $test->season,
        ['Id' => 'e1', 'Name' => 'Pilot', 'IndexNumber' => 1, ...$data],
    );

    return Episode::where('series_id', $test->series->id)->sole();
}

it('normalizes only ISO timestamps', function (mixed $value, mixed $expected) {
    expect(ReleaseDate::normalize($value))->toBe($expected);
})->with([
    ['2018-12-06T00:00:00.0000000Z', '2018-12-06'],
    ['2018-12-06T23:30:00-05:00', '2018-12-06'],
    ['0001-01-01T00:00:00.0000000Z', null],
    ['2018-12-06', '2018-12-06'],
    ['2018', '2018'],
    [2018, 2018],
    ['', ''],
    [null, null],
]);

it('stores the episode added date and plain release date, keeping added when the server omits it', function () {
    $episode = formatsSyncEpisode($this, [
        'DateCreated' => '2021-03-04T05:06:07.0000000Z',
        'PremiereDate' => '1999-09-22T00:00:00.0000000Z',
    ]);

    expect($episode->getRawOriginal('added'))->toBe('1614834367')
        ->and($episode->info['release_date'])->toBe('1999-09-22');

    expect(formatsSyncEpisode($this, [])->getRawOriginal('added'))->toBe('1614834367');
});

it('requests DateCreated and ProviderIds for Jellyfin episodes', function () {
    Http::preventStrayRequests();
    Http::fake(['http://10.0.0.2:8096/*' => Http::response(['Items' => []])]);

    MediaServerService::make($this->integration)->fetchEpisodes('s1', 'season1');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/Shows/s1/Episodes')
        && str_contains($request['Fields'] ?? '', 'DateCreated')
        && str_contains($request['Fields'] ?? '', 'ProviderIds'));
});

it('returns string timestamps and plain dates for already synced episodes', function () {
    $undated = formatsEpisode($this, [
        'episode_num' => 1,
        'added' => null,
        'info' => ['release_date' => '1999-09-22T00:00:00.0000000Z', 'movie_image' => 'http://server/one.jpg'],
    ]);
    formatsEpisode($this, ['episode_num' => 2, 'added' => '1640995200', 'info' => []]);

    $this->getJson(formatsApi('get_series_info', ['series_id' => $this->series->id]))
        ->assertOk()
        ->assertJsonPath('info.releaseDate', '1999-09-22')
        ->assertJsonPath('episodes.1.0.added', (string) $undated->created_at->timestamp)
        ->assertJsonPath('episodes.1.0.info.release_date', '1999-09-22')
        ->assertJsonPath('episodes.1.0.info.cover_big', 'http://server/one.jpg')
        ->assertJsonPath('episodes.1.1.added', '1640995200')
        // Art must not carry over from the previous episode.
        ->assertJsonPath('episodes.1.1.info.movie_image', null)
        ->assertJsonPath('episodes.1.1.info.cover_big', null);

    $this->getJson(formatsApi('get_series'))->assertOk()->assertJsonPath('0.releaseDate', '1999-09-22');
});

it('returns the TMDB id and plain dates for already synced movies', function () {
    $channel = Channel::factory()->for($this->playlist)->for(Group::factory()->for($this->user))->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'is_vod' => true,
        'year' => 2018,
        'tmdb_id' => 546630,
        'last_metadata_fetch' => now(),
        'info' => ['release_date' => '2018-12-06T00:00:00.0000000Z'],
    ]);

    $streams = json_decode($this->get(formatsApi('get_vod_streams'))->streamedContent(), true);
    expect($streams[0]['tmdb'])->toBe('546630')->and($streams[0]['tmdb_id'])->toBe(546630);

    $this->getJson(formatsApi('get_vod_info', ['vod_id' => $channel->id]))
        ->assertOk()
        ->assertJsonPath('info.tmdb_id', 546630)
        ->assertJsonPath('info.release_date', '2018-12-06')
        ->assertJsonPath('info.releasedate', '2018-12-06');
});

it('still runs TMDB enrichment on a series whose episodes carry the server TMDB id', function () {
    $this->mock(GeneralSettings::class, function ($mock) {
        $mock->tmdb_api_key = 'fake-api-key';
        $mock->tmdb_language = 'en-US';
        $mock->tmdb_rate_limit = 40;
        $mock->tmdb_confidence_threshold = 80;
        $mock->tmdb_auto_create_groups = false;
    });
    Http::preventStrayRequests();
    Http::fake([
        'https://api.themoviedb.org/3/tv/1396/season/1*' => Http::response([
            'id' => 139601,
            'season_number' => 1,
            'episodes' => [['id' => 62085, 'episode_number' => 1, 'name' => 'Pilot', 'vote_count' => 50]],
        ]),
        'https://api.themoviedb.org/3/tv/1396*' => Http::response([
            'id' => 1396,
            'name' => 'Breaking Bad',
            'overview' => 'Overview.',
            'poster_path' => '/bb.jpg',
            'seasons' => [['season_number' => 1, 'episode_count' => 1]],
            'credits' => ['cast' => [['id' => 17419, 'name' => 'Bryan Cranston', 'character' => 'Walter White', 'profile_path' => null]]],
            'recommendations' => ['results' => [['id' => 60059, 'name' => 'Better Call Saul']]],
        ]),
    ]);
    $this->series->update([
        'tmdb_id' => 1396,
        'plot' => 'Server plot.',
        'cover' => 'http://server/cover.jpg',
        'last_metadata_fetch' => now(),
        'metadata' => ['media_server_id' => 's1', 'cast_list' => [['id' => null, 'name' => 'Bryan Cranston']]],
    ]);

    // The server's id reaches info, but the column (the "TMDB done" gate) stays empty.
    $episode = formatsSyncEpisode($this, ['ProviderIds' => ['Tmdb' => '999']]);
    expect($episode->info['tmdb_id'])->toBe(999)->and($episode->tmdb_id)->toBeNull();

    app(FetchTmdbIds::class)->processSingleSeries(app(TmdbService::class), $this->series->refresh());

    $metadata = $this->series->refresh()->metadata;
    expect($metadata['cast_list'][0]['id'])->toBe(17419)
        ->and($metadata['related_tmdb'][0]['tmdb_id'])->toBe(60059)
        ->and($episode->refresh()->tmdb_id)->toBe(62085)
        ->and($episode->info['vote_count'])->toBe(50);

    // The next sync keeps TMDB's match over the server's id.
    $resynced = formatsSyncEpisode($this, ['ProviderIds' => ['Tmdb' => '999']]);
    expect($resynced->tmdb_id)->toBe(62085)->and($resynced->info['tmdb_id'])->toBe(62085);

    // As does a sync where the server sends no ids.
    expect(formatsSyncEpisode($this, [])->info['tmdb_id'])->toBe(62085);
});

it('keeps TMDB data on a matched episode over later syncs', function () {
    $episode = formatsSyncEpisode($this, ['CommunityRating' => 5.0]);
    $still = 'https://image.tmdb.org/t/p/original/still.jpg';
    $episode->update([
        'tmdb_id' => 62085,
        'plot' => 'TMDB plot.',
        'cover' => $still,
        'info' => [
            ...$episode->info,
            'movie_image' => $still,
            'rating' => 8.8,
            'vote_count' => 50,
            'releasedate' => '2008-01-20',
            'plot' => 'TMDB plot.',
        ],
    ]);

    $synced = formatsSyncEpisode($this, ['CommunityRating' => 5.0]);
    expect($synced->cover)->toBe($still)
        ->and($synced->plot)->toBe('TMDB plot.')
        ->and($synced->info)->toMatchArray([
            'movie_image' => $still,
            'rating' => 8.8,
            'vote_count' => 50,
            'releasedate' => '2008-01-20',
            'plot' => 'TMDB plot.',
            'duration_secs' => 1200,
        ]);

    // The server's own plot still wins when it has one, as it does for a TMDB fetch.
    expect(formatsSyncEpisode($this, ['Overview' => 'Server plot.'])->plot)->toBe('Server plot.');

    // Local/WebDAV servers send no artwork at all.
    expect(formatsSyncEpisode($this, [], '')->cover)->toBe($still);
});

it('refreshes server values on an episode TMDB has not matched, and server art on any episode', function () {
    $episode = formatsSyncEpisode($this, ['CommunityRating' => 5.0, 'Overview' => 'Old.'], 'http://old/img');

    $synced = formatsSyncEpisode($this, ['CommunityRating' => 6.0]);
    expect($synced->cover)->toBe('http://server/img')
        ->and($synced->plot)->toBeNull()
        ->and($synced->info['rating'])->toEqual(6.0);

    $synced->update(['tmdb_id' => 62085]);
    $matched = formatsSyncEpisode($this, ['CommunityRating' => 7.0], 'http://new/img');
    expect($matched->cover)->toBe('http://new/img')
        ->and($matched->info['movie_image'])->toBe('http://new/img')
        ->and($matched->info['rating'])->toEqual(7.0);
});
