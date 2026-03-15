<?php

/**
 * Tests for client-to-profile affinity (session tracking for provider profiles).
 *
 * When a client is assigned a provider profile, the mapping is stored in Redis.
 * On subsequent requests from the same client, the same profile is preferred,
 * preventing unnecessary profile switches during channel changes.
 */

use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Models\User;
use App\Services\ProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create([
        'permissions' => ['use_proxy'],
    ]);

    config(['proxy.m3u_proxy_host' => 'http://localhost:8765']);
    config(['proxy.m3u_proxy_token' => 'test-token']);
    config(['cache.default' => 'array']);
});

/**
 * Helper: create a playlist with profiles enabled.
 */
function createAffinityPlaylist(User $user, int $profileCount = 2, int $maxStreams = 2, bool $enableAffinity = true): Playlist
{
    $playlist = Playlist::factory()->for($user)->create([
        'profiles_enabled' => true,
        'enable_proxy' => true,
        'xtream' => false,
        'available_streams' => 0,
        'enable_provider_affinity' => $enableAffinity,
    ]);

    for ($i = 0; $i < $profileCount; $i++) {
        PlaylistProfile::factory()
            ->for($playlist)
            ->for($user)
            ->withProviderInfo(0, $maxStreams)
            ->withMaxStreams($maxStreams)
            ->create([
                'is_primary' => $i === 0,
                'priority' => $i,
                'enabled' => true,
            ]);
    }

    return $playlist;
}

// ── buildClientIdentifier ─────────────────────────────────────────────────

test('buildClientIdentifier returns ip:username when both available', function () {
    $result = ProfileService::buildClientIdentifier('192.168.1.1', 'alice');

    expect($result)->toBe('192.168.1.1:alice');
});

test('buildClientIdentifier returns ip when username is null', function () {
    $result = ProfileService::buildClientIdentifier('192.168.1.1', null);

    expect($result)->toBe('192.168.1.1');
});

test('buildClientIdentifier returns null when ip is null', function () {
    $result = ProfileService::buildClientIdentifier(null, 'alice');

    expect($result)->toBeNull();
});

// ── No affinity → normal priority-based selection ─────────────────────────

test('selectProfile uses normal priority selection when no affinity exists', function () {
    $playlist = createAffinityPlaylist($this->user, profileCount: 2, maxStreams: 2);
    $profiles = $playlist->enabledProfiles()->get();

    // All profiles have capacity
    Redis::shouldReceive('get')
        ->andReturn(0);
    Redis::shouldReceive('expire')
        ->andReturn(true);

    $selected = ProfileService::selectProfile($playlist, clientIdentifier: '10.0.0.1:bob');

    // Should pick the first by priority (priority=0)
    expect($selected)->not->toBeNull()
        ->and($selected->id)->toBe($profiles->first()->id);
});

// ── With affinity → same profile reused ───────────────────────────────────

test('selectProfile reuses affinity profile when it has capacity', function () {
    $playlist = createAffinityPlaylist($this->user, profileCount: 2, maxStreams: 2);
    $profiles = $playlist->enabledProfiles()->get();
    $secondProfile = $profiles->skip(1)->first();

    // Store affinity pointing to the SECOND profile (not the highest priority)
    $affinityKey = ProfileService::getClientAffinityKey('10.0.0.1:bob', $playlist->id);

    Redis::shouldReceive('get')
        ->with($affinityKey)
        ->once()
        ->andReturn((string) $secondProfile->id);

    Redis::shouldReceive('expire')
        ->with($affinityKey, 86400)
        ->once()
        ->andReturn(true);

    // The affinity profile has capacity
    $countKey = "playlist_profile:{$secondProfile->id}:connections";
    Redis::shouldReceive('get')
        ->with($countKey)
        ->andReturn(0);

    $selected = ProfileService::selectProfile($playlist, clientIdentifier: '10.0.0.1:bob');

    // Should pick the affinity profile, NOT the highest priority
    expect($selected)->not->toBeNull()
        ->and($selected->id)->toBe($secondProfile->id);
});

// ── Affinity to at-capacity profile → still uses affinity (capacity ignored) ─

