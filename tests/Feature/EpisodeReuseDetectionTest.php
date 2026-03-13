<?php

/**
 * Tests for episode reuse detection in provider profile selection.
 *
 * When selectAndReserveProfile() is called with channelId and channelPlaylistUuid,
 * it should create a channel stream key for reuse detection, and detect when
 * another request is already creating a stream for the same channel.
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
function createProfilePlaylist(User $user, int $profileCount = 2, int $maxStreams = 2): Playlist
{
    $playlist = Playlist::factory()->for($user)->create([
        'profiles_enabled' => true,
        'enable_proxy' => true,
        'xtream' => false,
        'available_streams' => 0,
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

// ── Episode reuse detection ───────────────────────────────────────────────

test('selectAndReserveProfile sets channel stream key when episode ID and playlist UUID are provided', function () {
    $playlist = createProfilePlaylist($this->user, profileCount: 2, maxStreams: 2);
    $profiles = $playlist->enabledProfiles()->get();
    $firstProfile = $profiles->first();
    $episodeId = 42;
    $playlistUuid = $playlist->uuid;

    // No existing channel stream key (reuse check)
    $channelStreamKey = "channel_stream:{$episodeId}:{$playlistUuid}";
    Redis::shouldReceive('exists')
        ->with($channelStreamKey)
        ->once()
        ->andReturn(false);

    // Profile has capacity
    Redis::shouldReceive('get')
        ->with("playlist_profile:{$firstProfile->id}:connections")
        ->andReturn(0);

    // Expect increment pipeline (includes channel stream key creation)
    Redis::shouldReceive('pipeline')->twice()->andReturnUsing(function ($callback) {
        $pipe = Mockery::mock();
        $pipe->shouldReceive('incr')->zeroOrMoreTimes();
        $pipe->shouldReceive('expire')->zeroOrMoreTimes();
        $pipe->shouldReceive('set')->zeroOrMoreTimes();
        $pipe->shouldReceive('sadd')->zeroOrMoreTimes();
        $pipe->shouldReceive('setex')->zeroOrMoreTimes();
        $callback($pipe);
    });

    [$selectedProfile, $reservationId] = ProfileService::selectAndReserveProfile(
        $playlist,
        null,
        $episodeId,
        $playlistUuid,
    );

    expect($selectedProfile)->not->toBeNull()
        ->and($selectedProfile->id)->toBe($firstProfile->id)
        ->and($reservationId)->toStartWith('reservation:');
});

test('selectAndReserveProfile detects reuse when episode stream key already exists', function () {
    $playlist = createProfilePlaylist($this->user, profileCount: 2, maxStreams: 2);
    $episodeId = 42;
    $playlistUuid = $playlist->uuid;

    // Channel stream key already exists (another request is creating this stream)
    $channelStreamKey = "channel_stream:{$episodeId}:{$playlistUuid}";
    Redis::shouldReceive('exists')
        ->with($channelStreamKey)
        ->once()
        ->andReturn(true);

    [$selectedProfile, $reservationId] = ProfileService::selectAndReserveProfile(
        $playlist,
        null,
        $episodeId,
        $playlistUuid,
    );

    // Should return [null, null] to signal reuse detection
    expect($selectedProfile)->toBeNull()
        ->and($reservationId)->toBeNull();
});
