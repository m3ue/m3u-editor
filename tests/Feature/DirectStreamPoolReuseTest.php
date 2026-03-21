<?php

/**
 * Tests for non-transcoded stream pool reuse (PR #836) and the proxy-API-based
 * capacity enforcement introduced alongside it.
 *
 * Covers:
 * - getEffectiveConnectionCount() returns proxy API count as ground truth
 * - getEffectiveConnectionCount() adds in-flight Redis reservations
 * - selectProfile() selects a profile when proxy says capacity is free, even if
 *   Redis INCR is stale-high after a proxy restart
 * - selectProfile() respects proxy API count when at capacity
 * - selectProfile() treats pending reservations as consumed capacity
 * - getChannelUrl() reuses an existing non-transcoded pooled stream (bypassing
 *   capacity checks entirely)
 * - getChannelUrl() clears a stale channel→stream Redis key when the proxy has
 *   no matching non-transcoded stream
 */

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Models\User;
use App\Services\M3uProxyService;
use App\Services\ProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create([
        'permissions' => ['use_proxy'],
    ]);

    config(['proxy.m3u_proxy_host' => 'http://localhost:8765']);
    config(['proxy.m3u_proxy_port' => null]); // prevent port appended in tests
    config(['proxy.m3u_proxy_token' => 'test-token']);
    config(['cache.default' => 'array']);
});

// ── getEffectiveConnectionCount ───────────────────────────────────────────────

test('getEffectiveConnectionCount returns proxy API count as ground truth', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['profiles_enabled' => true]);
    $profile = PlaylistProfile::factory()->for($playlist)->for($this->user)->primary()->withMaxStreams(5)->create();

    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [],
            'total_matching' => 3,
            'total_clients' => 9, // higher client count is irrelevant
        ]),
    ]);

    Redis::shouldReceive('smembers')
        ->once()
        ->with("playlist_profile:{$profile->id}:streams")
        ->andReturn([]); // no pending reservations

    $count = ProfileService::getEffectiveConnectionCount($profile);

    expect($count)->toBe(3);
});

test('getEffectiveConnectionCount adds pending reservations to proxy count', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['profiles_enabled' => true]);
    $profile = PlaylistProfile::factory()->for($playlist)->for($this->user)->primary()->withMaxStreams(5)->create();

    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [],
            'total_matching' => 1,
            'total_clients' => 1,
        ]),
    ]);

    Redis::shouldReceive('smembers')
        ->once()
        ->with("playlist_profile:{$profile->id}:streams")
        ->andReturn(['reservation:aabbccdd', 'stream-real-xyz']); // 1 pending + 1 real (real counted by proxy)

    $count = ProfileService::getEffectiveConnectionCount($profile);

    expect($count)->toBe(2); // proxy=1 + pending=1
});

test('getEffectiveConnectionCount ignores stale Redis INCR counter', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['profiles_enabled' => true]);
    $profile = PlaylistProfile::factory()->for($playlist)->for($this->user)->primary()->withMaxStreams(5)->create();

    // Proxy correctly shows 0 after a restart (all streams dead)
    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [],
            'total_matching' => 0,
            'total_clients' => 0,
        ]),
    ]);

    Redis::shouldReceive('smembers')
        ->once()
        ->andReturn([]); // no pending reservations either

    // Even if Redis INCR key says 4, getEffectiveConnectionCount ignores it
    $count = ProfileService::getEffectiveConnectionCount($profile);

    expect($count)->toBe(0);
});

// ── selectProfile capacity enforcement via proxy API ─────────────────────────

test('selectProfile finds capacity when proxy count is below max even if Redis was stale', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['profiles_enabled' => true]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)->for($this->user)->primary()
        ->withMaxStreams(2)->withProviderInfo(0, 2)
        ->create(['priority' => 0]);

    // Proxy says 1 active stream; Redis INCR would have said 2 (stale after proxy restart)
    Http::fake([
        '*/streams/counts-by-metadata*' => Http::response([
            'field' => 'provider_profile_id',
            'counts' => [(string) $profile->id => 1],
        ]),
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [],
            'total_matching' => 1,
            'total_clients' => 1,
        ]),
    ]);

    Redis::shouldReceive('smembers')->andReturn([]); // no pending reservations

    $selected = ProfileService::selectProfile($playlist);

    expect($selected)->not->toBeNull();
    expect($selected->id)->toBe($profile->id);
});

test('selectProfile returns null when proxy API count is at max capacity', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['profiles_enabled' => true]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)->for($this->user)->primary()
        ->withMaxStreams(1)->withProviderInfo(0, 1)
        ->create(['priority' => 0]);

    // Proxy says 1 active stream = profile is at max_streams=1
    Http::fake([
        '*/streams/counts-by-metadata*' => Http::response([
            'field' => 'provider_profile_id',
            'counts' => [(string) $profile->id => 1],
        ]),
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [],
            'total_matching' => 1,
            'total_clients' => 3,
        ]),
    ]);

    Redis::shouldReceive('smembers')->andReturn([]);

    $selected = ProfileService::selectProfile($playlist);

    expect($selected)->toBeNull();
});