test('selectProfile uses affinity profile even when it is at capacity', function () {
    $playlist = createAffinityPlaylist($this->user, profileCount: 2, maxStreams: 2);
    $profiles = $playlist->enabledProfiles()->get();
    $secondProfile = $profiles->skip(1)->first();

    // Store affinity pointing to the SECOND profile
    $affinityKey = ProfileService::getClientAffinityKey('10.0.0.1:bob', $playlist->id);

    Redis::shouldReceive('get')
        ->with($affinityKey)
        ->once()
        ->andReturn((string) $secondProfile->id);

    Redis::shouldReceive('expire')
        ->with($affinityKey, 86400)
        ->once()
        ->andReturn(true);

    // Affinity profile is at capacity — but affinity always wins when enable_provider_affinity is true
    $selected = ProfileService::selectProfile($playlist, clientIdentifier: '10.0.0.1:bob');

    // Capacity is not checked for the affinity profile — it is always returned
    expect($selected)->not->toBeNull()
        ->and($selected->id)->toBe($secondProfile->id);
});

// ── forceSelect does not affect affinity behaviour ────────────────────────

test('selectProfile uses affinity profile when forceSelect is true', function () {
    $playlist = createAffinityPlaylist($this->user, profileCount: 2, maxStreams: 2);
    $profiles = $playlist->enabledProfiles()->get();
    $secondProfile = $profiles->skip(1)->first();

    // Store affinity pointing to the SECOND profile
    $affinityKey = ProfileService::getClientAffinityKey('10.0.0.1:bob', $playlist->id);

    Redis::shouldReceive('get')
        ->with($affinityKey)
        ->once()
        ->andReturn((string) $secondProfile->id);

    Redis::shouldReceive('expire')
        ->with($affinityKey, 86400)
        ->once()
        ->andReturn(true);

    $selected = ProfileService::selectProfile($playlist, forceSelect: true, clientIdentifier: '10.0.0.1:bob');

    // Affinity profile is returned; forceSelect is independent of affinity behaviour
    expect($selected)->not->toBeNull()
        ->and($selected->id)->toBe($secondProfile->id);
});

// ── Affinity to disabled profile → falls back ────────────────────────────

test('selectProfile falls back when affinity profile is disabled', function () {
    $playlist = createAffinityPlaylist($this->user, profileCount: 2, maxStreams: 2);
    $profiles = $playlist->enabledProfiles()->get();
    $firstProfile = $profiles->first();

    // Create a disabled profile
    $disabledProfile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->disabled()
        ->withMaxStreams(2)
        ->create(['priority' => 5]);

    // Store affinity pointing to the disabled profile
    $affinityKey = ProfileService::getClientAffinityKey('10.0.0.1:bob', $playlist->id);

    Redis::shouldReceive('get')
        ->with($affinityKey)
        ->once()
        ->andReturn((string) $disabledProfile->id);

    Redis::shouldReceive('expire')
        ->with($affinityKey, 86400)
        ->once()
        ->andReturn(true);

    // The enabled profiles have capacity
    Redis::shouldReceive('get')
        ->with("playlist_profile:{$firstProfile->id}:connections")
        ->andReturn(0);

    $selected = ProfileService::selectProfile($playlist, clientIdentifier: '10.0.0.1:bob');

    // Disabled profile won't be in enabledProfiles(), so falls back to normal selection
    expect($selected)->not->toBeNull()
        ->and($selected->id)->toBe($firstProfile->id);
});

// ── Affinity to excluded profile → falls back ────────────────────────────

test('selectProfile falls back when affinity profile is excluded', function () {
    $playlist = createAffinityPlaylist($this->user, profileCount: 2, maxStreams: 2);
    $profiles = $playlist->enabledProfiles()->get();
    $firstProfile = $profiles->first();
    $secondProfile = $profiles->skip(1)->first();

    // Store affinity pointing to the first profile
    $affinityKey = ProfileService::getClientAffinityKey('10.0.0.1:bob', $playlist->id);

    Redis::shouldReceive('get')
        ->with($affinityKey)
        ->once()
        ->andReturn((string) $firstProfile->id);

    Redis::shouldReceive('expire')
        ->with($affinityKey, 86400)
        ->once()
        ->andReturn(true);

    // Second profile has capacity
    Redis::shouldReceive('get')
        ->with("playlist_profile:{$secondProfile->id}:connections")
        ->andReturn(0);

    // Exclude the affinity profile
    $selected = ProfileService::selectProfile($playlist, excludeProfileId: $firstProfile->id, clientIdentifier: '10.0.0.1:bob');

    // The excluded affinity profile won't be in the collection, falls back
    expect($selected)->not->toBeNull()
        ->and($selected->id)->toBe($secondProfile->id);
});

// ── Affinity stored after successful selectAndReserveProfile ──────────────

