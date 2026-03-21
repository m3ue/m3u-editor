<?php

/**
 * Tests for the audit findings from PR #801 code review.
 *
 * Fix #2: Reservation pattern (selectAndReserveProfile, finalizeReservation, cancelReservation)
 * Fix #3: PHP_INT_MAX default when provider_info is null
 * Fix #4: Auto-update max_streams only when null/0
 * Fix #5: reconcileAndSelectProfile returns reservation tuple
 */

use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Models\User;
use App\Services\ProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();

    config(['proxy.m3u_proxy_host' => 'http://localhost:8765']);
    config(['proxy.m3u_proxy_token' => 'test-token']);
    config(['cache.default' => 'array']);
});

// ── Fix #1: decrementConnections cleans up stream set and channel mapping ──

test('decrementConnections removes stream from set and cleans up channel reverse key', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(5)
        ->create();

    $streamId = 'stream-123';

    // First get: channel reverse-key lookup → null (no channel mapping)
    Redis::shouldReceive('get')
        ->once()
        ->with("stream:{$streamId}:channel")
        ->andReturn(null);

    // Pipeline: srem from streams set + del the reverse key
    Redis::shouldReceive('pipeline')
        ->once()
        ->andReturnUsing(function ($callback) use ($profile, $streamId) {
            $pipe = Mockery::mock();
            $pipe->shouldReceive('srem')
                ->with("playlist_profile:{$profile->id}:streams", $streamId)
                ->once()
                ->andReturnSelf();
            $pipe->shouldReceive('del')
                ->with("stream:{$streamId}:channel")
                ->once()
                ->andReturnSelf();
            $callback($pipe);
        });

    // Should not throw
    ProfileService::decrementConnections($profile, $streamId);
});

test('decrementConnections cleans up channel stream key when reverse mapping exists', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(5)
        ->create();

    $streamId = 'stream-456';
    $channelId = 42;
    $playlistUuid = 'uuid-abc';
    $channelStreamKey = "channel_stream:{$channelId}:{$playlistUuid}";

    // Reverse key lookup returns channel coordinates
    Redis::shouldReceive('get')
        ->once()
        ->with("stream:{$streamId}:channel")
        ->andReturn("{$channelId}:{$playlistUuid}");

    // Pipeline: srem + del
    Redis::shouldReceive('pipeline')
        ->once()
        ->andReturnUsing(function ($callback) {
            $pipe = Mockery::mock();
            $pipe->shouldReceive('srem')->andReturnSelf();
            $pipe->shouldReceive('del')->andReturnSelf();
            $callback($pipe);
        });

    // Channel stream key lookup confirms this stream still owns the channel key
    Redis::shouldReceive('get')
        ->once()
        ->with($channelStreamKey)
        ->andReturn($streamId);

    // Channel stream key deleted since it still points to this stream
    Redis::shouldReceive('del')
        ->once()
        ->with($channelStreamKey);

    ProfileService::decrementConnections($profile, $streamId);
});

test('decrementConnections does not delete channel stream key when it points to a different stream', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(5)
        ->create();

    $streamId = 'stream-orphan';
    $channelId = 99;
    $playlistUuid = 'uuid-xyz';
    $channelStreamKey = "channel_stream:{$channelId}:{$playlistUuid}";

    // Reverse key lookup returns channel coordinates
    Redis::shouldReceive('get')
        ->once()
        ->with("stream:{$streamId}:channel")
        ->andReturn("{$channelId}:{$playlistUuid}");

    // Pipeline runs
    Redis::shouldReceive('pipeline')
        ->once()
        ->andReturnUsing(function ($callback) {
            $pipe = Mockery::mock();
            $pipe->shouldReceive('srem')->andReturnSelf();
            $pipe->shouldReceive('del')->andReturnSelf();
            $callback($pipe);
        });

    // Channel stream key now points to a DIFFERENT (newer) stream — do not delete
    Redis::shouldReceive('get')
        ->once()
        ->with($channelStreamKey)
        ->andReturn('stream-newer');

    // del on channelStreamKey must NOT be called
    Redis::shouldReceive('del')
        ->with($channelStreamKey)
        ->never();

    ProfileService::decrementConnections($profile, $streamId);
});

// ── Fix #2: Reservation pattern ────────────────────────────────────────────

test('selectAndReserveProfile returns profile and reservation ID on success', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(5)
        ->withProviderInfo(0, 5)
        ->create();

    // selectProfile uses batch endpoint; getEffectiveConnectionCount uses per-profile endpoint
    Http::fake([
        '*/streams/counts-by-metadata*' => Http::response([
            'field' => 'provider_profile_id',
            'counts' => [(string) $profile->id => 0],
        ]),
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [],
            'total_clients' => 0,
        ]),
    ]);

    Redis::shouldReceive('smembers')->andReturn([]);

    // incrementConnections uses pipeline with sadd + expire
    Redis::shouldReceive('pipeline')
        ->once()
        ->andReturnUsing(function ($callback) {
            $pipe = Mockery::mock();
            $pipe->shouldReceive('sadd')->andReturnSelf();
            $pipe->shouldReceive('expire')->andReturnSelf();
            $callback($pipe);
        });

    [$selected, $reservationId] = ProfileService::selectAndReserveProfile($playlist);

    expect($selected)->not->toBeNull();
    expect($selected->id)->toBe($profile->id);
    expect($reservationId)->toStartWith('reservation:');
    expect(strlen($reservationId))->toBeGreaterThan(12); // 'reservation:' + 16 hex chars
});