test('selectProfile treats pending reservations as consumed capacity to prevent TOCTOU', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['profiles_enabled' => true]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)->for($this->user)->primary()
        ->withMaxStreams(1)->withProviderInfo(0, 1)
        ->create(['priority' => 0]);

    // Proxy says 0 confirmed streams (stream being created hasn't appeared yet)
    Http::fake([
        '*/streams/counts-by-metadata*' => Http::response([
            'field' => 'provider_profile_id',
            'counts' => [(string) $profile->id => 0],
        ]),
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [],
            'total_matching' => 0,
            'total_clients' => 0,
        ]),
    ]);

    // But Redis has 1 in-flight reservation for this profile
    Redis::shouldReceive('smembers')
        ->with("playlist_profile:{$profile->id}:streams")
        ->andReturn(['reservation:deadbeef01']);

    $selected = ProfileService::selectProfile($playlist);

    // effective = proxy(0) + pending(1) = 1 = max_streams → no capacity
    expect($selected)->toBeNull();
});

test('selectProfile force-selects least-loaded profile using proxy-based effective count', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
        'bypass_provider_limits' => true,
    ]);

    $profileA = PlaylistProfile::factory()
        ->for($playlist)->for($this->user)
        ->withMaxStreams(1)->withProviderInfo(0, 1)
        ->create(['priority' => 0, 'is_primary' => true]);

    $profileB = PlaylistProfile::factory()
        ->for($playlist)->for($this->user)
        ->withMaxStreams(1)->withProviderInfo(0, 1)
        ->create(['priority' => 1, 'is_primary' => false]);

    // Both at capacity per proxy, but profileB has fewer actual streams
    Http::fake([
        '*/streams/counts-by-metadata*' => Http::response([
            'field' => 'provider_profile_id',
            'counts' => [
                (string) $profileA->id => 2,
                (string) $profileB->id => 1,
            ],
        ]),
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [], 'total_matching' => 1, 'total_clients' => 1]),
    ]);

    Redis::shouldReceive('smembers')->andReturn([]);

    $selected = ProfileService::selectProfile($playlist, forceSelect: true);

    // force-select picks the profile with fewer effective connections (profileB with 1)
    expect($selected)->not->toBeNull();
    expect($selected->id)->toBe($profileB->id);
});

// ── getChannelUrl non-transcoded pool reuse ───────────────────────────────────

test('getChannelUrl reuses existing non-transcoded pooled stream and bypasses capacity check', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
        'enable_proxy' => true,
        'available_streams' => 2, // non-zero so capacity check would run if not bypassed
        'xtream' => false,
    ]);

    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
        'url' => 'http://provider.com/live/user/pass/1234.ts',
    ]);

    // First call to streams/by-metadata: findExistingPooledStream returns a match.
    // Subsequent calls (capacity check) should never be reached.
    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [
                [
                    'stream_id' => 'pool-stream-abc',
                    'client_count' => 1,
                    'metadata' => [
                        'original_channel_id' => (string) $channel->id,
                        'original_playlist_uuid' => $playlist->uuid,
                        'transcoding' => 'false',
                    ],
                ],
            ],
            'total_matching' => 1,
            'total_clients' => 1,
        ]),
    ]);

    $service = app(M3uProxyService::class);
    $url = $service->getChannelUrl($playlist, $channel);

    // Should return a proxy stream URL for the existing stream, not create a new one
    expect($url)->toContain('stream/pool-stream-abc');

    // Only one HTTP call made (findExistingPooledStream); no capacity check or stream creation
    Http::assertSentCount(1);
});

test('getChannelUrl clears stale channel stream key when proxy has no matching non-transcoded stream', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
        'enable_proxy' => true,
        'available_streams' => 0,
        'xtream' => false,
    ]);

    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
        'url' => 'http://provider.com/live/user/pass/5678.ts',
    ]);

    $channelKey = "channel_stream:{$channel->id}:{$playlist->uuid}";

    Http::fake([
        // findExistingPooledStream returns no matching stream
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [],
            'total_matching' => 0,
            'total_clients' => 0,
        ]),
        // createStream returns a new stream ID
        '*/streams' => Http::response(['stream_id' => 'new-stream-xyz']),
    ]);

    // Redis has a stale key for this channel
    Redis::shouldReceive('exists')
        ->with($channelKey)
        ->andReturn(1);

    Redis::shouldReceive('del')
        ->once()
        ->with($channelKey);

    $service = app(M3uProxyService::class);
    $url = $service->getChannelUrl($playlist, $channel);

    // After stale key cleared, a new stream is created and its URL is returned
    expect($url)->toContain('stream/new-stream-xyz');
});
