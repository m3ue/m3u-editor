<?php

/**
 * Tests for PlaylistInfo component displaying correct proxy usage stats across all playlist types.
 *
 * Verifies that available_streams (the authoritative proxy-level limit) is displayed
 * correctly regardless of whether Provider Profiles are enabled.
 */

use App\Facades\PlaylistFacade;
use App\Livewire\PlaylistInfo;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create([
        'permissions' => ['use_proxy'],
    ]);

    // Configure proxy host so M3uProxyService can construct URLs
    config(['proxy.m3u_proxy_host' => 'http://localhost:8765']);
    config(['proxy.m3u_proxy_token' => 'test-token']);

    // Force array cache driver to avoid Redis dependency
    config(['cache.default' => 'array']);

    // Mock proxy API to return 2 active clients by default
    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [],
            'total_clients' => 2,
        ]),
    ]);
});

/**
 * Helper: create a source playlist with profiles enabled and profiles with known capacity.
 */
function createSourcePlaylistWithProfiles(User $user, int $profileCount = 1, int $maxStreams = 5): Playlist
{
    $playlist = Playlist::factory()->for($user)->create([
        'profiles_enabled' => true,
        'enable_proxy' => true,
        'xtream' => false,
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

/**
 * Helper: call getStats() on a PlaylistInfo component for a given record.
 * Mocks PlaylistFacade to return the resolved playlist.
 */
function callGetStats(Model $record, Model $resolvedPlaylist): array
{
    PlaylistFacade::shouldReceive('resolvePlaylistByUuid')
        ->once()
        ->with($record->uuid)
        ->andReturn($resolvedPlaylist);

    $component = new PlaylistInfo;
    $component->record = $record;

    return $component->getStats();
}

// ── Playlist with profiles: shows numeric capacity ────────────────────────

test('getStats shows playlist available_streams for Playlist with profiles enabled', function () {
    $playlist = createSourcePlaylistWithProfiles($this->user, profileCount: 2, maxStreams: 5);
    $playlist->update(['available_streams' => 15]);

    $stats = callGetStats($playlist, $playlist);

    expect($stats['proxy_enabled'])->toBeTrue();
    expect($stats['active_streams'])->toBe(2); // from Http::fake
    // available_streams is the authoritative proxy limit, not pool capacity
    expect($stats['available_streams'])->toBe(15);
    expect($stats['active_connections'])->toBe('2/15');
});

test('getStats shows infinity for Playlist with profiles enabled and zero available_streams', function () {
    $playlist = createSourcePlaylistWithProfiles($this->user, profileCount: 2, maxStreams: 5);
    $playlist->update(['available_streams' => 0]);

    $stats = callGetStats($playlist, $playlist);

    expect($stats['proxy_enabled'])->toBeTrue();
    expect($stats['active_streams'])->toBe(2);
    expect($stats['available_streams'])->toBe('∞');
});

// ── Playlist without profiles: shows ∞ when available_streams is 0 ────────

test('getStats shows infinity for Playlist without profiles and zero available_streams', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);

    $stats = callGetStats($playlist, $playlist);

    expect($stats['proxy_enabled'])->toBeTrue();
    expect($stats['available_streams'])->toBe('∞');
});

// ── CustomPlaylist with profiled source: shows numeric capacity ───────────

test('getStats shows playlist available_streams for CustomPlaylist with profiled source playlists', function () {
    $sourcePlaylist = createSourcePlaylistWithProfiles($this->user, profileCount: 1, maxStreams: 3);

    $channel = Channel::factory()->for($this->user)->for($sourcePlaylist)->create([
        'enabled' => true,
    ]);

    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 8,
    ]);
    $customPlaylist->channels()->attach($channel->id);

    $stats = callGetStats($customPlaylist, $customPlaylist);

    expect($stats['proxy_enabled'])->toBeTrue();
    expect($stats['active_streams'])->toBe(2); // from Http::fake
    // available_streams is the authoritative proxy limit, not pool capacity
    expect($stats['available_streams'])->toBe(8);
    expect($stats['active_connections'])->toBe('2/8');
});

// ── CustomPlaylist with multiple profiled sources: aggregates capacity ────

test('getStats uses playlist available_streams for CustomPlaylist with multiple profiled sources', function () {
    $sourceA = createSourcePlaylistWithProfiles($this->user, profileCount: 1, maxStreams: 4);
    $sourceB = createSourcePlaylistWithProfiles($this->user, profileCount: 2, maxStreams: 3);

    $channelA = Channel::factory()->for($this->user)->for($sourceA)->create(['enabled' => true]);
    $channelB = Channel::factory()->for($this->user)->for($sourceB)->create(['enabled' => true]);

    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 20,
    ]);
    $customPlaylist->channels()->attach([$channelA->id, $channelB->id]);

    $stats = callGetStats($customPlaylist, $customPlaylist);

    expect($stats['proxy_enabled'])->toBeTrue();
    // Uses playlist's available_streams, not aggregated pool capacity
    expect($stats['available_streams'])->toBe(20);
    expect($stats['active_connections'])->toBe('2/20');
});

