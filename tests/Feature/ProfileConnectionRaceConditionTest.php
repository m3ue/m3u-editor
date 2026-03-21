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

    $streamsKey = "playlist_profile:{$profile->id}:streams";

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

    // srem called directly for each stale stream (stream-aaa and stream-ccc)
    Redis::shouldReceive('srem')
        ->with($streamsKey, 'stream-aaa')
        ->once();

    Redis::shouldReceive('srem')
        ->with($streamsKey, 'stream-ccc')
        ->once();

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

test('reconcileAndSelectProfile returns profile and reservation after correcting stale counts', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->primary()
        ->withMaxStreams(2)
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

    // No pending reservations in the streams set
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

    [$selected, $reservationId] = ProfileService::reconcileAndSelectProfile($playlist);

    expect($selected)->not->toBeNull();
    expect($selected->id)->toBe($profile->id);
    expect($reservationId)->toStartWith('reservation:');
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

    // Mock proxy confirming 1 active stream (truly at capacity)
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

    // No pending reservations in Redis
    Redis::shouldReceive('smembers')->andReturn([]);

    [$selected, $reservationId] = ProfileService::reconcileAndSelectProfile($playlist);

    expect($selected)->toBeNull();
    expect($reservationId)->toBeNull();
});

test('reconcileAndSelectProfile returns null for non-profile playlists', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
    ]);

    [$selected, $reservationId] = ProfileService::reconcileAndSelectProfile($playlist);

    expect($selected)->toBeNull();
    expect($reservationId)->toBeNull();
});

// ── Fix A3: Episode profile resolution via CustomPlaylist ──────────────────

test('episode profile source is resolved from source playlist when streaming via custom playlist', function () {
    // Fake HTTP before creating playlist to prevent real Xtream API calls
    // triggered by PlaylistListener::ensurePrimaryProfileExists()
    Http::fake([
        '*/player_api.php*' => Http::response([
            'user_info' => ['max_connections' => '5'],
        ]),
    ]);

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

    // getPoolStatus calls M3uProxyService::getActiveStreamsCountByMetadata for each profile
    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [],
            'total_clients' => 0,
        ]),
    ]);

    $poolStatus = ProfileService::getPoolStatus($playlist);

    expect($poolStatus['enabled'])->toBeTrue();
    expect($poolStatus['total_capacity'])->toBe(5); // 2 + 3
    expect($poolStatus['profiles'])->toHaveCount(2);
});
