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
use Illuminate\Support\Facades\Event;
use Spatie\Tags\Tag;

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
    // Channel that was previously in the group, synced into the custom
    // playlist by this exact rule while still a member of it.
    $staleChannel = Channel::factory()->create([
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

it('removes a rule-managed channel once it moves to a different, active, unselected group (issue #1695)', function () {
    $otherGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'type' => 'live',
        'name' => 'News',
    ]);

    // This rule is the playlist's only one into the custom playlist, so every tracked row is its own.
    $this->playlist->updateQuietly(['auto_sync_to_custom_config' => [[
        'enabled' => true,
        'type' => 'live_groups',
        'groups' => [$this->group->id],
        'custom_playlist_id' => $this->customPlaylist->id,
        'sync_mode' => 'full_sync',
    ]]]);

    // Synced into the custom playlist by this rule while still in the selected group.
    $movedChannel = Channel::factory()->create([
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

    expect($this->customPlaylist->channels()->where('channels.id', $movedChannel->id)->exists())->toBeTrue();

    // The channel moves to another group that is itself active, just not one this rule selects.
    $movedChannel->update(['group_id' => $otherGroup->id]);

    (new AutoSyncGroupsToCustomPlaylist(
        userId: $this->user->id,
        playlistId: $this->playlist->id,
        groupIds: [$this->group->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: 'full_sync',
    ))->handle();

    expect($this->customPlaylist->channels()->where('channels.id', $movedChannel->id)->exists())->toBeFalse();
});

it('does not detach a manually attached groupless custom channel the rule never managed (issue #1695)', function () {
    // A manually created custom channel on the same source playlist, never
    // assigned to any group, manually added to the custom playlist - the
    // rule below (selecting $this->group) never touched it.
    $manualChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => null,
        'is_custom' => true,
    ]);
    $this->customPlaylist->channels()->attach($manualChannel->id);

    // Currently-active channel from the selected group, so the rule has
    // something to actually sync.
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
    expect($this->customPlaylist->channels()->where('channels.id', $manualChannel->id)->exists())->toBeTrue();
});

/**
 * Run one rule's job the way a sync does, with the rules stored on the source playlist.
 *
 * @param  array<int>  $groupIds
 */
function runAutoSyncRule($test, array $groupIds, array $channelNamePatterns = []): void
{
    (new AutoSyncGroupsToCustomPlaylist(
        userId: $test->user->id,
        playlistId: $test->playlist->id,
        groupIds: $groupIds,
        customPlaylistId: $test->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: 'full_sync',
        channelNamePatterns: $channelNamePatterns,
    ))->handle();
}

it('keeps the channels a sibling rule added to the same custom playlist', function () {
    $news = Group::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'type' => 'live', 'name' => 'News']);
    $sportsChannel = Channel::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'group_id' => $this->group->id]);
    $newsChannel = Channel::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'group_id' => $news->id]);
    $rule = fn (array $groups, array $extra = []) => array_merge([
        'enabled' => true,
        'type' => 'live_groups',
        'groups' => $groups,
        'custom_playlist_id' => $this->customPlaylist->id,
        'sync_mode' => 'full_sync',
    ], $extra);
    $this->playlist->updateQuietly(['auto_sync_to_custom_config' => [$rule([$this->group->id]), $rule([$news->id])]]);

    // One pipeline run chains the rules in config order, run again to cover both orders
    runAutoSyncRule($this, [$this->group->id]);
    runAutoSyncRule($this, [$news->id]);
    runAutoSyncRule($this, [$this->group->id]);

    expect($this->customPlaylist->channels()->pluck('channels.id')->all())->toContain($sportsChannel->id, $newsChannel->id);

    // A filtered rule drops its own unmatched channel but never the sibling's
    $this->playlist->updateQuietly(['auto_sync_to_custom_config' => [
        $rule([$this->group->id]),
        $rule([$news->id], ['channel_name_patterns' => ['^Never$']]),
    ]]);
    runAutoSyncRule($this, [$news->id], ['^Never$']);

    $synced = $this->customPlaylist->channels()->pluck('channels.id')->all();
    expect($synced)->toContain($sportsChannel->id)
        ->and($synced)->not->toContain($newsChannel->id);
});