test('selectAndReserveProfile returns null tuple when no capacity', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(1)
        ->withProviderInfo(0, 1)
        ->create();

    // Proxy confirms 1 active stream = profile at max_streams=1
    Http::fake([
        '*/streams/counts-by-metadata*' => Http::response([
            'field' => 'provider_profile_id',
            'counts' => [(string) $profile->id => 1],
        ]),
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [], 'total_matching' => 1, 'total_clients' => 1]),
    ]);
    Redis::shouldReceive('smembers')->andReturn([]);

    [$selected, $reservationId] = ProfileService::selectAndReserveProfile($playlist);

    expect($selected)->toBeNull();
    expect($reservationId)->toBeNull();
});

test('selectAndReserveProfile returns null tuple when profiles disabled', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
    ]);

    [$selected, $reservationId] = ProfileService::selectAndReserveProfile($playlist);

    expect($selected)->toBeNull();
    expect($reservationId)->toBeNull();
});

test('finalizeReservation swaps reservation ID for real stream ID in pipeline', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(5)
        ->create();

    $reservationId = 'reservation:abc123def456';
    $realStreamId = 'proxy-stream-789';
    $streamsKey = "playlist_profile:{$profile->id}:streams";

    // Pipeline should: srem old reservation, sadd new stream id, expire
    Redis::shouldReceive('pipeline')
        ->once()
        ->andReturnUsing(function ($callback) use ($streamsKey, $reservationId, $realStreamId) {
            $pipe = Mockery::mock();
            $pipe->shouldReceive('srem')
                ->with($streamsKey, $reservationId)
                ->once()
                ->andReturnSelf();
            $pipe->shouldReceive('sadd')
                ->with($streamsKey, $realStreamId)
                ->once()
                ->andReturnSelf();
            $pipe->shouldReceive('expire')
                ->once()
                ->andReturnSelf();
            $callback($pipe);
        });

    // Should not throw
    ProfileService::finalizeReservation($profile, $reservationId, $realStreamId);
});

test('cancelReservation delegates to decrementConnections', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(5)
        ->create();

    $reservationId = 'reservation:cancel123';

    // decrementConnections: get channel reverse key (no mapping)
    Redis::shouldReceive('get')
        ->once()
        ->with("stream:{$reservationId}:channel")
        ->andReturn(null);

    // Cleanup pipeline
    Redis::shouldReceive('pipeline')
        ->once()
        ->andReturnUsing(function ($callback) {
            $pipe = Mockery::mock();
            $pipe->shouldReceive('srem')->andReturnSelf();
            $pipe->shouldReceive('del')->andReturnSelf();
            $callback($pipe);
        });

    ProfileService::cancelReservation($profile, $reservationId);
});

// ── Fix #3: PHP_INT_MAX default when provider_info is null ─────────────────

test('provider_max_connections returns PHP_INT_MAX when provider_info is null', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(3)
        ->create(); // No withProviderInfo => provider_info is null

    expect($profile->provider_max_connections)->toBe(PHP_INT_MAX);
});

test('provider_max_connections returns actual value when provider_info is set', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(3)
        ->withProviderInfo(0, 5)
        ->create();

    expect($profile->provider_max_connections)->toBe(5);
});

test('effective_max_streams uses user max_streams when provider_info is null', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(4)
        ->create(); // No provider info

    // provider_max_connections is PHP_INT_MAX, so user's value should be used directly
    expect($profile->effective_max_streams)->toBe(4);
});

test('effective_max_streams caps user value at provider limit when known', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(10) // User wants 10
        ->withProviderInfo(0, 5) // Provider allows 5
        ->create();

    expect($profile->effective_max_streams)->toBe(5); // Capped at provider limit
});

test('effective_max_streams falls back to 1 when neither user nor provider set', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    // Create profile with max_streams = 0 (not set) and no provider info
    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->create([
            'max_streams' => 0,
        ]); // No provider info

    // provider_max_connections = PHP_INT_MAX, max_streams = 0 => fallback to 1
    expect($profile->effective_max_streams)->toBe(1);
});

test('effective_max_streams uses provider limit when user max_streams is zero', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withProviderInfo(0, 8)
        ->create([
            'max_streams' => 0,
        ]);

    // max_streams=0 is falsy, so provider limit (8) is used
    expect($profile->effective_max_streams)->toBe(8);
});

// ── Fix #4: Auto-update max_streams only when null/0 ──────────────────────

