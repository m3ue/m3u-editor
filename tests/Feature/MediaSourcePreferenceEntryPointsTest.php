<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use App\Services\M3uProxyService;
use App\Services\MediaSourceMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();

    // Array cache: unique-job locks (ShouldBeUnique) would otherwise persist
    // in Redis across test runs while DB ids reset, silently dropping dispatches.
    config()->set('cache.default', 'array');
    Cache::flush();

    $this->user = User::factory()->create([
        'name' => 'testuser',
        'permissions' => ['use_proxy'],
    ]);

    $this->provider = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => false,
        'prefer_media_server_sources' => true,
    ]);
    $this->media = Playlist::factory()->for($this->user)->create();
    $this->integration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'emby',
        'enabled' => true,
        'playlist_id' => $this->media->id,
    ]);
});

function fakeProxyForStreamCreation(): void
{
    config([
        'proxy.m3u_proxy_host' => 'http://localhost',
        'proxy.m3u_proxy_port' => 8765,
        'proxy.m3u_proxy_token' => 'test-token',
    ]);
    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => []]),
        '*/streams' => Http::response(['stream_id' => 'media-stream']),
    ]);
    Redis::shouldReceive('exists')->andReturn(0);
}

function makeMatchedEpisodes($test): array
{
    $mediaSeries = Series::factory()->for($test->media)->for($test->user)->create(['enabled' => true, 'tmdb_id' => 1399]);
    $mediaEpisode = Episode::factory()->for($test->media)->for($test->user)->create([
        'series_id' => $mediaSeries->id,
        'season_id' => Season::factory()->create(['series_id' => $mediaSeries->id, 'playlist_id' => $test->media->id, 'season_number' => 1])->id,
        'season' => 1,
        'episode_num' => 1,
        'enabled' => true,
        'url' => 'http://app.test/media-server/2/stream/def.mp4',
    ]);

    $providerSeries = Series::factory()->for($test->provider)->for($test->user)->create(['enabled' => true, 'tmdb_id' => 1399]);
    $providerEpisode = Episode::factory()->for($test->provider)->for($test->user)->create([
        'series_id' => $providerSeries->id,
        'season_id' => Season::factory()->create(['series_id' => $providerSeries->id, 'playlist_id' => $test->provider->id, 'season_number' => 1])->id,
        'season' => 1,
        'episode_num' => 1,
        'enabled' => true,
        'url' => 'http://provider.test/series/1/1.mp4',
        'container_extension' => 'mp4',
    ]);

    app(MediaSourceMatchService::class)->rebuildForPlaylist($test->provider);
    Cache::put("media-server-reachable:{$test->integration->id}", true, 60);

    return [$providerEpisode, $mediaEpisode];
}

function makeMatchedMovies($test): array
{
    $mediaMovie = Channel::factory()->for($test->media)->for($test->user)->create([
        'enabled' => true,
        'is_vod' => true,
        'tmdb_id' => 603,
        'url' => 'http://app.test/media-server/1/stream/abc.mkv',
        'enable_proxy' => false,
    ]);
    $providerMovie = Channel::factory()->for($test->provider)->for($test->user)->create([
        'enabled' => true,
        'is_vod' => true,
        'tmdb_id' => 603,
        'url' => 'http://provider.test/movie/123.mkv',
        'container_extension' => 'mkv',
    ]);

    app(MediaSourceMatchService::class)->rebuildForPlaylist($test->provider);

    // The resolver checks media-server reachability before swapping.
    Cache::put("media-server-reachable:{$test->integration->id}", true, 60);

    return [$providerMovie, $mediaMovie];
}

test('handleVod redirects to the media URL when a match exists', function () {
    [$providerMovie, $mediaMovie] = makeMatchedMovies($this);

    $this->get("/movie/{$this->user->name}/{$this->provider->uuid}/{$providerMovie->id}.mkv")
        ->assertRedirect('http://app.test/media-server/1/stream/abc.mkv');
});

test('handleVod redirects to the provider URL when no match exists', function () {
    $providerMovie = Channel::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true,
        'is_vod' => true,
        'tmdb_id' => 999,
        'url' => 'http://provider.test/movie/123.mkv',
        'container_extension' => 'mkv',
    ]);

    $this->get("/movie/{$this->user->name}/{$this->provider->uuid}/{$providerMovie->id}.mkv")
        ->assertRedirect('http://provider.test/movie/123.mkv');
});

test('handleSeries redirects to the media episode URL when matched', function () {
    [$providerEpisode] = makeMatchedEpisodes($this);

    $this->get("/series/{$this->user->name}/{$this->provider->uuid}/{$providerEpisode->id}.mp4")
        ->assertRedirect('http://app.test/media-server/2/stream/def.mp4');
});

