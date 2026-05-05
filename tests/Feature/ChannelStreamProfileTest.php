<?php

/**
 * Tests for per-channel proxy enable/disable and per-channel stream profile support.
 *
 * Covers:
 * - Channel::isProxyEnabled() returns the correct value based on channel and playlist settings
 * - M3uProxyApiController::channel() uses channel-level stream profile over playlist profile
 * - M3uProxyApiController::channel() falls back to playlist profile when no channel profile
 * - M3uProxyApiController::channel() passes null profile when neither has one
 * - M3uProxyApiController::channelPlayer() uses channel-level stream profile over GeneralSettings defaults
 * - M3uProxyApiController::channelPlayer() falls back to GeneralSettings defaults when no channel-level profile is set
 * - XtreamStreamController::handleLive() proxies when channel enable_proxy is true (playlist off)
 * - XtreamStreamController::handleLive() redirects directly when both channel and playlist proxy are off
 * - XtreamStreamController::handleVod() proxies when channel enable_proxy is true (playlist off)
 * - XtreamStreamController::handleVod() redirects directly when both channel and playlist proxy are off
 */

use App\Http\Controllers\Api\M3uProxyApiController;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\StreamProfile;
use App\Models\User;
use App\Services\M3uProxyService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create([
        'name' => 'testuser',
        'permissions' => ['use_proxy'],
    ]);
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => false,
        'xtream' => false,
    ]);
});

// ── Channel::isProxyEnabled() ─────────────────────────────────────────────────

test('isProxyEnabled returns true when channel enable_proxy is true regardless of playlist', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enable_proxy' => true,
        'enabled' => true,
    ]);

    expect($channel->isProxyEnabled())->toBeTrue();
});

test('isProxyEnabled returns true when only the playlist has enable_proxy set', function () {
    $this->playlist->update(['enable_proxy' => true]);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enable_proxy' => false,
        'enabled' => true,
    ]);

    $channel->load('playlist');

    expect($channel->isProxyEnabled())->toBeTrue();
});

test('isProxyEnabled returns false when both channel and playlist have proxy disabled', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enable_proxy' => false,
        'enabled' => true,
    ]);

    $channel->load('playlist');

    expect($channel->isProxyEnabled())->toBeFalse();
});

test('isProxyEnabled returns true when custom playlist has enable_proxy set', function () {
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create([
        'enable_proxy' => true,
    ]);

    $channel = Channel::factory()->for($this->user)->create([
        'playlist_id' => null,
        'custom_playlist_id' => $customPlaylist->id,
        'enable_proxy' => false,
        'enabled' => true,
    ]);

    $channel->load('customPlaylist');

    expect($channel->isProxyEnabled())->toBeTrue();
});

// ── M3uProxyApiController::channel() profile resolution ──────────────────────

test('channel() uses channel-level stream profile over playlist-level profile for live channels', function () {
    $this->playlist->update(['enable_proxy' => true]);

    $playlistProfile = StreamProfile::factory()->for($this->user)->create(['name' => 'playlist-profile']);
    $channelProfile = StreamProfile::factory()->for($this->user)->create(['name' => 'channel-profile']);

    $this->playlist->update(['stream_profile_id' => $playlistProfile->id]);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'stream_profile_id' => $channelProfile->id,
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $capturedProfile = null;

    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getChannelUrl')
        ->once()
        ->withArgs(function ($playlist, $ch, $request, $profile, $username = null) use (&$capturedProfile) {
            $capturedProfile = $profile;

            return true;
        })
        ->andReturn('http://proxy.test/redirected');

    app()->instance(M3uProxyService::class, $mock);

    $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.ts")
        ->assertRedirect();

    expect($capturedProfile)->not->toBeNull()
        ->and($capturedProfile->id)->toBe($channelProfile->id);
});

