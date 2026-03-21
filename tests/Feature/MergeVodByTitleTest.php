<?php

use App\Jobs\MergeChannels;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
    Notification::fake();
});

test('it merges VOD channels by TMDB ID across providers', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly(['name' => 'Provider 1']);
    $playlist2 = Playlist::factory()->for($user)->createQuietly(['name' => 'Provider 2']);

    // Same movie from different providers with different stream_ids but same TMDB ID
    $vod1 = Channel::factory()->create([
        'title' => 'DK | The Last Viking',
        'name' => 'The Last Viking',
        'stream_id' => 'prov1_12345',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'is_vod' => true,
        'can_merge' => true,
        'enabled' => true,
        'tmdb_id' => 603692,
    ]);

    $vod2 = Channel::factory()->create([
        'title' => 'SC - The Last Viking (2025)',
        'name' => 'The Last Viking 2025',
        'stream_id' => 'prov2_67890',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'is_vod' => true,
        'can_merge' => true,
        'enabled' => true,
        'tmdb_id' => 603692,
    ]);

    // A different movie with a different TMDB ID that should NOT be merged
    $differentVod = Channel::factory()->create([
        'title' => 'SC - The Matrix (1999)',
        'name' => 'The Matrix',
        'stream_id' => 'prov2_99999',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'is_vod' => true,
        'can_merge' => true,
        'enabled' => true,
        'tmdb_id' => 603,
    ]);

    $playlists = collect([
        ['playlist_failover_id' => $playlist1->id],
        ['playlist_failover_id' => $playlist2->id],
    ]);

    MergeChannels::dispatchSync(
        $user,
        $playlists,
        $playlist1->id,
        mergeVodByTmdbId: true,
    );

    // The two VODs with the same TMDB ID should be merged with failover
    $failovers = ChannelFailover::where('user_id', $user->id)->get();
    expect($failovers)->toHaveCount(1);

    $failover = $failovers->first();
    // Master should be from the preferred playlist
    expect($failover->channel_id)->toBe($vod1->id);
    expect($failover->channel_failover_id)->toBe($vod2->id);

    // The Matrix should not be part of any failover
    expect(ChannelFailover::where('channel_id', $differentVod->id)->exists())->toBeFalse();
    expect(ChannelFailover::where('channel_failover_id', $differentVod->id)->exists())->toBeFalse();
});

test('it does not merge VOD by TMDB ID when feature is disabled', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly();
    $playlist2 = Playlist::factory()->for($user)->createQuietly();

    Channel::factory()->create([
        'title' => 'DK | The Last Viking',
        'stream_id' => 'prov1_12345',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'is_vod' => true,
        'can_merge' => true,
        'tmdb_id' => 603692,
    ]);

    Channel::factory()->create([
        'title' => 'SC - The Last Viking (2025)',
        'stream_id' => 'prov2_67890',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'is_vod' => true,
        'can_merge' => true,
        'tmdb_id' => 603692,
    ]);

    $playlists = collect([
        ['playlist_failover_id' => $playlist1->id],
        ['playlist_failover_id' => $playlist2->id],
    ]);

    // mergeVodByTmdbId defaults to false
    MergeChannels::dispatchSync($user, $playlists, $playlist1->id);

    // No merges since stream_ids differ and TMDB merge is off
    expect(ChannelFailover::where('user_id', $user->id)->count())->toBe(0);
});

test('TMDB merge respects deactivate failover channels option', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly(['name' => 'Master']);
    $playlist2 = Playlist::factory()->for($user)->createQuietly(['name' => 'Failover']);

    $vod1 = Channel::factory()->create([
        'title' => 'SC - Avatar (2009)',
        'stream_id' => 'a_1',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'is_vod' => true,
        'can_merge' => true,
        'enabled' => true,
        'tmdb_id' => 19995,
    ]);

    $vod2 = Channel::factory()->create([
        'title' => 'HD - Avatar (2009)',
        'stream_id' => 'b_2',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'is_vod' => true,
        'can_merge' => true,
        'enabled' => true,
        'tmdb_id' => 19995,
    ]);

    $playlists = collect([
        ['playlist_failover_id' => $playlist1->id],
        ['playlist_failover_id' => $playlist2->id],
    ]);

    MergeChannels::dispatchSync(
        $user,
        $playlists,
        $playlist1->id,
        deactivateFailoverChannels: true,
        mergeVodByTmdbId: true,
    );

    $vod2->refresh();
    expect($vod2->enabled)->toBeFalse();
});

test('it does not merge VOD channels without a TMDB ID', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly();
    $playlist2 = Playlist::factory()->for($user)->createQuietly();

    Channel::factory()->create([
        'title' => 'Some Movie',
        'stream_id' => 'a_1',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'is_vod' => true,
        'can_merge' => true,
        'tmdb_id' => null,
    ]);

    Channel::factory()->create([
        'title' => 'Some Movie',
        'stream_id' => 'b_2',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'is_vod' => true,
        'can_merge' => true,
        'tmdb_id' => null,
    ]);

    $playlists = collect([
        ['playlist_failover_id' => $playlist1->id],
        ['playlist_failover_id' => $playlist2->id],
    ]);

    MergeChannels::dispatchSync(
        $user,
        $playlists,
        $playlist1->id,
        mergeVodByTmdbId: true,
    );

    expect(ChannelFailover::where('user_id', $user->id)->count())->toBe(0);
});