it('keeps what a "New Groups Only" rule added in earlier runs', function () {
    $earlier = Channel::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'group_id' => $this->group->id]);
    $newGroup = Group::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'type' => 'live', 'name' => 'Brand New']);
    $added = Channel::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'group_id' => $newGroup->id]);
    $this->playlist->updateQuietly(['auto_sync_to_custom_config' => [[
        'enabled' => true,
        'type' => 'live_groups',
        'group_filter' => 'new_only',
        'groups' => [],
        'custom_playlist_id' => $this->customPlaylist->id,
        'sync_mode' => 'full_sync',
    ]]]);

    // Earlier run: $this->group was new then. This run: only $newGroup is new.
    runAutoSyncRule($this, [$this->group->id]);
    runAutoSyncRule($this, [$newGroup->id]);

    expect($this->customPlaylist->channels()->pluck('channels.id')->all())->toContain($earlier->id, $added->id);
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

it('removes channels from a soft-deleted source group in full_sync mode', function () {
    // Channels synced into the custom playlist by this rule while the group was still active.
    $channels = Channel::factory()->count(2)->create([
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

    // Soft-delete the group (simulates provider removing the whole group on re-sync)
    $groupId = $this->group->id;
    $this->group->delete();

    (new AutoSyncGroupsToCustomPlaylist(
        userId: $this->user->id,
        playlistId: $this->playlist->id,
        groupIds: [$groupId],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: 'full_sync',
    ))->handle();

    // All channels from the deleted group must be removed — none should linger as Uncategorized
    foreach ($channels as $channel) {
        expect($this->customPlaylist->channels()->where('channels.id', $channel->id)->exists())->toBeFalse();
    }
});

it('detaches the group tag when channels were already hard-deleted before the sync job runs', function () {
    // This covers the normal M3U re-sync path: ProcessM3uImportComplete hard-deletes removed
    // channels (cascading the pivot row via FK) before AutoSyncGroupsToCustomPlaylist runs.
    // The pivot is already clean, but the group tag on the custom playlist becomes a ghost.
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
    ]);

    // First sync: attaches the "Sports" tag to the custom playlist and to the channel
    (new AutoSyncGroupsToCustomPlaylist(
        userId: $this->user->id,
        playlistId: $this->playlist->id,
        groupIds: [$this->group->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: 'full_sync',
    ))->handle();

    expect($this->customPlaylist->fresh()->groupTags()->pluck('name')->contains('Sports'))->toBeTrue();

    // Simulate what ProcessM3uImportComplete does: hard-delete the channel.
    // The channel_custom_playlist row is cascade-deleted by the DB FK constraint.
    $groupId = $this->group->id;
    $channel->forceDelete();

    // The ghost "Sports" tag is still attached to the custom playlist at this point.
    expect($this->customPlaylist->fresh()->groupTags()->pluck('name')->contains('Sports'))->toBeTrue();

    // Re-run the job (group is still "active" in DB but has no channels) —
    // the tag cleanup should detach the ghost tag.
    (new AutoSyncGroupsToCustomPlaylist(
        userId: $this->user->id,
        playlistId: $this->playlist->id,
        groupIds: [$groupId],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: 'full_sync',
    ))->handle();

    expect($this->customPlaylist->fresh()->groupTags()->pluck('name')->contains('Sports'))->toBeFalse();
    expect($this->customPlaylist->channels()->count())->toBe(0);
});

it('detaches the group tag from the custom playlist when all its channels are removed in full_sync mode', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
    ]);

    // Run the first sync so the "Sports" tag is created and attached
    (new AutoSyncGroupsToCustomPlaylist(
        userId: $this->user->id,
        playlistId: $this->playlist->id,
        groupIds: [$this->group->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: 'full_sync',
    ))->handle();

    expect($this->customPlaylist->fresh()->groupTags()->pluck('name')->contains($this->group->name))->toBeTrue();

    // Soft-delete the group and re-run full_sync — the tag should be cleaned up
    $groupId = $this->group->id;
    $this->group->delete();

    (new AutoSyncGroupsToCustomPlaylist(
        userId: $this->user->id,
        playlistId: $this->playlist->id,
        groupIds: [$groupId],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: 'full_sync',
    ))->handle();

    expect($this->customPlaylist->fresh()->groupTags()->pluck('name')->contains($this->group->name))->toBeFalse();
});

