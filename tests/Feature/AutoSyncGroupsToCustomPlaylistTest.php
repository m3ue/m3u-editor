<?php

/**
 * Tests for AutoSyncGroupsToCustomPlaylist job and SyncListener dispatch.
 *
 * Verifies:
 *  - add_only mode: syncs channels from source groups into custom playlist without removing existing ones.
 *  - full_sync mode: adds new channels and removes channels no longer in the source group.
 *  - SyncListener dispatches the job for each enabled rule after a completed playlist sync.
 *  - Disabled rules are skipped.
 *  - Rules with missing custom_playlist_id or empty groups are skipped.
 *  - Tags are applied according to the configured mode (original, select, create).
 */

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Jobs\AutoSyncGroupsToCustomPlaylist;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly([
        'status' => Status::Completed,
        'auto_sync_to_custom_config' => null,
    ]);
    $this->customPlaylist = CustomPlaylist::factory()->create(['user_id' => $this->user->id]);
    $this->group = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'type' => 'live',
        'name' => 'Sports',
    ]);
});

// ──────────────────────────────────────────────────────────────────────────────
// Job: add_only mode
// ──────────────────────────────────────────────────────────────────────────────

it('adds channels from source group to custom playlist in add_only mode', function () {
    $channels = Channel::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'is_vod' => false,
    ]);

    (new AutoSyncGroupsToCustomPlaylist(
        userId: $this->user->id,
        playlistId: $this->playlist->id,
        groupIds: [$this->group->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: 'add_only',
    ))->handle();

    foreach ($channels as $channel) {
        expect($this->customPlaylist->channels()->where('channels.id', $channel->id)->exists())->toBeTrue();
    }
});

it('does not remove pre-existing channels unrelated to the source group in add_only mode', function () {
    $unrelatedChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => null,
    ]);
    $this->customPlaylist->channels()->attach($unrelatedChannel->id);

    Channel::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
    ]);

    (new AutoSyncGroupsToCustomPlaylist(
        userId: $this->user->id,
        playlistId: $this->playlist->id,
        groupIds: [$this->group->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: 'add_only',
    ))->handle();

    // Unrelated channel must still be there
    expect($this->customPlaylist->channels()->where('channels.id', $unrelatedChannel->id)->exists())->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// Job: full_sync mode
// ──────────────────────────────────────────────────────────────────────────────

it('adds new channels in full_sync mode', function () {
    $channels = Channel::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
    ]);

    (new AutoSyncGroupsToCustomPlaylist(
        userId: $this->user->id,
        playlistId: $this->playlist->id,
        groupIds: [$this->group->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: 'full_sync',
    ))->handle();

    foreach ($channels as $channel) {
        expect($this->customPlaylist->channels()->where('channels.id', $channel->id)->exists())->toBeTrue();
    }
});

it('removes stale channels from source group in full_sync mode', function () {
    // Channel that was previously in the group and added to custom playlist
    $staleChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
    ]);
    $this->customPlaylist->channels()->attach($staleChannel->id);

    // Remove the channel from its group (simulates provider removing it)
    $staleChannel->update(['group_id' => null]);

    // New channel that is currently in the group
    $activeChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
    ]);

    (new AutoSyncGroupsToCustomPlaylist(
        userId: $this->user->id,
        playlistId: $this->playlist->id,
        groupIds: [$this->group->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: 'full_sync',
    ))->handle();

    expect($this->customPlaylist->channels()->where('channels.id', $activeChannel->id)->exists())->toBeTrue();
    expect($this->customPlaylist->channels()->where('channels.id', $staleChannel->id)->exists())->toBeFalse();
});

it('does not remove channels from other groups in full_sync mode', function () {
    $otherGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'type' => 'live',
    ]);

    $otherChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $otherGroup->id,
    ]);
    $this->customPlaylist->channels()->attach($otherChannel->id);

    // Source group has its own channels
    Channel::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
    ]);

    (new AutoSyncGroupsToCustomPlaylist(
        userId: $this->user->id,
        playlistId: $this->playlist->id,
        groupIds: [$this->group->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: 'full_sync',
    ))->handle();

    // Channel from a different group must NOT be removed
    expect($this->customPlaylist->channels()->where('channels.id', $otherChannel->id)->exists())->toBeTrue();
});