test('channel() falls back to playlist stream profile when channel has no profile (live channel)', function () {
    $this->playlist->update(['enable_proxy' => true]);

    $playlistProfile = StreamProfile::factory()->for($this->user)->create(['name' => 'playlist-profile']);
    $this->playlist->update(['stream_profile_id' => $playlistProfile->id]);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'stream_profile_id' => null,
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $capturedProfile = null;

    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getChannelUrl')
        ->once()
        ->withArgs(function ($playlist, $ch, $request, $profile, $username = null) use (&$capturedProfile) {
            $capturedProfile = $profile;

            return true;
        })
        ->andReturn('http://proxy.test/redirected');

    app()->instance(M3uProxyService::class, $mock);

    $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.ts")
        ->assertRedirect();

    expect($capturedProfile)->not->toBeNull()
        ->and($capturedProfile->id)->toBe($playlistProfile->id);
});

test('channel() falls back to playlist vod stream profile when channel has no profile (VOD channel)', function () {
    $this->playlist->update(['enable_proxy' => true]);

    $vodProfile = StreamProfile::factory()->for($this->user)->create(['name' => 'vod-profile']);
    $this->playlist->update(['vod_stream_profile_id' => $vodProfile->id]);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'stream_profile_id' => null,
        'url' => 'http://provider.test/movie/123.mkv',
        'is_vod' => true,
        'container_extension' => 'mkv',
        'enabled' => true,
    ]);

    $capturedProfile = null;

    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getChannelUrl')
        ->once()
        ->withArgs(function ($playlist, $ch, $request, $profile, $username = null) use (&$capturedProfile) {
            $capturedProfile = $profile;

            return true;
        })
        ->andReturn('http://proxy.test/redirected');

    app()->instance(M3uProxyService::class, $mock);

    $this->get("/movie/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.mkv")
        ->assertRedirect();

    expect($capturedProfile)->not->toBeNull()
        ->and($capturedProfile->id)->toBe($vodProfile->id);
});

test('channel() passes null profile when neither channel nor playlist has a stream profile', function () {
    $this->playlist->update([
        'enable_proxy' => true,
        'stream_profile_id' => null,
        'vod_stream_profile_id' => null,
    ]);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'stream_profile_id' => null,
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $capturedProfile = 'not-set';

    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getChannelUrl')
        ->once()
        ->withArgs(function ($playlist, $ch, $request, $profile, $username = null) use (&$capturedProfile) {
            $capturedProfile = $profile;

            return true;
        })
        ->andReturn('http://proxy.test/redirected');

    app()->instance(M3uProxyService::class, $mock);

    $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.ts")
        ->assertRedirect();

    expect($capturedProfile)->toBeNull();
});

// ── M3uProxyApiController::channelPlayer() profile resolution ─────────────────

test('channelPlayer() uses channel-level stream profile over GeneralSettings default', function () {
    $this->playlist->update(['enable_proxy' => true]);

    $channelProfile = StreamProfile::factory()->for($this->user)->create(['name' => 'channel-profile']);
    $defaultProfile = StreamProfile::factory()->for($this->user)->create(['name' => 'default-profile']);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'stream_profile_id' => $channelProfile->id,
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->default_stream_profile_id = $defaultProfile->id;
    $mockSettings->default_vod_stream_profile_id = null;
    app()->instance(GeneralSettings::class, $mockSettings);

    $capturedProfile = null;

    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getChannelUrl')
        ->once()
        ->withArgs(function ($playlist, $ch, $request, $profile, $username = null) use (&$capturedProfile) {
            $capturedProfile = $profile;

            return true;
        })
        ->andReturn('http://proxy.test/redirected');

    app()->instance(M3uProxyService::class, $mock);

    $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.ts?player=true")
        ->assertRedirect();

    expect($capturedProfile)->not->toBeNull()
        ->and($capturedProfile->id)->toBe($channelProfile->id);
});