it('does not detach a group tag when another source playlist still has channels with that tag', function () {
    // Second source playlist with its own "Sports" group — same name, different playlist
    $secondPlaylist = Playlist::factory()->for($this->user)->createQuietly([
        'status' => Status::Completed,
    ]);
    $secondGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $secondPlaylist->id,
        'type' => 'live',
        'name' => 'Sports', // same group name
    ]);
    $secondChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $secondPlaylist->id,
        'group_id' => $secondGroup->id,
    ]);

    // Sync both playlists' channels into the same custom playlist
    (new AutoSyncGroupsToCustomPlaylist(
        userId: $this->user->id,
        playlistId: $this->playlist->id,
        groupIds: [$this->group->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: 'full_sync',
    ))->handle();

    (new AutoSyncGroupsToCustomPlaylist(
        userId: $this->user->id,
        playlistId: $secondPlaylist->id,
        groupIds: [$secondGroup->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: 'full_sync',
    ))->handle();

    expect($this->customPlaylist->fresh()->groupTags()->pluck('name')->contains('Sports'))->toBeTrue();

    // Soft-delete the first playlist's "Sports" group and re-run its rule
    $firstGroupId = $this->group->id;
    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
    ]);
    $this->group->delete();

    (new AutoSyncGroupsToCustomPlaylist(
        userId: $this->user->id,
        playlistId: $this->playlist->id,
        groupIds: [$firstGroupId],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: 'full_sync',
    ))->handle();

    // The "Sports" tag must still be attached — second playlist's channel still uses it
    expect($this->customPlaylist->fresh()->groupTags()->pluck('name')->contains('Sports'))->toBeTrue();
    expect($this->customPlaylist->channels()->where('channels.id', $secondChannel->id)->exists())->toBeTrue();
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

