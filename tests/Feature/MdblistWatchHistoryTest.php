<?php

/**
 * MDBList as a watch tracking provider: device sign-in, scrobbles (episodes
 * nest the season inside `show`), paged history reads, and history export to
 * `/sync/watched`.
 */

use App\Jobs\PushWatchHistoryProgress;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\Series;
use App\Models\User;
use App\Models\ViewerWatchProgress;
use App\Models\WatchHistoryIntegration;
use App\Services\WatchHistory\WatchHistoryClient;
use App\Services\WatchHistory\WatchHistoryException;
use App\Services\WatchHistory\WatchHistoryScrobbler;
use App\Services\WatchHistory\WatchHistorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.mdblist.client_id' => 'mdblist-client']);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->playlistAuth = PlaylistAuth::create([
        'name' => 'Den',
        'username' => 'mdbuser',
        'password' => 'mdbpass',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlistAuth->assignTo($this->playlist);

    $this->viewer = PlaylistViewer::create([
        'ulid' => (string) Str::ulid(),
        'name' => 'Den',
        'is_admin' => false,
        'playlist_auth_id' => $this->playlistAuth->id,
        'viewerable_type' => $this->playlist->getMorphClass(),
        'viewerable_id' => $this->playlist->id,
    ]);

    $this->mdblist = WatchHistoryIntegration::factory()->mdblist()->for($this->user)->create();
    $this->mdblist->playlistAuths()->attach($this->playlistAuth);

    $this->movie = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'title' => 'The Matrix',
        'year' => 1999,
        'tmdb_id' => 603,
        'info' => ['duration_secs' => 8160],
    ]);
    $this->series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Breaking Bad',
        'tmdb_id' => 1396,
    ]);
    $this->episode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $this->series->id,
        'season' => 1,
        'episode_num' => 2,
        'info' => ['duration_secs' => 2820],
    ]);
});

function mdblistProgress(array $attributes = []): ViewerWatchProgress
{
    return ViewerWatchProgress::create([
        'playlist_viewer_id' => test()->viewer->id,
        'content_type' => 'vod',
        'stream_id' => test()->movie->id,
        'position_seconds' => 1800,
        'duration_seconds' => 3600,
        'completed' => false,
        'last_watched_at' => now(),
        ...$attributes,
    ]);
}

it('signs in with the device flow and saves the account name', function () {
    $integration = WatchHistoryIntegration::factory()->mdblist()->disconnected()->for($this->user)->create();
    Http::fakeSequence('api.mdblist.com/oauth/token/')
        ->push(['error' => 'authorization_pending'], 400)
        ->push(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 2592000]);
    Http::fake([
        'api.mdblist.com/oauth/device-authorization/' => Http::response([
            'device_code' => 'device-123',
            'user_code' => 'WDJB-MJHT',
            'verification_uri' => 'https://mdblist.com/oauth/device/',
            'verification_uri_complete' => 'https://mdblist.com/oauth/device/?user_code=WDJB-MJHT',
            'expires_in' => 300,
            'interval' => 5,
        ]),
        'api.mdblist.com/user' => Http::response(['username' => 'couchpotato', 'name' => 'Couch Potato']),
    ]);
    $client = WatchHistoryClient::make($integration);

    $start = $client->startDeviceAuth();

    expect($start)->toMatchArray([
        'user_code' => 'WDJB-MJHT',
        'verification_url' => 'https://mdblist.com/oauth/device/?user_code=WDJB-MJHT',
    ])
        ->and($client->pollDeviceAuth('device-123', ''))->toBe('pending')
        ->and($client->pollDeviceAuth('device-123', ''))->toBe('connected')
        ->and($integration->fresh())
        ->access_token->toBe('access-1')
        ->account_name->toBe('couchpotato');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.mdblist.com/oauth/token/'
        && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:device_code'
        && $request['client_id'] === 'mdblist-client'
        && $request['device_code'] === 'device-123');
});

it('explains a missing client id instead of calling MDBList', function () {
    config(['services.mdblist.client_id' => '']);

    expect(fn () => WatchHistoryClient::make($this->mdblist)->startDeviceAuth())
        ->toThrow(WatchHistoryException::class, 'MDBLIST_CLIENT_ID');
    Http::assertNothingSent();
});