test('channelPlayer() falls back to GeneralSettings default when channel has no stream profile', function () {
    $this->playlist->update(['enable_proxy' => true]);

    $defaultProfile = StreamProfile::factory()->for($this->user)->create(['name' => 'default-profile']);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'stream_profile_id' => null,
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->default_stream_profile_id = $defaultProfile->id;
    $mockSettings->default_vod_stream_profile_id = null;
    app()->instance(GeneralSettings::class, $mockSettings);

    $capturedProfile = null;

    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getChannelUrl')
        ->once()
        ->withArgs(function ($playlist, $ch, $request, $profile, $username = null) use (&$capturedProfile) {
            $capturedProfile = $profile;

            return true;
        })
        ->andReturn('http://proxy.test/redirected');

    app()->instance(M3uProxyService::class, $mock);

    $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.ts?player=true")
        ->assertRedirect();

    expect($capturedProfile)->not->toBeNull()
        ->and($capturedProfile->id)->toBe($defaultProfile->id);
});

test('channelPlayer() uses channel-level profile over GeneralSettings default for VOD channels', function () {
    $this->playlist->update(['enable_proxy' => true]);

    $channelProfile = StreamProfile::factory()->for($this->user)->create(['name' => 'channel-profile']);
    $defaultVodProfile = StreamProfile::factory()->for($this->user)->create(['name' => 'default-vod-profile']);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'stream_profile_id' => $channelProfile->id,
        'url' => 'http://provider.test/movie/123.mkv',
        'is_vod' => true,
        'container_extension' => 'mkv',
        'enabled' => true,
    ]);

    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->default_stream_profile_id = null;
    $mockSettings->default_vod_stream_profile_id = $defaultVodProfile->id;
    app()->instance(GeneralSettings::class, $mockSettings);

    $capturedProfile = null;

    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getChannelUrl')
        ->once()
        ->withArgs(function ($playlist, $ch, $request, $profile, $username = null) use (&$capturedProfile) {
            $capturedProfile = $profile;

            return true;
        })
        ->andReturn('http://proxy.test/redirected');

    app()->instance(M3uProxyService::class, $mock);

    $this->get("/movie/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.mkv?player=true")
        ->assertRedirect();

    expect($capturedProfile)->not->toBeNull()
        ->and($capturedProfile->id)->toBe($channelProfile->id);
});

test('channelPlayer() falls back to GeneralSettings default vod profile when channel has no stream profile', function () {
    $this->playlist->update(['enable_proxy' => true]);

    $defaultVodProfile = StreamProfile::factory()->for($this->user)->create(['name' => 'default-vod-profile']);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'stream_profile_id' => null,
        'url' => 'http://provider.test/movie/123.mkv',
        'is_vod' => true,
        'container_extension' => 'mkv',
        'enabled' => true,
    ]);

    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->default_stream_profile_id = null;
    $mockSettings->default_vod_stream_profile_id = $defaultVodProfile->id;
    app()->instance(GeneralSettings::class, $mockSettings);

    $capturedProfile = null;

    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getChannelUrl')
        ->once()
        ->withArgs(function ($playlist, $ch, $request, $profile, $username = null) use (&$capturedProfile) {
            $capturedProfile = $profile;

            return true;
        })
        ->andReturn('http://proxy.test/redirected');

    app()->instance(M3uProxyService::class, $mock);

    $this->get("/movie/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.mkv?player=true")
        ->assertRedirect();

    expect($capturedProfile)->not->toBeNull()
        ->and($capturedProfile->id)->toBe($defaultVodProfile->id);
});

// ── XtreamStreamController proxy routing ──────────────────────────────────────

test('handleLive() proxies when only channel enable_proxy is true (playlist proxy off)', function () {
    // Playlist proxy is OFF, but channel proxy is ON — should still route through proxy
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enable_proxy' => true,
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getChannelUrl')
        ->once()
        ->andReturn('http://proxy.test/redirected');
    app()->instance(M3uProxyService::class, $mock);

    $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.ts")
        ->assertRedirect();
});

test('handleLive() redirects directly to stream URL when both channel and playlist proxy are off', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enable_proxy' => false,
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.ts")
        ->assertRedirect('http://provider.test/stream/live.ts');
});