test('a proxied media swap tags the stream with the episode the client asked for', function () {
    [$providerEpisode, $mediaEpisode] = makeMatchedEpisodes($this);
    fakeProxyForStreamCreation();

    $this->get("/series/{$this->user->name}/{$this->provider->uuid}/{$providerEpisode->id}.mp4?proxy=true")
        ->assertRedirect();

    // The TV app stops its stream by the episode it asked for, not the media copy serving it.
    Http::assertSent(fn (ClientRequest $request) => $request->method() === 'POST'
        && ($request['metadata']['original_episode_id'] ?? null) === $mediaEpisode->id
        && ($request['metadata']['requested_episode_id'] ?? null) === $providerEpisode->id);
});

test('the proxy entry point swaps to the media playlist so no provider slot is used', function () {
    [$providerMovie, $mediaMovie] = makeMatchedMovies($this);

    $providerMovie->update(['enable_proxy' => true]);

    $captured = null;
    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getChannelUrl')
        ->once()
        ->withArgs(function ($playlist, $channel) use (&$captured) {
            $captured = [$playlist->id, $channel->id];

            return true;
        })
        ->andReturn('http://proxy.test/redirected');
    app()->instance(M3uProxyService::class, $mock);

    $this->get("/movie/{$this->user->name}/{$this->provider->uuid}/{$providerMovie->id}.mkv?proxy=true")
        ->assertRedirect();

    // The swap happened before M3uProxyService: media channel + media playlist.
    expect($captured)->toBe([$this->media->id, $mediaMovie->id]);
});

test('a proxied media swap tags the stream with the movie the client asked for', function () {
    [$providerMovie, $mediaMovie] = makeMatchedMovies($this);
    $providerMovie->update(['enable_proxy' => true]);
    fakeProxyForStreamCreation();

    $this->get("/movie/{$this->user->name}/{$this->provider->uuid}/{$providerMovie->id}.mkv?proxy=true")
        ->assertRedirect();

    // The TV app stops its stream by the movie it asked for, not the media copy serving it.
    Http::assertSent(fn (ClientRequest $request) => $request->method() === 'POST'
        && ($request['metadata']['original_channel_id'] ?? null) === $mediaMovie->id
        && ($request['metadata']['requested_channel_id'] ?? null) === $providerMovie->id);
});

test('the proxy entry point keeps the provider context when unmatched', function () {
    $providerMovie = Channel::factory()->for($this->provider)->for($this->user)->create([
        'enabled' => true,
        'is_vod' => true,
        'tmdb_id' => 999,
        'url' => 'http://provider.test/movie/123.mkv',
        'container_extension' => 'mkv',
        'enable_proxy' => true,
    ]);

    $captured = null;
    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getChannelUrl')
        ->once()
        ->withArgs(function ($playlist, $channel) use (&$captured) {
            $captured = [$playlist->id, $channel->id];

            return true;
        })
        ->andReturn('http://proxy.test/redirected');
    app()->instance(M3uProxyService::class, $mock);

    $this->get("/movie/{$this->user->name}/{$this->provider->uuid}/{$providerMovie->id}.mkv?proxy=true")
        ->assertRedirect();

    expect($captured)->toBe([$this->provider->id, $providerMovie->id]);
});

test('an alias uuid request still swaps to the media playlist and media channel', function () {
    [$providerMovie, $mediaMovie] = makeMatchedMovies($this);
    $providerMovie->update(['enable_proxy' => true]);

    $alias = PlaylistAlias::create([
        'name' => 'Alias With Host Swap',
        'uuid' => fake()->uuid(),
        'user_id' => $this->user->id,
        'playlist_id' => $this->provider->id,
    ]);

    $captured = null;
    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getChannelUrl')
        ->once()
        ->withArgs(function ($playlist, $channel) use (&$captured) {
            $captured = [$playlist instanceof PlaylistAlias ? 'alias' : 'playlist', $playlist->id, $channel->id];

            return true;
        })
        ->andReturn('http://proxy.test/redirected');
    app()->instance(M3uProxyService::class, $mock);

    // Requesting through the alias wrapper must not leak the alias context
    // into the media URL: the swap replaces the playlist context entirely.
    $this->get("/movie/{$this->user->name}/{$alias->uuid}/{$providerMovie->id}.mkv?proxy=true")
        ->assertRedirect();

    expect($captured)->toBe(['playlist', $this->media->id, $mediaMovie->id]);
});