it('does not skip a group literally named "0" in original mode', function () {
    $zeroGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'type' => 'live',
        'name' => '0',
    ]);

    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $zeroGroup->id,
    ]);

    (new AutoSyncGroupsToCustomPlaylist(
        userId: $this->user->id,
        playlistId: $this->playlist->id,
        groupIds: [$zeroGroup->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: 'add_only',
    ))->handle();

    $channel->refresh();

    expect($this->customPlaylist->channels()->where('channels.id', $channel->id)->exists())->toBeTrue()
        ->and($channel->tags->pluck('name')->all())->toContain('0');
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

it('dispatches SyncCompleted when processing rules are enabled on the custom playlist', function () {
    Event::fake([SyncCompleted::class]);

    $this->customPlaylist->update([
        'processing_config' => [[
            'enabled' => true,
            'action' => 'sort_alpha',
            'type' => 'all',
            'column' => 'title',
            'sort' => 'ASC',
        ]],
    ]);

    Channel::factory()->create([
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

    Event::assertDispatched(
        SyncCompleted::class,
        fn (SyncCompleted $event): bool => $event->model->is($this->customPlaylist)
            && $event->source === 'custom_playlist'
    );
});

it('does not dispatch SyncCompleted when processing rules are disabled on the custom playlist', function () {
    Event::fake([SyncCompleted::class]);

    $this->customPlaylist->update([
        'processing_config' => [[
            'enabled' => false,
            'action' => 'sort_alpha',
            'type' => 'all',
            'column' => 'title',
            'sort' => 'ASC',
        ]],
    ]);

    Channel::factory()->create([
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

    Event::assertNotDispatched(SyncCompleted::class);
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

it('prunes soft-deleted group IDs from source playlist config after full_sync', function () {
    // Simulate what ProcessM3uImportComplete does: soft-delete the group without pruning
    $this->group->delete();
    $this->playlist->updateQuietly([
        'auto_sync_to_custom_config' => [[
            'enabled' => true,
            'type' => 'live_groups',
            'groups' => [$this->group->id],
            'custom_playlist_id' => $this->customPlaylist->id,
            'sync_mode' => 'full_sync',
            'mode' => 'original',
        ]],
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

    $config = $this->playlist->fresh()->auto_sync_to_custom_config;
    expect($config[0]['groups'])->toBe([]);
});

it('detaches ghost tag and prunes config when the only group in a rule is soft-deleted', function () {
    // Attach a tag to the custom playlist simulating a prior sync
    $tagType = $this->customPlaylist->uuid;
    $tag = Tag::findOrCreate($this->group->name, $tagType);
    $this->customPlaylist->attachTag($tag);

    // Soft-delete the group (as ProcessM3uImportComplete would do)
    $this->group->delete();
    $this->playlist->updateQuietly([
        'auto_sync_to_custom_config' => [[
            'enabled' => true,
            'type' => 'live_groups',
            'groups' => [$this->group->id],
            'custom_playlist_id' => $this->customPlaylist->id,
            'sync_mode' => 'full_sync',
            'mode' => 'original',
        ]],
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

    // Ghost tag detached
    expect($this->customPlaylist->fresh()->tagsWithType($tagType)->pluck('id'))->not->toContain($tag->id);
    // Config pruned
    $config = $this->playlist->fresh()->auto_sync_to_custom_config;
    expect($config[0]['groups'])->toBe([]);
});

// ──────────────────────────────────────────────────────────────────────────────
// Job: channel filters (picked IDs and name patterns)
// ──────────────────────────────────────────────────────────────────────────────

function makeFilterChannel(string $title, $test): Channel
{
    return Channel::factory()->create([
        'user_id' => $test->user->id,
        'playlist_id' => $test->playlist->id,
        'group_id' => $test->group->id,
        'is_vod' => false,
        'title' => $title,
        'title_custom' => null,
    ]);
}

function runFilteredAutoSync($test, array $channelIds = [], array $patterns = [], string $syncMode = 'full_sync'): void
{
    (new AutoSyncGroupsToCustomPlaylist(
        userId: $test->user->id,
        playlistId: $test->playlist->id,
        groupIds: [$test->group->id],
        customPlaylistId: $test->customPlaylist->id,
        data: ['mode' => 'original'],
        type: 'channel',
        syncMode: $syncMode,
        channelIds: $channelIds,
        channelNamePatterns: $patterns,
    ))->handle();
}

it('only syncs picked channels and pattern matches out of the selected group', function () {
    $picked = makeFilterChannel('Local News', $this);
    $matched = makeFilterChannel('ESPN HD', $this);
    $skipped = makeFilterChannel('Cartoon Hour', $this);

    runFilteredAutoSync($this, [$picked->id], ['^ESPN']);

    $synced = $this->customPlaylist->channels()->pluck('channels.id')->all();

    expect($synced)->toContain($picked->id, $matched->id)
        ->and($synced)->not->toContain($skipped->id);
});

it('removes previously synced channels that no longer match the filter in full_sync mode', function () {
    $keep = makeFilterChannel('ESPN HD', $this);
    $drop = makeFilterChannel('Cartoon Hour', $this);

    runFilteredAutoSync($this);
    expect($this->customPlaylist->channels()->count())->toBe(2);

    runFilteredAutoSync($this, [], ['^ESPN']);

    $synced = $this->customPlaylist->channels()->pluck('channels.id')->all();
    expect($synced)->toBe([$keep->id])
        ->and($synced)->not->toContain($drop->id);
});

it('keeps manually attached channels when filtered full_sync cleans up', function () {
    $manual = makeFilterChannel('Manual Pick', $this);
    $this->customPlaylist->channels()->attach($manual->id);
    makeFilterChannel('ESPN HD', $this);

    runFilteredAutoSync($this, [], ['^ESPN']);

    expect($this->customPlaylist->channels()->where('channels.id', $manual->id)->exists())->toBeTrue();
});

it('passes channel filters from the rule to the job', function () {
    Bus::fake();
    $this->playlist->update(['auto_sync_to_custom_config' => [[
        'enabled' => true,
        'type' => 'live_groups',
        'group_filter' => 'selected',
        'groups' => [$this->group->id],
        'custom_playlist_id' => $this->customPlaylist->id,
        'channels' => ['5', '7'],
        'channel_name_patterns' => ['^ESPN'],
    ]]]);

    event(new SyncCompleted($this->playlist, 'playlist'));

    Bus::assertDispatched(AutoSyncGroupsToCustomPlaylist::class, fn ($job): bool => $job->channelIds === [5, 7]
        && $job->channelNamePatterns === ['^ESPN']);
});
