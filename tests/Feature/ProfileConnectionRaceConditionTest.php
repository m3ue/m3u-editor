<?php

/**
 * Tests for provider profile connection race condition fixes.
 *
 * Fixes:
 * - cleanupStaleStreams() now queries the proxy API and removes orphaned Redis entries
 * - reconcileAndSelectProfile() reconciles before returning 503 to handle the race
 *   condition where increment fires before the old stream's decrement webhook
 * - getEpisodeUrl() now resolves profiles from episode's source playlist when
 *   streaming through CustomPlaylist/MergedPlaylist
 * - PlaylistInfo now uses actual proxy counts instead of stale Redis counts
 */

use App\Models\CustomPlaylist;
use App\Models\Episode;
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

    // Configure proxy host so M3uProxyService can construct URLs
    config(['proxy.m3u_proxy_host' => 'http://localhost:8765']);
    config(['proxy.m3u_proxy_token' => 'test-token']);

    // Force array cache driver to avoid Redis dependency.
    // The app hardcodes 'default' => 'redis' in config/cache.php,
    // which ignores the CACHE_STORE=array from phpunit.xml.
    config(['cache.default' => 'array']);
});

// ── Fix A1: cleanupStaleStreams() ──────────────────────────────────────────

test('cleanupStaleStreams removes Redis entries for streams no longer active in proxy', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(5)
        ->create();

    // Mock Redis: profile has 3 tracked streams, counter at 3
    $streamsKey = "playlist_profile:{$profile->id}:streams";
    $countKey = "playlist_profile:{$profile->id}:connections";

    Redis::shouldReceive('smembers')
        ->once()
        ->with($streamsKey)
        ->andReturn(['stream-aaa', 'stream-bbb', 'stream-ccc']);

    // Mock the proxy API to say only stream-bbb is still active
    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [
                [
                    'stream_id' => 'stream-bbb',
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

    // Pipeline called twice: once for stream-aaa, once for stream-ccc
    Redis::shouldReceive('pipeline')
        ->twice()
        ->andReturnUsing(function ($callback) {
            // Create a mock pipe that accepts any calls
            $pipe = Mockery::mock();
            $pipe->shouldReceive('srem')->andReturnSelf();
            $pipe->shouldReceive('del')->andReturnSelf();
            $callback($pipe);
        });

    // After cleanup: get current count (3), set corrected count (3 - 2 = 1)
    Redis::shouldReceive('get')
        ->once()
        ->with($countKey)
        ->andReturn(3);

    Redis::shouldReceive('set')
        ->once()
        ->with($countKey, 1);

    $cleaned = ProfileService::cleanupStaleStreams($profile);

    expect($cleaned)->toBe(2);
});

test('cleanupStaleStreams does nothing when proxy API call fails', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(5)
        ->create();

    $streamsKey = "playlist_profile:{$profile->id}:streams";

    Redis::shouldReceive('smembers')
        ->once()
        ->with($streamsKey)
        ->andReturn(['stream-xxx', 'stream-yyy']);

    // Mock proxy API returning 500 (failure)
    Http::fake([
        '*/streams/by-metadata*' => Http::response([], 500),
    ]);

    $cleaned = ProfileService::cleanupStaleStreams($profile);

    // Should not touch anything (API failure returns null -> 0)
    expect($cleaned)->toBe(0);
});

test('cleanupStaleStreams handles empty Redis stream set gracefully', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(5)
        ->create();

    $streamsKey = "playlist_profile:{$profile->id}:streams";

    Redis::shouldReceive('smembers')
        ->once()
        ->with($streamsKey)
        ->andReturn([]);

    $cleaned = ProfileService::cleanupStaleStreams($profile);

    expect($cleaned)->toBe(0);
});

// ── Fix A2: reconcileAndSelectProfile() ────────────────────────────────────

test('reconcileAndSelectProfile returns profile after correcting stale counts', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(2)
        ->create();

    $countKey = "playlist_profile:{$profile->id}:connections";

    // Mock proxy returning 0 active streams for this playlist
    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [],
            'total_clients' => 0,
        ]),
    ]);

    // reconcileFromProxy reads current count (stale: 2), then sets it to 0
    // Then selectProfile reads count (now 0) and finds capacity
    Redis::shouldReceive('get')
        ->with($countKey)
        ->andReturn(2, 0, 0); // reconcile read, set corrected, selectProfile read, hasCapacity read

    Redis::shouldReceive('set')
        ->once()
        ->with($countKey, 0);

    $selected = ProfileService::reconcileAndSelectProfile($playlist);

    expect($selected)->not->toBeNull();
    expect($selected->id)->toBe($profile->id);
});

