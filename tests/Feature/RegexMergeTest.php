<?php

use App\Jobs\MergeChannels;
use App\Models\Channel;
use App\Models\ChannelFailover;
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

it('merges channels matching a regex pattern as failovers', function () {
    // Master channel with a regex pattern
    $master = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => 'CCTV1',
        'title' => 'CCTV-1',
        'name' => 'CCTV1',
        'can_merge' => true,
        'merge_regex' => '/^CCTV[-]?1$/i',
    ]);

    // Channels that should match the regex
    $match1 = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => 'different-id-1',
        'title' => 'CCTV1',
        'name' => 'cctv1',
        'can_merge' => true,
    ]);

    $match2 = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => 'different-id-2',
        'title' => 'CCTV-1',
        'name' => 'cctv-1',
        'can_merge' => true,
    ]);

    // Channel that should NOT match (CCTV10)
    $noMatch = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => 'different-id-3',
        'title' => 'CCTV10',
        'name' => 'CCTV10',
        'can_merge' => true,
    ]);

    $playlists = collect([['playlist_failover_id' => $this->playlist->id]]);

    MergeChannels::dispatchSync(
        $this->user,
        $playlists,
        $this->playlist->id,
        forceCompleteRemerge: true,
    );

    // Assert regex-matched channels are failovers of the master
    expect(ChannelFailover::where('channel_id', $master->id)->count())->toBe(2);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $master->id,
        'channel_failover_id' => $match1->id,
    ]);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $master->id,
        'channel_failover_id' => $match2->id,
    ]);

    // CCTV10 should NOT be a failover of the master
    $this->assertDatabaseMissing('channel_failovers', [
        'channel_id' => $master->id,
        'channel_failover_id' => $noMatch->id,
    ]);
});

it('skips channels with invalid regex patterns', function () {
    $master = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => 'TEST1',
        'title' => 'Test Channel',
        'name' => 'Test',
        'can_merge' => true,
        'merge_regex' => '/[invalid', // Invalid regex
    ]);

    $candidate = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => 'TEST2',
        'title' => 'Test Channel 2',
        'name' => 'Test2',
        'can_merge' => true,
    ]);

    $playlists = collect([['playlist_failover_id' => $this->playlist->id]]);

    MergeChannels::dispatchSync(
        $this->user,
        $playlists,
        $this->playlist->id,
        forceCompleteRemerge: true,
    );

    // No failovers should be created from the invalid regex
    expect(ChannelFailover::where('channel_id', $master->id)->count())->toBe(0);
});

it('matches regex against channel name when title does not match', function () {
    $master = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => 'BBC1',
        'title' => 'BBC One',
        'name' => 'BBC1',
        'can_merge' => true,
        'merge_regex' => '/^BBC\s*One$/i',
    ]);

    $matchByName = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => 'bbc-other',
        'title' => 'Something Else',
        'name' => 'BBC One',
        'can_merge' => true,
    ]);

    $playlists = collect([['playlist_failover_id' => $this->playlist->id]]);

    MergeChannels::dispatchSync(
        $this->user,
        $playlists,
        $this->playlist->id,
        forceCompleteRemerge: true,
    );

    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $master->id,
        'channel_failover_id' => $matchByName->id,
    ]);
});