it('scrobbles a movie in the Trakt shape', function () {
    Http::fake(['api.mdblist.com/scrobble/pause' => Http::response(['action' => 'pause'])]);

    expect(app(WatchHistoryScrobbler::class)->push($this->mdblist, mdblistProgress()))->toBe('pause');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.mdblist.com/scrobble/pause'
        && $request->hasHeader('Authorization', 'Bearer '.$this->mdblist->access_token)
        && $request['movie']['ids'] === ['tmdb' => 603]
        && (float) $request['progress'] === 50.0);
});

it('scrobbles an episode with the season and episode nested inside the show', function () {
    Http::fake(['api.mdblist.com/scrobble/stop' => Http::response(['action' => 'scrobble'])]);
    $progress = mdblistProgress([
        'content_type' => 'episode',
        'stream_id' => $this->episode->id,
        'series_id' => $this->series->id,
        'completed' => true,
    ]);

    expect(app(WatchHistoryScrobbler::class)->push($this->mdblist, $progress))->toBe('stop');

    Http::assertSent(fn (Request $request): bool => $request['show']['ids'] === ['tmdb' => 1396]
        && $request['show']['season'] === ['number' => 1, 'episode' => ['number' => 2]]
        && ! isset($request['episode']));
});

it('waits for the daily quota to reset when rate limited', function () {
    $this->freezeTime();
    Http::fake(['api.mdblist.com/scrobble/*' => Http::response(['error' => 'rate limit'], 429, [
        'X-RateLimit-Reset' => (string) now()->addMinutes(10)->getTimestamp(),
    ])]);
    $progress = mdblistProgress(['completed' => true]);
    $this->travel(2)->minutes();

    $job = (new PushWatchHistoryProgress($this->mdblist->id, $progress->id))->withFakeQueueInteractions();
    $job->handle(app(WatchHistoryScrobbler::class));

    $job->assertReleased(480);
});

it('imports watched movies and episodes across pages, plus resume points', function () {
    Http::fake([
        'api.mdblist.com/sync/watched?limit=1000&offset=0' => Http::response([
            'movies' => [['last_watched_at' => '2026-10-01T20:00:00.000Z', 'movie' => ['title' => 'The Matrix', 'ids' => ['tmdb' => 603, 'mdblist' => 'a0']]]],
            'shows' => [['show' => ['title' => 'Breaking Bad', 'ids' => ['tmdb' => 1396]], 'watched_episodes' => 1]],
            'seasons' => [],
            'episodes' => [],
            'pagination' => ['offset' => 0, 'limit' => 1000, 'has_more' => true],
        ]),
        'api.mdblist.com/sync/watched?limit=1000&offset=1000' => Http::response([
            'movies' => [],
            'shows' => [],
            'seasons' => [],
            'episodes' => [['last_watched_at' => '2026-10-02T21:00:00.000Z', 'episode' => [
                'season' => 1, 'number' => 2, 'ids' => ['tmdb' => 62086],
                'show' => ['title' => 'Breaking Bad', 'ids' => ['tmdb' => 1396, 'imdb' => 'tt0903747']],
            ]]],
            'pagination' => ['offset' => 1000, 'limit' => 1000, 'has_more' => false],
        ]),
        'api.mdblist.com/sync/playback' => Http::response([]),
    ]);

    $stats = app(WatchHistorySyncService::class)->run($this->mdblist, $this->playlistAuth, 'import');

    expect($stats)->toMatchArray(['imported' => 2, 'unmatched' => 0]);
    expect(ViewerWatchProgress::where('completed', true)->pluck('content_type')->sort()->values()->all())
        ->toBe(['episode', 'vod']);
});

it('exports watched titles to /sync/watched', function () {
    mdblistProgress(['completed' => true, 'last_watched_at' => now()->parse('2026-10-03 21:00:00')]);
    Http::fake([
        'api.mdblist.com/sync/watched?*' => Http::response(['movies' => [], 'shows' => [], 'seasons' => [], 'episodes' => [], 'pagination' => ['has_more' => false]]),
        'api.mdblist.com/sync/playback' => Http::response([]),
        'api.mdblist.com/sync/watched' => Http::response(['updated' => ['movies' => 1, 'shows' => 0, 'seasons' => 0, 'episodes' => 0]]),
    ]);

    $stats = app(WatchHistorySyncService::class)->run($this->mdblist, $this->playlistAuth, 'export');

    expect($stats['exported'])->toBe(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.mdblist.com/sync/watched'
        && $request['movies'][0]['ids'] === ['tmdb' => 603]);
});