test('refreshProfile does not override positive user-configured max_streams', function () {
    // Fake HTTP before playlist creation to prevent timeouts from auto-created primary profile
    Http::fake([
        'example.com:8080/player_api.php*' => Http::response([
            'user_info' => [
                'max_connections' => 5,
                'active_cons' => 0,
                'status' => 'Active',
            ],
        ]),
    ]);

    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://example.com:8080',
            'username' => 'primary',
            'password' => 'primary_pass',
        ],
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(1) // User explicitly set to 1
        ->create([
            'url' => 'http://example.com:8080',
        ]);

    ProfileService::refreshProfile($profile);

    $profile->refresh();

    // max_streams should stay at 1 (user's explicit choice), NOT auto-updated to 5
    expect($profile->max_streams)->toBe(1);
    // But provider_info should be updated
    expect($profile->provider_info)->not->toBeEmpty();
});

test('refreshProfile auto-updates max_streams when value is zero', function () {
    Http::fake([
        'example.com:8080/player_api.php*' => Http::response([
            'user_info' => [
                'max_connections' => 3,
                'active_cons' => 0,
                'status' => 'Active',
            ],
        ]),
    ]);

    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://example.com:8080',
            'username' => 'primary',
            'password' => 'primary_pass',
        ],
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->create([
            'max_streams' => 0, // Not configured yet
            'url' => 'http://example.com:8080',
        ]);

    ProfileService::refreshProfile($profile);

    $profile->refresh();

    // max_streams should be auto-updated to 3 since it was 0 (never configured)
    expect($profile->max_streams)->toBe(3);
});

test('refreshProfile preserves user max_streams even when provider upgrades', function () {
    // Provider upgrades to 10 connections
    Http::fake([
        'example.com:8080/player_api.php*' => Http::response([
            'user_info' => [
                'max_connections' => 10,
                'active_cons' => 0,
                'status' => 'Active',
            ],
        ]),
    ]);

    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://example.com:8080',
            'username' => 'primary',
            'password' => 'primary_pass',
        ],
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(2)  // User configured to 2
        ->withProviderInfo(0, 2) // Provider originally had 2
        ->create([
            'url' => 'http://example.com:8080',
        ]);

    ProfileService::refreshProfile($profile);

    $profile->refresh();

    // max_streams should stay at 2 — user's choice is respected even after provider upgrade
    expect($profile->max_streams)->toBe(2);
});

// ── Fix #5: reconcileAndSelectProfile returns reservation tuple ────────────

test('reconcileAndSelectProfile returns array with profile and reservation on success', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(2)
        ->withProviderInfo(0, 5)
        ->create();

    // selectProfile uses batch endpoint; proxy returns 0 active streams → profile has capacity
    Http::fake([
        '*/streams/counts-by-metadata*' => Http::response([
            'field' => 'provider_profile_id',
            'counts' => [(string) $profile->id => 0],
        ]),
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [],
            'total_clients' => 0,
        ]),
    ]);

    // No pending reservations in Redis
    Redis::shouldReceive('smembers')->andReturn([]);

    // incrementConnections pipeline: sadd + expire
    Redis::shouldReceive('pipeline')
        ->once()
        ->andReturnUsing(function ($callback) {
            $pipe = Mockery::mock();
            $pipe->shouldReceive('sadd')->andReturnSelf();
            $pipe->shouldReceive('expire')->andReturnSelf();
            $callback($pipe);
        });

    $result = ProfileService::reconcileAndSelectProfile($playlist);

    expect($result)->toBeArray();
    expect($result)->toHaveCount(2);
    [$selected, $reservationId] = $result;
    expect($selected)->not->toBeNull();
    expect($selected->id)->toBe($profile->id);
    expect($reservationId)->toStartWith('reservation:');
});

test('reconcileAndSelectProfile returns null tuple when profiles disabled', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
    ]);

    $result = ProfileService::reconcileAndSelectProfile($playlist);

    expect($result)->toBe([null, null]);
});

test('reconcileAndSelectProfile returns null tuple when truly at capacity after reconcile', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(1)
        ->withProviderInfo(0, 1)
        ->create();

    // Proxy confirms 1 active stream (at max capacity)
    Http::fake([
        '*/streams/counts-by-metadata*' => Http::response([
            'field' => 'provider_profile_id',
            'counts' => [(string) $profile->id => 1],
        ]),
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [
                [
                    'stream_id' => 'real-stream',
                    'client_count' => 1,
                    'metadata' => [
                        'provider_profile_id' => $profile->id,
                        'playlist_uuid' => $playlist->uuid,
                    ],
                ],
            ],
            'total_matching' => 1,
            'total_clients' => 1,
        ]),
    ]);

    // No pending reservations
    Redis::shouldReceive('smembers')->andReturn([]);

    [$selected, $reservationId] = ProfileService::reconcileAndSelectProfile($playlist);

    expect($selected)->toBeNull();
    expect($reservationId)->toBeNull();
});
