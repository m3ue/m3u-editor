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

    $this->enabledGroup = Group::factory()->createQuietly([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'enabled' => true,
    ]);

    $this->disabledGroup = Group::factory()->createQuietly([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'enabled' => false,
    ]);
});

it('does not select a channel from a disabled group as master when exclude_disabled_groups is enabled', function () {
    // The disabled-group channel has the better sort (= would normally win)
    $disabledMaster = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->disabledGroup->id,
        'stream_id' => 'shared-id',
        'name' => 'Disabled Group Channel',
        'sort' => 1.0,
        'enabled' => true,
        'can_merge' => true,
    ]);

    $enabledCandidate = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->enabledGroup->id,
        'stream_id' => 'shared-id',
        'name' => 'Enabled Group Channel',
        'sort' => 5.0,
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
            'exclude_disabled_groups' => true,
            'group_priorities' => [],
            'priority_attributes' => ['playlist_priority'],
        ],
    ))->handle();

    // The enabled-group channel must be master, the disabled-group channel its failover.
    $this->assertDatabaseCount('channel_failovers', 1);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $enabledCandidate->id,
        'channel_failover_id' => $disabledMaster->id,
    ]);
    $this->assertDatabaseMissing('channel_failovers', [
        'channel_id' => $disabledMaster->id,
    ]);
});

it('skips merge entirely when every candidate sits in a disabled group', function () {
    // Two channels share a stream id, but BOTH live in disabled groups.
    // With exclude_disabled_groups=true the job must not pick a master at all
    // (no failover row, no enable side-effect on either channel).
    $a = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->disabledGroup->id,
        'stream_id' => 'orphan-id',
        'name' => 'Disabled A',
        'sort' => 1.0,
        'enabled' => false,
        'can_merge' => true,
    ]);

    $b = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->disabledGroup->id,
        'stream_id' => 'orphan-id',
        'name' => 'Disabled B',
        'sort' => 2.0,
        'enabled' => false,
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
            'exclude_disabled_groups' => true,
            'group_priorities' => [],
            'priority_attributes' => ['playlist_priority'],
        ],
    ))->handle();

    $this->assertDatabaseCount('channel_failovers', 0);
    expect($a->fresh()->enabled)->toBeFalse();
    expect($b->fresh()->enabled)->toBeFalse();
});

it('does not silently re-enable a disabled master that lives in a disabled group', function () {
    // One enabled candidate (will become master), one disabled candidate in a
    // disabled group. The enabled candidate must NOT be touched, and the
    // disabled-group failover must NOT be auto-enabled.
    $disabledFailover = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->disabledGroup->id,
        'stream_id' => 'mix-id',
        'name' => 'Disabled Group Failover',
        'sort' => 1.0,
        'enabled' => false,
        'can_merge' => true,
    ]);

    $enabledMaster = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->enabledGroup->id,
        'stream_id' => 'mix-id',
        'name' => 'Enabled Master',
        'sort' => 5.0,
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
            'exclude_disabled_groups' => true,
            'group_priorities' => [],
            'priority_attributes' => ['playlist_priority'],
        ],
    ))->handle();

    // The disabled-group channel stays disabled; it became a failover, not master.
    expect($disabledFailover->fresh()->enabled)->toBeFalse();
    expect($enabledMaster->fresh()->enabled)->toBeTrue();
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $enabledMaster->id,
        'channel_failover_id' => $disabledFailover->id,
    ]);
});
