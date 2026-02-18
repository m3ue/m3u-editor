<?php

use App\Jobs\MergeChannels;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->playlist = Playlist::factory()->createQuietly([
        'user_id' => $this->user->id,
    ]);

    $this->group = Group::factory()->createQuietly([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);
});

it('selects the channel with the lowest sort order as master', function () {
    // Create three channels with the same stream_id but different sort orders
    // Deliberately assign higher ID to the channel with lowest sort order
    $channelC = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => '12345',
        'name' => 'Das Erste HDraw Cable',
        'sort' => 3.0,
        'enabled' => true,
        'can_merge' => true,
    ]);

    $channelA = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => '12345',
        'name' => 'Das Erste HDraw',
        'sort' => 1.0,
        'enabled' => true,
        'can_merge' => true,
    ]);

    $channelB = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => '12345',
        'name' => 'Das Erste HDraw²',
        'sort' => 2.0,
        'enabled' => true,
        'can_merge' => true,
    ]);

    // channelA has the highest auto-increment ID but lowest sort order
    expect($channelA->id)->toBeGreaterThan($channelC->id);

    $playlists = collect([['playlist_failover_id' => $this->playlist->id]]);
    (new MergeChannels(
        user: $this->user,
        playlists: $playlists,
        playlistId: $this->playlist->id,
    ))->handle();

    // channelA (sort=1.0) should be master, not channelC (lowest ID)
    $this->assertDatabaseCount('channel_failovers', 2);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $channelA->id,
        'channel_failover_id' => $channelC->id,
    ]);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $channelA->id,
        'channel_failover_id' => $channelB->id,
    ]);
});

it('selects master by sort order with weighted scoring', function () {
    // When all channels score equally, sort order should break the tie
    $channelHigh = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => '99999',
        'name' => 'ZDF HDraw Cable',
        'sort' => 30.0,
        'enabled' => true,
        'can_merge' => true,
    ]);

    $channelLow = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => '99999',
        'name' => 'ZDF HDraw',
        'sort' => 10.0,
        'enabled' => true,
        'can_merge' => true,
    ]);

    $playlists = collect([['playlist_failover_id' => $this->playlist->id]]);
    (new MergeChannels(
        user: $this->user,
        playlists: $playlists,
        playlistId: $this->playlist->id,
        weightedConfig: [
            'priority_keywords' => [],
            'prefer_codec' => null,
            'exclude_disabled_groups' => false,
            'group_priorities' => [],
            'priority_attributes' => ['playlist_priority'],
        ],
    ))->handle();

    // channelLow (sort=10.0) should be master
    $this->assertDatabaseCount('channel_failovers', 1);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $channelLow->id,
        'channel_failover_id' => $channelHigh->id,
    ]);
});

it('sorts failover channels by sort order', function () {
    $channel1 = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => '55555',
        'name' => 'RTL HDraw',
        'sort' => 1.0,
        'enabled' => true,
        'can_merge' => true,
    ]);

    $channel3 = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => '55555',
        'name' => 'RTL HDraw Cable',
        'sort' => 3.0,
        'enabled' => true,
        'can_merge' => true,
    ]);

    $channel2 = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => '55555',
        'name' => 'RTL HDraw²',
        'sort' => 2.0,
        'enabled' => true,
        'can_merge' => true,
    ]);

    $playlists = collect([['playlist_failover_id' => $this->playlist->id]]);
    (new MergeChannels(
        user: $this->user,
        playlists: $playlists,
        playlistId: $this->playlist->id,
    ))->handle();

    // channel1 (sort=1.0) should be master
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $channel1->id,
        'channel_failover_id' => $channel2->id,
    ]);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $channel1->id,
        'channel_failover_id' => $channel3->id,
    ]);

    // Failovers should be in insertion order: channel2 (sort=2.0) before channel3 (sort=3.0)
    // MergeChannels inserts failovers in sorted order, so we check by insertion (id) order
    $failovers = \App\Models\ChannelFailover::where('channel_id', $channel1->id)
        ->orderBy('id')
        ->pluck('channel_failover_id')
        ->toArray();

    expect($failovers[0])->toBe($channel2->id);
    expect($failovers[1])->toBe($channel3->id);
});
