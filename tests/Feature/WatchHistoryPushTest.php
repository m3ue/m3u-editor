<?php

/**
 * Server-side Trakt / SIMKL scrobbling: progress saved for a Playlist Auth
 * viewer is pushed, once it has gone quiet, to the accounts attached to that
 * auth, unless the client says it scrobbles to that provider itself.
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
use App\Models\WatchHistorySyncState;
use App\Services\WatchHistory\SimklClient;
use App\Services\WatchHistory\WatchHistoryScrobbler;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->playlistAuth = PlaylistAuth::create([
        'name' => 'Living Room',
        'username' => 'whuser',
        'password' => 'whpass',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($this->playlistAuth);

    $this->viewer = PlaylistViewer::create([
        'ulid' => (string) Str::ulid(),
        'name' => 'Living Room',
        'is_admin' => false,
        'playlist_auth_id' => $this->playlistAuth->id,
        'viewerable_type' => $this->playlist->getMorphClass(),
        'viewerable_id' => $this->playlist->id,
    ]);

    $this->trakt = WatchHistoryIntegration::factory()->for($this->user)->create();
    $this->trakt->playlistAuths()->attach($this->playlistAuth);

    $this->movie = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'title' => 'The Matrix',
        'year' => 1999,
        'tmdb_id' => 603,
        'imdb_id' => 'tt0133093',
    ]);
});

function watchHistoryUpdateProgress(array $body): void
{
    test()->postJson(route('xtream.api.player'), [
        'username' => 'whuser',
        'password' => 'whpass',
        'action' => 'update_progress',
        ...$body,
    ])->assertOk();
}

function watchHistoryProgress(array $attributes = []): ViewerWatchProgress
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

it('queues a delayed push for each attached account when an auth viewer saves progress', function () {
    Bus::fake();

    watchHistoryUpdateProgress([
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'vod',
        'stream_id' => (string) $this->movie->id,
        'position_seconds' => '600',
        'duration_seconds' => '3600',
    ]);

    Bus::assertDispatched(PushWatchHistoryProgress::class, fn (PushWatchHistoryProgress $job): bool => $job->integrationId === $this->trakt->id
        && $job->delay !== null);
});

it('skips a provider the client scrobbles to itself', function () {
    Bus::fake();
    $simkl = WatchHistoryIntegration::factory()->simkl()->for($this->user)->create();
    $simkl->playlistAuths()->attach($this->playlistAuth);

    watchHistoryUpdateProgress([
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'vod',
        'stream_id' => (string) $this->movie->id,
        'position_seconds' => '600',
        'duration_seconds' => '3600',
        'client_scrobblers' => 'trakt',
    ]);

    Bus::assertDispatchedTimes(PushWatchHistoryProgress::class, 1);
    Bus::assertDispatched(PushWatchHistoryProgress::class, fn (PushWatchHistoryProgress $job): bool => $job->integrationId === $simkl->id);
});

it('does not queue pushes for live channels, unattached auths or paused accounts', function () {
    Bus::fake();
    $live = Channel::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'is_vod' => false]);

    watchHistoryUpdateProgress([
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'live',
        'stream_id' => (string) $live->id,
    ]);
    Bus::assertNotDispatched(PushWatchHistoryProgress::class);

    $this->trakt->update(['enabled' => false]);
    watchHistoryUpdateProgress([
        'viewer_id' => $this->viewer->ulid,
        'content_type' => 'vod',
        'stream_id' => (string) $this->movie->id,
        'position_seconds' => '600',
        'duration_seconds' => '3600',
    ]);
    Bus::assertNotDispatched(PushWatchHistoryProgress::class);
});

it('advertises the attached accounts to M3U TV in the m3u_editor payload', function () {
    $settings = app(GeneralSettings::class);
    $settings->app_output_enabled = true;
    $settings->save();

    $this->trakt->update(['account_name' => 'couchpotato']);
    WatchHistoryIntegration::factory()->simkl()->disconnected()->for($this->user)->create()
        ->playlistAuths()->attach($this->playlistAuth);

    $response = $this->getJson(route('xtream.api.player', ['username' => 'whuser', 'password' => 'whpass']));

    $response->assertOk()
        ->assertJsonPath('m3u_editor.watch_history', [
            ['provider' => 'trakt', 'account' => 'couchpotato'],
        ]);
});

it('waits for the progress row to go quiet before pushing', function () {
    $progress = watchHistoryProgress();

    $job = (new PushWatchHistoryProgress($this->trakt->id, $progress->id))->withFakeQueueInteractions();
    $job->handle(app(WatchHistoryScrobbler::class));

    $job->assertReleased();
    Http::assertNothingSent();
});

it('sends a pause with the position once playback stops part-way', function () {
    Http::fake(['api.trakt.tv/scrobble/pause' => Http::response(['action' => 'pause'], 201)]);
    $progress = watchHistoryProgress();
    $this->travel(2)->minutes();

    $job = (new PushWatchHistoryProgress($this->trakt->id, $progress->id))->withFakeQueueInteractions();
    $job->handle(app(WatchHistoryScrobbler::class));

    $job->assertNotReleased();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.trakt.tv/scrobble/pause'
        && $request['progress'] == 50
        && $request['movie']['ids'] === ['tmdb' => 603, 'imdb' => 'tt0133093']
        && $request->hasHeader('trakt-api-key'));

    expect(WatchHistorySyncState::first())
        ->completed->toBeFalse()
        ->progress->toBe(50.0);
});

it('marks a finished title watched once, and again after a rewatch', function () {
    Http::fake([
        'api.trakt.tv/scrobble/stop' => Http::response(['action' => 'scrobble'], 201),
        'api.trakt.tv/scrobble/pause' => Http::response(['action' => 'pause'], 201),
    ]);
    $progress = watchHistoryProgress(['position_seconds' => 3400, 'completed' => true]);
    $scrobbler = app(WatchHistoryScrobbler::class);

    expect($scrobbler->push($this->trakt, $progress))->toBe('stop')
        ->and($scrobbler->push($this->trakt, $progress))->toBe('unchanged');

    $progress->update(['position_seconds' => 900, 'completed' => false]);
    expect($scrobbler->push($this->trakt, $progress))->toBe('pause');

    $progress->update(['position_seconds' => 3500, 'completed' => true]);
    expect($scrobbler->push($this->trakt, $progress))->toBe('stop');

    Http::assertSentCount(3);
});

it('sends episodes by their show ids with season and episode numbers', function () {
    Http::fake(['api.trakt.tv/scrobble/stop' => Http::response([], 201)]);
    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Breaking Bad',
        'tmdb_id' => 1396,
        'tvdb_id' => 81189,
    ]);
    $episode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'season' => 2,
        'episode_num' => 3,
    ]);
    $progress = watchHistoryProgress([
        'content_type' => 'episode',
        'stream_id' => $episode->id,
        'series_id' => $series->id,
        'completed' => true,
    ]);

    app(WatchHistoryScrobbler::class)->push($this->trakt, $progress);

    Http::assertSent(fn (Request $request): bool => $request['show']['ids'] === ['tmdb' => 1396, 'tvdb' => 81189]
        && $request['episode'] === ['season' => 2, 'number' => 3]);
});

it('skips titles the library has no external id for', function () {
    $untagged = Channel::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'is_vod' => true, 'tmdb_id' => null, 'imdb_id' => null, 'info' => null, 'movie_data' => null]);
    $progress = watchHistoryProgress(['stream_id' => $untagged->id, 'completed' => true]);

    expect(app(WatchHistoryScrobbler::class)->push($this->trakt, $progress))->toBe('unidentified');
    Http::assertNothingSent();
});

it('retries after SIMKL\'s write lock', function () {
    Http::fake(['api.simkl.com/scrobble/*' => Http::response(['error' => 'RATE_LIMIT'], 400)]);
    $simkl = WatchHistoryIntegration::factory()->simkl()->for($this->user)->create();
    $progress = watchHistoryProgress(['completed' => true]);
    $this->travel(2)->minutes();

    $job = (new PushWatchHistoryProgress($simkl->id, $progress->id))->withFakeQueueInteractions();
    $job->handle(app(WatchHistoryScrobbler::class));

    $job->assertReleased(SimklClient::WRITE_LOCK_SECONDS);
    expect(WatchHistorySyncState::count())->toBe(0);
});

it('refreshes an expired token before pushing', function () {
    Http::fake([
        'api.trakt.tv/oauth/token' => Http::response(['access_token' => 'fresh-token', 'refresh_token' => 'fresh-refresh', 'expires_in' => 86400]),
        'api.trakt.tv/scrobble/stop' => Http::response([], 201),
    ]);
    $this->trakt->update(['token_expires_at' => now()->subMinute()]);

    app(WatchHistoryScrobbler::class)->push($this->trakt, watchHistoryProgress(['completed' => true]));

    expect($this->trakt->fresh()->access_token)->toBe('fresh-token');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/scrobble/stop')
        && $request->hasHeader('Authorization', 'Bearer fresh-token'));
});

it('signs the account out when its refresh token is rejected', function () {
    Http::fake([
        'api.trakt.tv/scrobble/stop' => Http::response([], 401),
        'api.trakt.tv/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);
    $progress = watchHistoryProgress(['completed' => true]);
    $this->travel(2)->minutes();

    $job = (new PushWatchHistoryProgress($this->trakt->id, $progress->id))->withFakeQueueInteractions();
    $job->handle(app(WatchHistoryScrobbler::class));

    $job->assertNotReleased();
    expect($this->trakt->fresh())
        ->isConnected()->toBeFalse()
        ->last_error->not->toBeNull();
});
