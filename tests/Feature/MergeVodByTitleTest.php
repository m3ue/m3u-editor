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

test('it merges VOD channels by title similarity across providers', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly(['name' => 'Provider 1']);
    $playlist2 = Playlist::factory()->for($user)->createQuietly(['name' => 'Provider 2']);

    // Same movie from different providers with different stream_ids and title formats
    $vod1 = Channel::factory()->create([
        'title' => 'DK | The Last Viking',
        'name' => 'The Last Viking',
        'stream_id' => 'prov1_12345',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'is_vod' => true,
        'can_merge' => true,
        'enabled' => true,
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
    ]);

    // A different movie that should NOT be merged
    $differentVod = Channel::factory()->create([
        'title' => 'SC - The Matrix (1999)',
        'name' => 'The Matrix',
        'stream_id' => 'prov2_99999',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'is_vod' => true,
        'can_merge' => true,
        'enabled' => true,
    ]);

    $playlists = collect([
        ['playlist_failover_id' => $playlist1->id],
        ['playlist_failover_id' => $playlist2->id],
    ]);

    MergeChannels::dispatchSync(
        $user,
        $playlists,
        $playlist1->id,
        mergeByTitle: true,
        titleSimilarityThreshold: 80.0,
    );

    // The two "Last Viking" VODs should be merged with failover
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

test('it does not merge VOD by title when feature is disabled', function () {
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
    ]);

    Channel::factory()->create([
        'title' => 'SC - The Last Viking (2025)',
        'stream_id' => 'prov2_67890',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'is_vod' => true,
        'can_merge' => true,
    ]);

    $playlists = collect([
        ['playlist_failover_id' => $playlist1->id],
        ['playlist_failover_id' => $playlist2->id],
    ]);

    // mergeByTitle defaults to false
    MergeChannels::dispatchSync($user, $playlists, $playlist1->id);

    // No merges since stream_ids differ and title merge is off
    expect(ChannelFailover::where('user_id', $user->id)->count())->toBe(0);
});

test('title merge respects deactivate failover channels option', function () {
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
    ]);

    $vod2 = Channel::factory()->create([
        'title' => 'HD - Avatar (2009)',
        'stream_id' => 'b_2',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'is_vod' => true,
        'can_merge' => true,
        'enabled' => true,
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
        mergeByTitle: true,
        titleSimilarityThreshold: 80.0,
    );

    $vod2->refresh();
    expect($vod2->enabled)->toBeFalse();
});
