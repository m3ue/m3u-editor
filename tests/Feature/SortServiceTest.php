<?php

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Services\SortService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->group = Group::factory()->for($this->playlist)->for($this->user)->create(['type' => 'live']);
    $this->service = new SortService;
});

it('sorts channels by title ASC using the title column', function () {
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Zebra', 'sort' => 1]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Alpha', 'sort' => 2]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Mango', 'sort' => 3]);

    $this->service->bulkSortGroupChannels($this->group, 'ASC', 'title');

    expect($this->group->channels()->orderBy('sort')->pluck('title')->toArray())
        ->toBe(['Alpha', 'Mango', 'Zebra']);
});

it('sorts channels by title DESC', function () {
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Alpha', 'sort' => 1]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Zebra', 'sort' => 2]);

    $this->service->bulkSortGroupChannels($this->group, 'DESC', 'title');

    expect($this->group->channels()->orderBy('sort')->pluck('title')->toArray())
        ->toBe(['Zebra', 'Alpha']);
});

it('accepts the channel column which previously relied on the default fallthrough', function () {
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'B', 'channel' => 30, 'sort' => 1]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'A', 'channel' => 10, 'sort' => 2]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'C', 'channel' => 20, 'sort' => 3]);

    $this->service->bulkSortGroupChannels($this->group, 'ASC', 'channel');

    expect($this->group->channels()->orderBy('sort')->pluck('channel')->toArray())
        ->toBe([10, 20, 30]);
});

it('treats null column as title (backwards compatible)', function () {
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Zebra', 'sort' => 1]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Alpha', 'sort' => 2]);

    $this->service->bulkSortGroupChannels($this->group, 'ASC', null);

    expect($this->group->channels()->orderBy('sort')->pluck('title')->toArray())
        ->toBe(['Alpha', 'Zebra']);
});

it('rejects arbitrary columns to prevent SQL injection', function () {
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Alpha', 'sort' => 1]);

    $this->service->bulkSortGroupChannels(
        $this->group,
        'ASC',
        'title; DROP TABLE channels; --'
    );
})->throws(InvalidArgumentException::class, 'Invalid sort column provided.');

it('rejects an unknown but benign column', function () {
    $this->service->bulkSortGroupChannels($this->group, 'ASC', 'created_at');
})->throws(InvalidArgumentException::class);

it('ignores an invalid sort order and defaults to ASC', function () {
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Zebra', 'sort' => 1]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Alpha', 'sort' => 2]);

    // Anything that isn't 'DESC' (case-insensitive) becomes ASC — the direction
    // whitelist on line 18 of SortService was already safe, this test locks it in.
    $this->service->bulkSortGroupChannels($this->group, 'garbage', 'title');

    expect($this->group->channels()->orderBy('sort')->pluck('title')->toArray())
        ->toBe(['Alpha', 'Zebra']);
});

// ---------------------------------------------------------------------------
// bulkSortAlphaCustomPlaylistChannels — pivot-only sort
// ---------------------------------------------------------------------------

it('sorts custom playlist channels by title ASC via pivot sort', function () {
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
    $channelZ = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Zebra', 'sort' => 1]);
    $channelA = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Alpha', 'sort' => 2]);
    $channelM = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Mango', 'sort' => 3]);

    $customPlaylist->channels()->attach([$channelZ->id, $channelA->id, $channelM->id]);
    $channels = $customPlaylist->channels()->get();

    $this->service->bulkSortAlphaCustomPlaylistChannels($customPlaylist, $channels, 'ASC', 'title');

    $orderedTitles = DB::table('channel_custom_playlist')
        ->join('channels', 'channels.id', '=', 'channel_custom_playlist.channel_id')
        ->where('channel_custom_playlist.custom_playlist_id', $customPlaylist->id)
        ->orderBy('channel_custom_playlist.sort')
        ->pluck('channels.title')
        ->toArray();

    expect($orderedTitles)->toBe(['Alpha', 'Mango', 'Zebra']);
});

it('sorts custom playlist channels by title DESC via pivot sort', function () {
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
    $channelA = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Alpha', 'sort' => 1]);
    $channelZ = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Zebra', 'sort' => 2]);

    $customPlaylist->channels()->attach([$channelA->id, $channelZ->id]);
    $channels = $customPlaylist->channels()->get();

    $this->service->bulkSortAlphaCustomPlaylistChannels($customPlaylist, $channels, 'DESC', 'title');

    $orderedTitles = DB::table('channel_custom_playlist')
        ->join('channels', 'channels.id', '=', 'channel_custom_playlist.channel_id')
        ->where('channel_custom_playlist.custom_playlist_id', $customPlaylist->id)
        ->orderBy('channel_custom_playlist.sort')
        ->pluck('channels.title')
        ->toArray();

    expect($orderedTitles)->toBe(['Zebra', 'Alpha']);
});

it('does not modify global channels.sort when sorting a custom playlist', function () {
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
    $channelZ = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Zebra', 'sort' => 1]);
    $channelA = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Alpha', 'sort' => 2]);
    $channelM = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Mango', 'sort' => 3]);

    $customPlaylist->channels()->attach([$channelZ->id, $channelA->id, $channelM->id]);
    $channels = $customPlaylist->channels()->get();

    $this->service->bulkSortAlphaCustomPlaylistChannels($customPlaylist, $channels, 'ASC', 'title');

    // Global sort values must be unchanged
    expect((int) $channelZ->fresh()->sort)->toBe(1)
        ->and((int) $channelA->fresh()->sort)->toBe(2)
        ->and((int) $channelM->fresh()->sort)->toBe(3);
});

it('sorting one custom playlist does not affect another custom playlists pivot sort', function () {
    $playlistA = CustomPlaylist::factory()->for($this->user)->create();
    $playlistB = CustomPlaylist::factory()->for($this->user)->create();

    $channelZ = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Zebra', 'sort' => 1]);
    $channelA = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create(['title' => 'Alpha', 'sort' => 2]);

    $playlistA->channels()->attach([$channelZ->id, $channelA->id]);
    $playlistB->channels()->attach([$channelZ->id, $channelA->id]);

    // Set explicit pivot sort values for playlist B
    DB::table('channel_custom_playlist')
        ->where('custom_playlist_id', $playlistB->id)
        ->where('channel_id', $channelZ->id)
        ->update(['sort' => 5]);
    DB::table('channel_custom_playlist')
        ->where('custom_playlist_id', $playlistB->id)
        ->where('channel_id', $channelA->id)
        ->update(['sort' => 10]);

    // Sort playlist A
    $channels = $playlistA->channels()->get();
    $this->service->bulkSortAlphaCustomPlaylistChannels($playlistA, $channels, 'ASC', 'title');

    // Playlist B's pivot sort must be unchanged
    $pivotBSorts = DB::table('channel_custom_playlist')
        ->where('custom_playlist_id', $playlistB->id)
        ->pluck('sort', 'channel_id')
        ->toArray();

    expect($pivotBSorts[$channelZ->id])->toBe(5)
        ->and($pivotBSorts[$channelA->id])->toBe(10);
});

it('rejects invalid column for custom playlist sort', function () {
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create();
    $customPlaylist->channels()->attach($channel->id);
    $channels = $customPlaylist->channels()->get();

    $this->service->bulkSortAlphaCustomPlaylistChannels($customPlaylist, $channels, 'ASC', 'created_at');
})->throws(InvalidArgumentException::class, 'Invalid sort column provided.');