test('handleVod() proxies when only channel enable_proxy is true (playlist proxy off)', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enable_proxy' => true,
        'url' => 'http://provider.test/movie/123.mkv',
        'is_vod' => true,
        'container_extension' => 'mkv',
        'enabled' => true,
    ]);

    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getChannelUrl')
        ->once()
        ->andReturn('http://proxy.test/redirected');
    app()->instance(M3uProxyService::class, $mock);

    $this->get("/movie/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.mkv")
        ->assertRedirect();
});

test('handleVod() redirects directly to stream URL when both channel and playlist proxy are off', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enable_proxy' => false,
        'url' => 'http://provider.test/movie/123.mkv',
        'is_vod' => true,
        'container_extension' => 'mkv',
        'enabled' => true,
    ]);

    $this->get("/movie/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.mkv")
        ->assertRedirect('http://provider.test/movie/123.mkv');
});

// ── client_id forwarding ──────────────────────────────────────────────────────

test('channelPlayer() appends client_id to the redirect URL when provided', function () {
    $this->playlist->update(['enable_proxy' => true]);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'stream_profile_id' => null,
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->default_stream_profile_id = null;
    $mockSettings->default_vod_stream_profile_id = null;
    app()->instance(GeneralSettings::class, $mockSettings);

    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getChannelUrl')
        ->once()
        ->andReturn('http://proxy.test/stream/abc123');
    app()->instance(M3uProxyService::class, $mock);

    $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.ts?player=true&client_id=floating-player-test-abc")
        ->assertRedirect('http://proxy.test/stream/abc123?client_id=floating-player-test-abc');
});

test('channelPlayer() does not append client_id to the redirect URL when not provided', function () {
    $this->playlist->update(['enable_proxy' => true]);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'stream_profile_id' => null,
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->default_stream_profile_id = null;
    $mockSettings->default_vod_stream_profile_id = null;
    app()->instance(GeneralSettings::class, $mockSettings);

    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getChannelUrl')
        ->once()
        ->andReturn('http://proxy.test/stream/abc123');
    app()->instance(M3uProxyService::class, $mock);

    $response = $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.ts?player=true");

    $response->assertRedirect('http://proxy.test/stream/abc123');
    expect($response->headers->get('Location'))->not->toContain('client_id');
});

test('episodePlayer() appends client_id to the redirect URL when provided', function () {
    $episode = Episode::factory()->for($this->user)->for($this->playlist)->create([
        'container_extension' => 'ts',
    ]);

    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->default_vod_stream_profile_id = null;
    app()->instance(GeneralSettings::class, $mockSettings);

    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getEpisodeUrl')
        ->once()
        ->andReturn('http://proxy.test/vod/ep999');
    app()->instance(M3uProxyService::class, $mock);

    $request = Request::create('/episodeplayer', 'GET', [
        'client_id' => 'popout-xyz123',
    ]);

    $response = app()->call(
        [app(M3uProxyApiController::class), 'episodePlayer'],
        ['request' => $request, 'id' => $episode->id]
    );

    expect($response->getTargetUrl())->toContain('client_id=popout-xyz123');
});

test('episodePlayer() does not append client_id to the redirect URL when not provided', function () {
    $episode = Episode::factory()->for($this->user)->for($this->playlist)->create([
        'container_extension' => 'ts',
    ]);

    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->default_vod_stream_profile_id = null;
    app()->instance(GeneralSettings::class, $mockSettings);

    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getEpisodeUrl')
        ->once()
        ->andReturn('http://proxy.test/vod/ep999');
    app()->instance(M3uProxyService::class, $mock);

    $request = Request::create('/episodeplayer', 'GET');

    $response = app()->call(
        [app(M3uProxyApiController::class), 'episodePlayer'],
        ['request' => $request, 'id' => $episode->id]
    );

    expect($response->getTargetUrl())->toBe('http://proxy.test/vod/ep999')
        ->and($response->getTargetUrl())->not->toContain('client_id');
});
