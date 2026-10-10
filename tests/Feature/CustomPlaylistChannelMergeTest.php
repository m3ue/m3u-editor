<?php

/**
 * Tests for auto-merge on custom playlists (issue #1103).
 *
 * Merge candidates are restricted to channels attached to the custom playlist
 * (optionally filtered to selected custom groups), so overlapping channels from
 * different source playlists can be merged only where they actually coexist in
 * the custom playlist.
 */

use App\Events\SyncCompleted;
use App\Jobs\MergeChannels;
use App\Jobs\RunCustomPlaylistProcessing;
use App\Listeners\SyncListener;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Spatie\Tags\Tag;

beforeEach(function () {
    Notification::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->playlistA = Playlist::factory()->createQuietly(['user_id' => $this->user->id]);
    $this->playlistB = Playlist::factory()->createQuietly(['user_id' => $this->user->id]);

    $this->groupA = Group::factory()->createQuietly([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlistA->id,
    ]);
    $this->groupB = Group::factory()->createQuietly([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlistB->id,
    ]);

    $this->customPlaylist = CustomPlaylist::factory()->create(['user_id' => $this->user->id]);
});

function makeMergeableChannel(Playlist $playlist, Group $group, string $streamId, float $sort = 1.0): Channel
{
    return Channel::factory()->create([
        'user_id' => $playlist->user_id,
        'playlist_id' => $playlist->id,
        'group_id' => $group->id,
        'stream_id' => $streamId,
        'sort' => $sort,
        'enabled' => true,
        'can_merge' => true,
        'is_vod' => false,
    ]);
}

it('merges only channels attached to the custom playlist', function () {
    // Same stream id in both playlists, attached to the custom playlist
    $inCpMaster = makeMergeableChannel($this->playlistA, $this->groupA, 'sport.1', 1.0);
    $inCpFailover = makeMergeableChannel($this->playlistB, $this->groupB, 'sport.1', 2.0);
    $this->customPlaylist->channels()->attach([$inCpMaster->id, $inCpFailover->id]);

    // Same stream id duplicated outside the custom playlist — must be untouched
    makeMergeableChannel($this->playlistA, $this->groupA, 'news.1', 1.0);
    makeMergeableChannel($this->playlistB, $this->groupB, 'news.1', 2.0);

    $this->customPlaylist->update([
        'auto_merge_channels_enabled' => true,
        'auto_merge_config' => [
            'failover_playlists' => [
                ['playlist_failover_id' => $this->playlistA->id],
                ['playlist_failover_id' => $this->playlistB->id],
            ],
        ],
    ]);

    SyncListener::getCustomPlaylistMergeJob($this->customPlaylist->refresh())->handle();

    $this->assertDatabaseCount('channel_failovers', 1);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $inCpMaster->id,
        'channel_failover_id' => $inCpFailover->id,
    ]);
});

it('restricts merging to the selected custom playlist groups', function () {
    $sportsTag = Tag::create(['name' => ['en' => 'Sports'], 'type' => $this->customPlaylist->uuid]);
    $newsTag = Tag::create(['name' => ['en' => 'News'], 'type' => $this->customPlaylist->uuid]);

    $sportsA = makeMergeableChannel($this->playlistA, $this->groupA, 'sport.1', 1.0);
    $sportsB = makeMergeableChannel($this->playlistB, $this->groupB, 'sport.1', 2.0);
    $newsA = makeMergeableChannel($this->playlistA, $this->groupA, 'news.1', 1.0);
    $newsB = makeMergeableChannel($this->playlistB, $this->groupB, 'news.1', 2.0);

    $sportsA->attachTag($sportsTag);
    $sportsB->attachTag($sportsTag);
    $newsA->attachTag($newsTag);
    $newsB->attachTag($newsTag);

    $this->customPlaylist->channels()->attach([$sportsA->id, $sportsB->id, $newsA->id, $newsB->id]);

    $this->customPlaylist->update([
        'auto_merge_channels_enabled' => true,
        'auto_merge_config' => [
            'groups' => ['Sports'],
            'failover_playlists' => [
                ['playlist_failover_id' => $this->playlistA->id],
                ['playlist_failover_id' => $this->playlistB->id],
            ],
        ],
    ]);

    SyncListener::getCustomPlaylistMergeJob($this->customPlaylist->refresh())->handle();

    $this->assertDatabaseCount('channel_failovers', 1);
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $sportsA->id,
        'channel_failover_id' => $sportsB->id,
    ]);
    expect(ChannelFailover::where('channel_id', $newsA->id)->exists())->toBeFalse();
});

it('selects the master channel from the configured playlist priority order', function () {
    $fromA = makeMergeableChannel($this->playlistA, $this->groupA, 'sport.1', 1.0);
    $fromB = makeMergeableChannel($this->playlistB, $this->groupB, 'sport.1', 2.0);
    $this->customPlaylist->channels()->attach([$fromA->id, $fromB->id]);

    // Playlist B first → its channel should win as master despite higher sort
    $this->customPlaylist->update([
        'auto_merge_channels_enabled' => true,
        'auto_merge_config' => [
            'failover_playlists' => [
                ['playlist_failover_id' => $this->playlistB->id],
                ['playlist_failover_id' => $this->playlistA->id],
            ],
        ],
    ]);

    SyncListener::getCustomPlaylistMergeJob($this->customPlaylist->refresh())->handle();

    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $fromB->id,
        'channel_failover_id' => $fromA->id,
    ]);
});

it('derives the source playlists from the custom playlist channels when not configured', function () {
    $fromA = makeMergeableChannel($this->playlistA, $this->groupA, 'sport.1', 1.0);
    $fromB = makeMergeableChannel($this->playlistB, $this->groupB, 'sport.1', 2.0);
    $this->customPlaylist->channels()->attach([$fromA->id, $fromB->id]);

    $this->customPlaylist->update(['auto_merge_channels_enabled' => true]);

    $job = SyncListener::getCustomPlaylistMergeJob($this->customPlaylist->refresh());

    expect($job)->not->toBeNull()
        ->and($job->customPlaylistId)->toBe($this->customPlaylist->id)
        ->and($job->playlists->pluck('playlist_failover_id')->sort()->values()->all())
        ->toEqual(collect([$this->playlistA->id, $this->playlistB->id])->sort()->values()->all());

    $job->handle();

    $this->assertDatabaseCount('channel_failovers', 1);
});

it('returns no merge job when auto-merge is disabled or the custom playlist is empty', function () {
    expect(SyncListener::getCustomPlaylistMergeJob($this->customPlaylist))->toBeNull();

    $this->customPlaylist->update(['auto_merge_channels_enabled' => true]);

    // Enabled but no channels and no configured playlists → still null
    expect(SyncListener::getCustomPlaylistMergeJob($this->customPlaylist->refresh()))->toBeNull();
});

it('chains the merge job before processing rules on custom playlist sync completion', function () {
    Bus::fake();

    $channel = makeMergeableChannel($this->playlistA, $this->groupA, 'sport.1');
    $this->customPlaylist->channels()->attach($channel->id);

    $this->customPlaylist->update([
        'auto_merge_channels_enabled' => true,
        'processing_config' => [
            ['enabled' => true, 'action' => 'sort_alpha', 'type' => 'all', 'groups' => ['all'], 'column' => 'title', 'sort' => 'ASC'],
        ],
    ]);

    (new SyncListener)->handle(new SyncCompleted($this->customPlaylist->refresh(), 'custom_playlist'));

    Bus::assertChained([
        MergeChannels::class,
        RunCustomPlaylistProcessing::class,
    ]);
});