test('affinity is stored after successful selectAndReserveProfile', function () {
    $playlist = createAffinityPlaylist($this->user, profileCount: 2, maxStreams: 2);
    $profiles = $playlist->enabledProfiles()->get();
    $firstProfile = $profiles->first();
    $clientIdentifier = '10.0.0.1:carol';

    // No existing affinity
    $affinityKey = ProfileService::getClientAffinityKey($clientIdentifier, $playlist->id);
    Redis::shouldReceive('get')
        ->with($affinityKey)
        ->andReturn(null);

    // Profile has capacity
    Redis::shouldReceive('get')
        ->with("playlist_profile:{$firstProfile->id}:connections")
        ->andReturn(0);

    // Expect increment pipeline
    Redis::shouldReceive('pipeline')->once()->andReturnUsing(function ($callback) {
        $pipe = Mockery::mock();
        $pipe->shouldReceive('incr')->once();
        $pipe->shouldReceive('expire')->times(3);
        $pipe->shouldReceive('set')->once();
        $pipe->shouldReceive('sadd')->once();
        $callback($pipe);
    });

    // Expect affinity to be stored
    Redis::shouldReceive('setex')
        ->with($affinityKey, 86400, $firstProfile->id)
        ->once();

    [$selectedProfile, $reservationId] = ProfileService::selectAndReserveProfile(
        $playlist,
        clientIdentifier: $clientIdentifier,
    );

    expect($selectedProfile)->not->toBeNull()
        ->and($selectedProfile->id)->toBe($firstProfile->id)
        ->and($reservationId)->toStartWith('reservation:');
});

// ── TTL refresh on subsequent use ─────────────────────────────────────────

test('affinity TTL is refreshed on subsequent reads', function () {
    $clientIdentifier = '10.0.0.1:dave';
    $playlistId = 42;
    $profileId = 7;

    $affinityKey = ProfileService::getClientAffinityKey($clientIdentifier, $playlistId);

    // Simulate reading existing affinity
    Redis::shouldReceive('get')
        ->with($affinityKey)
        ->once()
        ->andReturn((string) $profileId);

    // Expect TTL to be refreshed
    Redis::shouldReceive('expire')
        ->with($affinityKey, 86400)
        ->once()
        ->andReturn(true);

    $result = ProfileService::getClientAffinity($clientIdentifier, $playlistId);

    expect($result)->toBe($profileId);
});

// ── Affinity scoped per-playlist ──────────────────────────────────────────

test('affinity is scoped per playlist with independent keys', function () {
    $clientIdentifier = '10.0.0.1:eve';

    $key1 = ProfileService::getClientAffinityKey($clientIdentifier, 1);
    $key2 = ProfileService::getClientAffinityKey($clientIdentifier, 2);

    expect($key1)->toBe('client_affinity:10.0.0.1:eve:1')
        ->and($key2)->toBe('client_affinity:10.0.0.1:eve:2')
        ->and($key1)->not->toBe($key2);
});

// ── enable_provider_affinity = false → affinity ignored ───────────────────

test('selectProfile ignores affinity when enable_provider_affinity is false', function () {
    $playlist = createAffinityPlaylist($this->user, profileCount: 2, maxStreams: 2, enableAffinity: false);
    $profiles = $playlist->enabledProfiles()->get();
    $firstProfile = $profiles->first();
    $secondProfile = $profiles->skip(1)->first();

    // Store affinity pointing to the SECOND profile
    $affinityKey = ProfileService::getClientAffinityKey('10.0.0.1:bob', $playlist->id);

    // Affinity key should NOT be read since affinity is disabled
    Redis::shouldReceive('get')
        ->with($affinityKey)
        ->never();

    // First profile has capacity for normal priority-based selection
    Redis::shouldReceive('get')
        ->with("playlist_profile:{$firstProfile->id}:connections")
        ->andReturn(0);

    $selected = ProfileService::selectProfile($playlist, clientIdentifier: '10.0.0.1:bob');

    // Should fall through to normal priority selection — first profile by priority
    expect($selected)->not->toBeNull()
        ->and($selected->id)->toBe($firstProfile->id);
});

// ── Same IP + different usernames → independent affinities ────────────────

test('same IP with different usernames produces independent identifiers', function () {
    $id1 = ProfileService::buildClientIdentifier('192.168.1.1', 'alice');
    $id2 = ProfileService::buildClientIdentifier('192.168.1.1', 'bob');

    expect($id1)->toBe('192.168.1.1:alice')
        ->and($id2)->toBe('192.168.1.1:bob')
        ->and($id1)->not->toBe($id2);

    // And their affinity keys for the same playlist are distinct
    $key1 = ProfileService::getClientAffinityKey($id1, 1);
    $key2 = ProfileService::getClientAffinityKey($id2, 1);

    expect($key1)->not->toBe($key2);
});