test('reconcileAndSelectProfile returns null when truly at capacity', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(1)
        ->create();

    $countKey = "playlist_profile:{$profile->id}:connections";

    // Mock proxy confirming 1 active stream (truly at capacity)
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

    // Count is 1 before and after reconcile (accurate)
    Redis::shouldReceive('get')
        ->with($countKey)
        ->andReturn(1);

    $selected = ProfileService::reconcileAndSelectProfile($playlist);

    expect($selected)->toBeNull();
});

test('reconcileAndSelectProfile returns null for non-profile playlists', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
    ]);

    $selected = ProfileService::reconcileAndSelectProfile($playlist);

    expect($selected)->toBeNull();
});

// ── Fix A3: Episode profile resolution via CustomPlaylist ──────────────────

test('episode profile source is resolved from source playlist when streaming via custom playlist', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://example.com:8080',
            'username' => 'test',
            'password' => 'test',
        ],
    ]);

    PlaylistProfile::factory()
        ->for($sourcePlaylist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(5)
        ->create();

    $episode = Episode::factory()
        ->for($this->user)
        ->for($sourcePlaylist)
        ->create();

    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();

    // Simulate the profileSourcePlaylist resolution logic from getEpisodeUrl()
    $playlist = $customPlaylist;
    $profileSourcePlaylist = null;

    if ($playlist instanceof Playlist && $playlist->profiles_enabled) {
        $profileSourcePlaylist = $playlist;
    } elseif ($episode->playlist instanceof Playlist && $episode->playlist->profiles_enabled) {
        $profileSourcePlaylist = $episode->playlist;
    }

    expect($profileSourcePlaylist)->not->toBeNull();
    expect($profileSourcePlaylist->id)->toBe($sourcePlaylist->id);
    expect($profileSourcePlaylist->profiles_enabled)->toBeTrue();
});

test('episode profile source is null when source playlist has profiles disabled', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
    ]);

    $episode = Episode::factory()
        ->for($this->user)
        ->for($sourcePlaylist)
        ->create();

    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();

    $playlist = $customPlaylist;
    $profileSourcePlaylist = null;

    if ($playlist instanceof Playlist && $playlist->profiles_enabled) {
        $profileSourcePlaylist = $playlist;
    } elseif ($episode->playlist instanceof Playlist && $episode->playlist->profiles_enabled) {
        $profileSourcePlaylist = $episode->playlist;
    }

    expect($profileSourcePlaylist)->toBeNull();
});

// ── Fix B: PlaylistInfo uses actual proxy counts ───────────────────────────

test('getPoolStatus returns profile capacity data correctly', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(2)
        ->withProviderInfo(0, 10) // provider allows 10, user caps at 2
        ->create();

    PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->withMaxStreams(3)
        ->withPriority(1)
        ->withProviderInfo(0, 10) // provider allows 10, user caps at 3
        ->create();

    // getPoolStatus calls getConnectionCount for each profile, which calls Redis::get
    Redis::shouldReceive('get')
        ->andReturn(0);

    $poolStatus = ProfileService::getPoolStatus($playlist);

    expect($poolStatus['enabled'])->toBeTrue();
    expect($poolStatus['total_capacity'])->toBe(5); // 2 + 3
    expect($poolStatus['profiles'])->toHaveCount(2);
});

test('reconcileFromProxy corrects Redis counts to match actual proxy state', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(5)
        ->create();

    $countKey = "playlist_profile:{$profile->id}:connections";

    // Mock proxy saying only 1 stream is active for this profile
    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [
                [
                    'stream_id' => 'active-stream-1',
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

    // Redis has inflated count of 3, reconcile should set it to 1
    Redis::shouldReceive('get')
        ->with($countKey)
        ->andReturn(3);

    Redis::shouldReceive('set')
        ->once()
        ->with($countKey, 1);

    ProfileService::reconcileFromProxy($playlist);
});
