<?php

use App\Jobs\MapPlaylistChannelsToEpg;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgMap;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();

    $this->user = User::factory()->create();
    $this->epg = Epg::factory()->for($this->user)->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
    $this->groupA = Group::factory()->for($this->playlist)->for($this->user)->create(['type' => 'live']);
    $this->groupB = Group::factory()->for($this->playlist)->for($this->user)->create(['type' => 'live']);

    $this->channelsInA = Channel::factory()
        ->for($this->playlist)->for($this->user)->for($this->groupA)
        ->count(2)->create();
    $this->channelsInB = Channel::factory()
        ->for($this->playlist)->for($this->user)->for($this->groupB)
        ->count(3)->create();
});

it('scopes a fresh dispatch to only the channels in the given group(s)', function () {
    (new MapPlaylistChannelsToEpg(
        epg: $this->epg->id,
        playlist: $this->playlist->id,
        groups: [$this->groupA->id],
    ))->handle();

    $map = EpgMap::where('epg_id', $this->epg->id)->firstOrFail();

    expect($map->group_ids)->toBe([$this->groupA->id])
        ->and($map->total_channel_count)->toBe(2);
});

it('persists and restores the group scope on a re-fire that only passes epgMapId', function () {
    $map = EpgMap::factory()->create([
        'epg_id' => $this->epg->id,
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->epg->user_id,
        'group_ids' => [$this->groupB->id],
        'processing' => false,
    ]);

    (new MapPlaylistChannelsToEpg(
        epg: $this->epg->id,
        playlist: $this->playlist->id,
        epgMapId: $map->id,
    ))->handle();

    $map->refresh();

    expect($map->total_channel_count)->toBe(3);
});

it('skips a re-fire when the map is already being processed, to avoid a racing double-run', function () {
    $map = EpgMap::factory()->create([
        'epg_id' => $this->epg->id,
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->epg->user_id,
        'group_ids' => [$this->groupB->id],
        'processing' => true,
        'total_channel_count' => 0,
    ]);

    (new MapPlaylistChannelsToEpg(
        epg: $this->epg->id,
        playlist: $this->playlist->id,
        epgMapId: $map->id,
    ))->handle();

    $map->refresh();

    expect($map->total_channel_count)->toBe(0)
        ->and($map->processing)->toBeTrue();
});

it('prioritizes explicit channels over group_ids when both are present', function () {
    $explicitChannel = $this->channelsInB->first();

    (new MapPlaylistChannelsToEpg(
        epg: $this->epg->id,
        playlist: $this->playlist->id,
        channels: [$explicitChannel->id],
        groups: [$this->groupA->id],
    ))->handle();

    $map = EpgMap::where('epg_id', $this->epg->id)->firstOrFail();

    expect($map->total_channel_count)->toBe(1);
});

it('maps the entire playlist when no group scope is set', function () {
    (new MapPlaylistChannelsToEpg(
        epg: $this->epg->id,
        playlist: $this->playlist->id,
    ))->handle();

    $map = EpgMap::where('epg_id', $this->epg->id)->firstOrFail();

    expect($map->group_ids)->toBeNull()
        ->and($map->total_channel_count)->toBe(5);
});