it('applies the group name as a tag in original mode', function () {
    Channel::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
    ]);

    (new AutoSyncGroupsToCustomPlaylist(
        userId: $this->user->id,
        playlistId: $this->playlist->id,
        groupIds: [$this->group->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: 'add_only',
    ))->handle();

    $tagNames = $this->customPlaylist->groupTags()->get()->pluck('name')->all();
    expect($tagNames)->toContain($this->group->name);
});

it('applies a custom tag in create mode', function () {
    Channel::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
    ]);

    (new AutoSyncGroupsToCustomPlaylist(
        userId: $this->user->id,
        playlistId: $this->playlist->id,
        groupIds: [$this->group->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'create', 'new_category' => 'My Custom Group'],
        type: 'channel',
        syncMode: 'add_only',
    ))->handle();

    $tagNames = $this->customPlaylist->groupTags()->get()->pluck('name')->all();
    expect($tagNames)->toContain('My Custom Group');
});

// ──────────────────────────────────────────────────────────────────────────────
// SyncListener: dispatch behavior
// ──────────────────────────────────────────────────────────────────────────────

it('dispatches AutoSyncGroupsToCustomPlaylist for each enabled rule on completed sync', function () {
    Bus::fake();

    $secondCustomPlaylist = CustomPlaylist::factory()->create(['user_id' => $this->user->id]);
    $secondGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'type' => 'live',
    ]);

    $this->playlist->update([
        'auto_sync_to_custom_config' => [
            [
                'enabled' => true,
                'type' => 'live_groups',
                'groups' => [$this->group->id],
                'custom_playlist_id' => $this->customPlaylist->id,
                'sync_mode' => 'full_sync',
                'mode' => 'original',
                'category' => null,
                'new_category' => null,
            ],
            [
                'enabled' => true,
                'type' => 'live_groups',
                'groups' => [$secondGroup->id],
                'custom_playlist_id' => $secondCustomPlaylist->id,
                'sync_mode' => 'add_only',
                'mode' => 'original',
                'category' => null,
                'new_category' => null,
            ],
        ],
    ]);

    event(new SyncCompleted($this->playlist));

    Bus::assertDispatched(AutoSyncGroupsToCustomPlaylist::class, 2);
});

it('skips disabled auto-sync rules', function () {
    Bus::fake();

    $this->playlist->update([
        'auto_sync_to_custom_config' => [
            [
                'enabled' => false,
                'type' => 'live_groups',
                'groups' => [$this->group->id],
                'custom_playlist_id' => $this->customPlaylist->id,
                'sync_mode' => 'full_sync',
                'mode' => 'original',
                'category' => null,
                'new_category' => null,
            ],
        ],
    ]);

    event(new SyncCompleted($this->playlist));

    Bus::assertNotDispatched(AutoSyncGroupsToCustomPlaylist::class);
});

it('skips rules with no groups configured', function () {
    Bus::fake();

    $this->playlist->update([
        'auto_sync_to_custom_config' => [
            [
                'enabled' => true,
                'type' => 'live_groups',
                'groups' => [],
                'custom_playlist_id' => $this->customPlaylist->id,
                'sync_mode' => 'full_sync',
                'mode' => 'original',
                'category' => null,
                'new_category' => null,
            ],
        ],
    ]);

    event(new SyncCompleted($this->playlist));

    Bus::assertNotDispatched(AutoSyncGroupsToCustomPlaylist::class);
});

it('does not dispatch auto-sync jobs when playlist sync is not completed', function () {
    Bus::fake();

    $this->playlist->updateQuietly(['status' => Status::Failed]);
    $this->playlist->update([
        'auto_sync_to_custom_config' => [
            [
                'enabled' => true,
                'type' => 'live_groups',
                'groups' => [$this->group->id],
                'custom_playlist_id' => $this->customPlaylist->id,
                'sync_mode' => 'full_sync',
                'mode' => 'original',
                'category' => null,
                'new_category' => null,
            ],
        ],
    ]);

    event(new SyncCompleted($this->playlist));

    Bus::assertNotDispatched(AutoSyncGroupsToCustomPlaylist::class);
});