// ── CustomPlaylist without profiled sources: shows ∞ ──────────────────────

test('getStats shows infinity for CustomPlaylist without profiled source playlists', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
    ]);

    $channel = Channel::factory()->for($this->user)->for($sourcePlaylist)->create([
        'enabled' => true,
    ]);

    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);
    $customPlaylist->channels()->attach($channel->id);

    $stats = callGetStats($customPlaylist, $customPlaylist);

    expect($stats['proxy_enabled'])->toBeTrue();
    expect($stats['available_streams'])->toBe('∞');
});

// ── MergedPlaylist with profiled source: shows numeric capacity ───────────

test('getStats shows playlist available_streams for MergedPlaylist with profiled source playlists', function () {
    $sourcePlaylist = createSourcePlaylistWithProfiles($this->user, profileCount: 2, maxStreams: 4);

    $mergedPlaylist = MergedPlaylist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 12,
    ]);
    $mergedPlaylist->playlists()->attach($sourcePlaylist->id);

    $stats = callGetStats($mergedPlaylist, $mergedPlaylist);

    expect($stats['proxy_enabled'])->toBeTrue();
    // Uses playlist's available_streams, not pool capacity
    expect($stats['available_streams'])->toBe(12);
    expect($stats['active_connections'])->toBe('2/12');
});

// ── MergedPlaylist without profiled sources: shows ∞ ──────────────────────

test('getStats shows infinity for MergedPlaylist without profiled source playlists', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
    ]);

    $mergedPlaylist = MergedPlaylist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);
    $mergedPlaylist->playlists()->attach($sourcePlaylist->id);

    $stats = callGetStats($mergedPlaylist, $mergedPlaylist);

    expect($stats['proxy_enabled'])->toBeTrue();
    expect($stats['available_streams'])->toBe('∞');
});

// ── PlaylistAlias backed by Playlist with profiles ────────────────────────

test('getStats shows playlist available_streams for PlaylistAlias backed by profiled Playlist', function () {
    $sourcePlaylist = createSourcePlaylistWithProfiles($this->user, profileCount: 1, maxStreams: 6);

    $alias = PlaylistAlias::create([
        'name' => 'Test Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $this->user->id,
        'playlist_id' => $sourcePlaylist->id,
        'custom_playlist_id' => null,
        'xtream_config' => '[]',
        'enable_proxy' => true,
        'available_streams' => 10,
        'enabled' => true,
    ]);

    $stats = callGetStats($alias, $alias);

    expect($stats['proxy_enabled'])->toBeTrue();
    // Uses alias's available_streams, not pool capacity
    expect($stats['available_streams'])->toBe(10);
    expect($stats['active_connections'])->toBe('2/10');
});

// ── PlaylistAlias backed by CustomPlaylist with profiled source ───────────

test('getStats shows playlist available_streams for PlaylistAlias backed by CustomPlaylist with profiled sources', function () {
    $sourcePlaylist = createSourcePlaylistWithProfiles($this->user, profileCount: 1, maxStreams: 5);

    $channel = Channel::factory()->for($this->user)->for($sourcePlaylist)->create([
        'enabled' => true,
    ]);

    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 7,
    ]);
    $customPlaylist->channels()->attach($channel->id);

    $alias = PlaylistAlias::create([
        'name' => 'Test Alias for Custom',
        'uuid' => fake()->uuid(),
        'user_id' => $this->user->id,
        'playlist_id' => null,
        'custom_playlist_id' => $customPlaylist->id,
        'xtream_config' => '[]',
        'enable_proxy' => true,
        'available_streams' => 7,
        'enabled' => true,
    ]);

    $stats = callGetStats($alias, $alias);

    expect($stats['proxy_enabled'])->toBeTrue();
    // Uses alias's available_streams, not pool capacity
    expect($stats['available_streams'])->toBe(7);
    expect($stats['active_connections'])->toBe('2/7');
});

// ── Mixed: CustomPlaylist with some profiled and some non-profiled sources ─

test('getStats uses playlist available_streams for CustomPlaylist with mixed profiled and regular sources', function () {
    $profiledSource = createSourcePlaylistWithProfiles($this->user, profileCount: 1, maxStreams: 5);
    $regularSource = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
    ]);

    $channelA = Channel::factory()->for($this->user)->for($profiledSource)->create(['enabled' => true]);
    $channelB = Channel::factory()->for($this->user)->for($regularSource)->create(['enabled' => true]);

    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 15,
    ]);
    $customPlaylist->channels()->attach([$channelA->id, $channelB->id]);

    $stats = callGetStats($customPlaylist, $customPlaylist);

    expect($stats['proxy_enabled'])->toBeTrue();
    // Uses playlist's available_streams, not aggregated pool capacity
    expect($stats['available_streams'])->toBe(15);
    expect($stats['active_connections'])->toBe('2/15');
});
