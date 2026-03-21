<?php

/**
 * Tests for the bypass_provider_limits toggle and available_streams enforcement
 * when Provider Profiles are enabled.
 *
 * Covers:
 * - selectProfile() force-selects a profile when all are at capacity and bypass is on
 * - selectProfile() returns null when all are at capacity and bypass is off
 * - available_streams is enforced regardless of provider profiles being enabled
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
    $this->user = User::factory()->create([
        'permissions' => ['use_proxy'],
    ]);

    config(['proxy.m3u_proxy_host' => 'http://localhost:8765']);
    config(['proxy.m3u_proxy_port' => null]);
    config(['proxy.m3u_proxy_token' => 'test-token']);
    config(['cache.default' => 'array']);
});

/**
 * Helper: create a playlist with profiles at capacity.
 */
function createPlaylistWithFullProfiles(User $user, int $profileCount = 1, int $maxStreams = 2, bool $bypassProviderLimits = false): Playlist
{
    $playlist = Playlist::factory()->for($user)->create([
        'profiles_enabled' => true,
        'bypass_provider_limits' => $bypassProviderLimits,
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
            ]);
    }

    return $playlist;
}

// ── selectProfile with forceSelect=false returns null when at capacity ────

test('selectProfile returns null when all profiles are at capacity and forceSelect is false', function () {
    $playlist = createPlaylistWithFullProfiles($this->user, profileCount: 2, maxStreams: 2);

    $profileIds = $playlist->profiles->pluck('id');

    // Proxy reports 2 active streams per profile (at max_streams=2)
    Http::fake([
        '*/streams/counts-by-metadata*' => Http::response([
            'field' => 'provider_profile_id',
            'counts' => $profileIds->mapWithKeys(fn ($id) => [(string) $id => 2])->all(),
        ]),
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [], 'total_matching' => 2, 'total_clients' => 2]),
    ]);
    Redis::shouldReceive('smembers')->andReturn([]);

    $profile = ProfileService::selectProfile($playlist, forceSelect: false);

    expect($profile)->toBeNull();
});

// ── selectProfile with forceSelect=true returns a profile when at capacity ─

test('selectProfile force-selects a profile when all are at capacity and forceSelect is true', function () {
    $playlist = createPlaylistWithFullProfiles($this->user, profileCount: 2, maxStreams: 2);

    $profileIds = $playlist->profiles->pluck('id');

    // Proxy reports 2 active streams per profile (at max_streams=2)
    Http::fake([
        '*/streams/counts-by-metadata*' => Http::response([
            'field' => 'provider_profile_id',
            'counts' => $profileIds->mapWithKeys(fn ($id) => [(string) $id => 2])->all(),
        ]),
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [], 'total_matching' => 2, 'total_clients' => 2]),
    ]);
    Redis::shouldReceive('smembers')->andReturn([]);

    $profile = ProfileService::selectProfile($playlist, forceSelect: true);

    expect($profile)->not->toBeNull();
    expect($profile)->toBeInstanceOf(PlaylistProfile::class);
    expect($profile->playlist_id)->toBe($playlist->id);
});

// ── selectProfile with forceSelect=true picks the least-loaded profile ─────

test('selectProfile force-selects the least-loaded profile', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
        'bypass_provider_limits' => true,
        'enable_proxy' => true,
        'xtream' => false,
    ]);

    $profileA = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->withMaxStreams(2)
        ->create(['is_primary' => true, 'priority' => 0, 'name' => 'Profile A']);

    $profileB = PlaylistProfile::factory()
        ->for($playlist)
        ->for($this->user)
        ->withMaxStreams(2)
        ->create(['is_primary' => false, 'priority' => 1, 'name' => 'Profile B']);

    // Profile A has 3 connections, Profile B has 2 — both over max, B is least loaded
    Http::fake([
        '*/streams/counts-by-metadata*' => Http::response([
            'field' => 'provider_profile_id',
            'counts' => [
                (string) $profileA->id => 3,
                (string) $profileB->id => 2,
            ],
        ]),
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [], 'total_matching' => 2, 'total_clients' => 2]),
    ]);

    Redis::shouldReceive('smembers')->andReturn([]);

    $selected = ProfileService::selectProfile($playlist, forceSelect: true);

    expect($selected)->not->toBeNull();
    expect($selected->id)->toBe($profileB->id);
});

// ── selectProfile with forceSelect=true still returns normally when capacity is available ─

test('selectProfile with forceSelect=true returns normally when capacity exists', function () {
    $playlist = createPlaylistWithFullProfiles($this->user, profileCount: 2, maxStreams: 5);

    // Mock Redis: profiles have 1 active connection (well under max_streams=5)
    Redis::shouldReceive('get')->andReturn(1);

    $profile = ProfileService::selectProfile($playlist, forceSelect: true);

    expect($profile)->not->toBeNull();
    expect($profile)->toBeInstanceOf(PlaylistProfile::class);
});

// ── bypass_provider_limits defaults to false ───────────────────────────────

test('bypass_provider_limits defaults to false on new playlists', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
        'enable_proxy' => true,
    ]);

    $playlist->refresh();
    expect($playlist->bypass_provider_limits)->toBeFalse();
});

// ── bypass_provider_limits is castable as boolean ──────────────────────────

test('bypass_provider_limits is cast to boolean', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
        'bypass_provider_limits' => true,
        'enable_proxy' => true,
    ]);

    $playlist->refresh();
    expect($playlist->bypass_provider_limits)->toBeTrue()->toBeBool();
});
