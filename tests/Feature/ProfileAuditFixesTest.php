<?php

/**
 * Tests for the 6 audit findings from PR #801 code review.
 *
 * Fix #1: Atomic decrement via Lua script (prevents negative counts)
 * Fix #2: Reservation pattern (selectAndReserveProfile, finalizeReservation, cancelReservation)
 * Fix #3: PHP_INT_MAX default when provider_info is null
 * Fix #4: Auto-update max_streams only when null/0
 * Fix #5: reconcileAndSelectProfile returns reservation tuple
 * Fix #6: reconcileFromProxy cleans disabled profiles
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

// ── Fix #1: Atomic decrement via Lua script ────────────────────────────────

test('decrementConnections uses Lua eval for atomic decrement-if-positive', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(5)
        ->create();

    // The Lua script should be called with eval, 1 key
    Redis::shouldReceive('eval')
        ->once()
        ->withArgs(function ($lua, $numKeys, $key) use ($profile) {
            return str_contains($lua, 'current > 0')
                && str_contains($lua, "redis.call('decr'")
                && $numKeys === 1
                && str_contains($key, "playlist_profile:{$profile->id}:connections");
        })
        ->andReturn(2); // simulating count went from 3 to 2

    // Cleanup pipeline for stream references
    Redis::shouldReceive('pipeline')
        ->once()
        ->andReturnUsing(function ($callback) {
            $pipe = Mockery::mock();
            $pipe->shouldReceive('del')->andReturnSelf();
            $pipe->shouldReceive('srem')->andReturnSelf();
            $callback($pipe);
        });

    // Two get calls: (1) channel reverse-key lookup → null (no mapping), (2) getConnectionCount.
    Redis::shouldReceive('get')->once()->ordered()->andReturn(null);
    Redis::shouldReceive('get')->once()->ordered()->andReturn(2);

    ProfileService::decrementConnections($profile, 'stream-123');
});

test('decrementConnections logs warning when count is already zero', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(5)
        ->create();

    // Lua script returns -1 indicating count was already 0
    Redis::shouldReceive('eval')
        ->once()
        ->andReturn(-1);

    // Cleanup pipeline still runs
    Redis::shouldReceive('pipeline')
        ->once()
        ->andReturnUsing(function ($callback) {
            $pipe = Mockery::mock();
            $pipe->shouldReceive('del')->andReturnSelf();
            $pipe->shouldReceive('srem')->andReturnSelf();
            $callback($pipe);
        });

    // Two get calls: (1) channel reverse-key lookup → null (no mapping), (2) getConnectionCount.
    Redis::shouldReceive('get')->once()->ordered()->andReturn(null);
    Redis::shouldReceive('get')->once()->ordered()->andReturn(0);

    // Should not throw, just log warning
    ProfileService::decrementConnections($profile, 'stream-456');
});

test('decrementConnections cleans up stream references even when Lua returns -1', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(2)
        ->create();

    $streamId = 'stream-orphan';

    // Lua says already at zero
    Redis::shouldReceive('eval')
        ->once()
        ->andReturn(-1);

    // Pipeline MUST still be called to clean up del + srem + channel reverse-key del
    Redis::shouldReceive('pipeline')
        ->once()
        ->andReturnUsing(function ($callback) use ($streamId) {
            $pipe = Mockery::mock();
            $pipe->shouldReceive('del')
                ->with("stream:{$streamId}:profile_id")
                ->once()
                ->andReturnSelf();
            $pipe->shouldReceive('del')
                ->with("stream:{$streamId}:channel")
                ->once()
                ->andReturnSelf();
            $pipe->shouldReceive('srem')
                ->once()
                ->andReturnSelf();
            $callback($pipe);
        });

    // Two get calls: (1) channel reverse-key lookup → null, (2) getConnectionCount.
    Redis::shouldReceive('get')->once()->ordered()->andReturn(null);
    Redis::shouldReceive('get')->once()->ordered()->andReturn(0);

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
        ->withMaxStreams(3)
        ->withProviderInfo(0, 5)
        ->create();

    // selectProfile reads connection count => 0 (has capacity)
    // hasCapacity also reads count => 0
    // incrementConnections reads count after pipeline
    Redis::shouldReceive('get')
        ->andReturn(0);

    // incrementConnections uses pipeline
    Redis::shouldReceive('pipeline')
        ->once()
        ->andReturnUsing(function ($callback) {
            $pipe = Mockery::mock();
            $pipe->shouldReceive('incr')->andReturnSelf();
            $pipe->shouldReceive('expire')->andReturnSelf();
            $pipe->shouldReceive('set')->andReturnSelf();
            $pipe->shouldReceive('sadd')->andReturnSelf();
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

    PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(1)
        ->withProviderInfo(0, 1)
        ->create();

    // Connection count is at max (1)
    Redis::shouldReceive('get')
        ->andReturn(1);

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

    // Pipeline should: srem old, del old key, sadd new, expire, set new key, expire
    Redis::shouldReceive('pipeline')
        ->once()
        ->andReturnUsing(function ($callback) use ($reservationId, $realStreamId, $profile) {
            $pipe = Mockery::mock();
            $pipe->shouldReceive('srem')
                ->with("playlist_profile:{$profile->id}:streams", $reservationId)
                ->once()
                ->andReturnSelf();
            $pipe->shouldReceive('del')
                ->with("stream:{$reservationId}:profile_id")
                ->once()
                ->andReturnSelf();
            $pipe->shouldReceive('sadd')
                ->with("playlist_profile:{$profile->id}:streams", $realStreamId)
                ->once()
                ->andReturnSelf();
            $pipe->shouldReceive('expire')
                ->twice()
                ->andReturnSelf();
            $pipe->shouldReceive('set')
                ->with("stream:{$realStreamId}:profile_id", $profile->id)
                ->once()
                ->andReturnSelf();
            $callback($pipe);
        });

    // Should not throw
    ProfileService::finalizeReservation($profile, $reservationId, $realStreamId);
});

test('cancelReservation decrements connections and cleans up', function () {
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

    // cancelReservation calls decrementConnections which uses Lua eval
    Redis::shouldReceive('eval')
        ->once()
        ->andReturn(1); // count went from 2 to 1

    // Cleanup pipeline
    Redis::shouldReceive('pipeline')
        ->once()
        ->andReturnUsing(function ($callback) {
            $pipe = Mockery::mock();
            $pipe->shouldReceive('del')->andReturnSelf();
            $pipe->shouldReceive('srem')->andReturnSelf();
            $callback($pipe);
        });

    // Two get calls: (1) channel reverse-key lookup → null (no mapping), (2) getConnectionCount.
    Redis::shouldReceive('get')->once()->ordered()->andReturn(null);
    Redis::shouldReceive('get')->once()->ordered()->andReturn(1);

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

    // Mock proxy returning 0 active streams
    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [],
            'total_clients' => 0,
        ]),
    ]);

    // reconcileFromProxy reads count (stale: 1), sets to 0
    // selectProfile reads count (0), hasCapacity reads count (0), post-increment reads count
    Redis::shouldReceive('get')
        ->andReturn(1, 0, 0, 0);

    Redis::shouldReceive('set')
        ->once()
        ->andReturnUsing(function ($key, $value) use ($profile) {
            expect($key)->toBe("playlist_profile:{$profile->id}:connections");
            expect($value)->toBe(0);
        });

    // incrementConnections pipeline
    Redis::shouldReceive('pipeline')
        ->once()
        ->andReturnUsing(function ($callback) {
            $pipe = Mockery::mock();
            $pipe->shouldReceive('incr')->andReturnSelf();
            $pipe->shouldReceive('expire')->andReturnSelf();
            $pipe->shouldReceive('set')->andReturnSelf();
            $pipe->shouldReceive('sadd')->andReturnSelf();
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

    $countKey = "playlist_profile:{$profile->id}:connections";

    // Proxy confirms 1 active stream
    Http::fake([
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
            'total_clients' => 1,
        ]),
    ]);

    // Count stays at 1 throughout (accurate, truly at capacity)
    Redis::shouldReceive('get')
        ->with($countKey)
        ->andReturn(1);

    [$selected, $reservationId] = ProfileService::reconcileAndSelectProfile($playlist);

    expect($selected)->toBeNull();
    expect($reservationId)->toBeNull();
});

// ── Fix #6: reconcileFromProxy iterates ALL profiles (including disabled) ──

test('reconcileFromProxy corrects stale count on disabled profile', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    // Create an enabled profile
    $enabledProfile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(5)
        ->create();

    // Create a disabled profile with stale Redis count
    $disabledProfile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->disabled()
        ->withMaxStreams(3)
        ->withPriority(1)
        ->create();

    // Proxy says no streams active for either profile
    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [],
            'total_clients' => 0,
        ]),
    ]);

    $enabledCountKey = "playlist_profile:{$enabledProfile->id}:connections";
    $disabledCountKey = "playlist_profile:{$disabledProfile->id}:connections";

    // Redis::get will be called for both profiles (enabled has 0, disabled has stale 2)
    Redis::shouldReceive('get')
        ->with($enabledCountKey)
        ->andReturn(0);

    Redis::shouldReceive('get')
        ->with($disabledCountKey)
        ->andReturn(2); // Stale count on disabled profile

    // Only the disabled profile needs correction (0 != 2)
    Redis::shouldReceive('set')
        ->once()
        ->with($disabledCountKey, 0);

    ProfileService::reconcileFromProxy($playlist);
});

test('reconcileFromProxy corrects both enabled and disabled profiles', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $enabledProfile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(5)
        ->create();

    $disabledProfile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->disabled()
        ->withMaxStreams(3)
        ->withPriority(1)
        ->create();

    // Proxy says 1 stream active on enabled profile, 0 on disabled
    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [
                [
                    'stream_id' => 'active-1',
                    'client_count' => 1,
                    'metadata' => [
                        'provider_profile_id' => $enabledProfile->id,
                        'playlist_uuid' => $playlist->uuid,
                    ],
                ],
            ],
            'total_clients' => 1,
        ]),
    ]);

    $enabledCountKey = "playlist_profile:{$enabledProfile->id}:connections";
    $disabledCountKey = "playlist_profile:{$disabledProfile->id}:connections";

    // Both have stale counts
    Redis::shouldReceive('get')
        ->with($enabledCountKey)
        ->andReturn(3); // Stale: should be 1

    Redis::shouldReceive('get')
        ->with($disabledCountKey)
        ->andReturn(1); // Stale: should be 0

    // Both need correction
    Redis::shouldReceive('set')
        ->once()
        ->with($enabledCountKey, 1);

    Redis::shouldReceive('set')
        ->once()
        ->with($disabledCountKey, 0);

    ProfileService::reconcileFromProxy($playlist);
});

test('reconcileFromProxy skips when profiles_enabled is false', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
    ]);

    // Should not make any HTTP or Redis calls
    Http::fake();

    ProfileService::reconcileFromProxy($playlist);

    Http::assertNothingSent();
});
