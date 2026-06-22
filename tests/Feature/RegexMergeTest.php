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

it('merges channels matching a playlist-level regex pattern as failovers', function () {
    $this->playlist->update([
        'auto_merge_config' => ['regex_patterns' => ['/^CCTV[-]?1$/i']],
    ]);

    $master = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'title' => 'CCTV-1',
        'name' => 'CCTV-1',
        'stream_id' => 'cctv1-primary',
        'can_merge' => true,
        'enabled' => true,
    ]);

    $match1 = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'title' => 'CCTV1',
        'name' => 'CCTV1',
        'stream_id' => 'cctv1-backup-a',
        'can_merge' => true,
        'enabled' => true,
    ]);

    $match2 = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'title' => 'CCTV-1',
        'name' => 'cctv-1',
        'stream_id' => 'cctv1-backup-b',
        'can_merge' => true,
        'enabled' => true,
    ]);

    // Should NOT match — CCTV10 is beyond the regex boundary
    $noMatch = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'title' => 'CCTV10',
        'name' => 'CCTV10',
        'stream_id' => 'cctv10',
        'can_merge' => true,
        'enabled' => true,
    ]);

    $playlists = collect([['playlist_failover_id' => $this->playlist->id]]);

    MergeChannels::dispatchSync(
        $this->user,
        $playlists,
        $this->playlist->id,
        forceCompleteRemerge: true,
        regexPatterns: $this->playlist->fresh()->auto_merge_config['regex_patterns'] ?? null,
    );

    // Exactly 2 failovers created for the group (the 3 matching channels minus the master)
    expect(ChannelFailover::count())->toBe(2);

    // CCTV10 must not appear in the failover table at all
    $this->assertDatabaseMissing('channel_failovers', [
        'channel_failover_id' => $noMatch->id,
    ]);
});

it('skips channels with invalid regex patterns without crashing', function () {
    $this->playlist->update([
        'auto_merge_config' => ['regex_patterns' => ['/[invalid']],
    ]);

    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => 'test1',
        'can_merge' => true,
        'enabled' => true,
    ]);

    $playlists = collect([['playlist_failover_id' => $this->playlist->id]]);

    MergeChannels::dispatchSync(
        $this->user,
        $playlists,
        $this->playlist->id,
        regexPatterns: $this->playlist->fresh()->auto_merge_config['regex_patterns'] ?? null,
    );

    expect(ChannelFailover::count())->toBe(0);
});

it('does nothing when no regex patterns are configured', function () {
    // No auto_merge_config set on playlist — channels have distinct stream_ids so
    // the standard merge pass also creates no failovers
    foreach (['stream-a', 'stream-b', 'stream-c'] as $id) {
        Channel::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_id' => $this->group->id,
            'stream_id' => $id,
            'can_merge' => true,
            'enabled' => true,
        ]);
    }

    $playlists = collect([['playlist_failover_id' => $this->playlist->id]]);

    MergeChannels::dispatchSync($this->user, $playlists, $this->playlist->id);

    expect(ChannelFailover::count())->toBe(0);
});

it('matches regex against channel name when title does not match', function () {
    $this->playlist->update([
        'auto_merge_config' => ['regex_patterns' => ['/^BBC\s*One$/i']],
    ]);

    $master = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'title' => 'BBC One HD',
        'name' => 'BBC One',
        'stream_id' => 'bbc-one-primary',
        'can_merge' => true,
        'enabled' => true,
    ]);

    $matchByName = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'title' => 'Something Else',
        'name' => 'BBC One',
        'stream_id' => 'bbc-one-other',
        'can_merge' => true,
        'enabled' => true,
    ]);

    $playlists = collect([['playlist_failover_id' => $this->playlist->id]]);

    MergeChannels::dispatchSync(
        $this->user,
        $playlists,
        $this->playlist->id,
        forceCompleteRemerge: true,
        regexPatterns: $this->playlist->fresh()->auto_merge_config['regex_patterns'] ?? null,
    );

    expect(ChannelFailover::count())->toBe(1);
});
