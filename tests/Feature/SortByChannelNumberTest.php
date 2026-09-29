<?php

/**
 * Tests for the optional "Sort by channel number" playlist output ordering.
 *
 * Verifies that:
 * - Default ordering (group, sort, channel, title) is unchanged when the option is off
 * - Enabling the option orders channels flat by channel number across groups
 * - Channels without a number (NULL or 0) are output last, in standard group order
 * - Custom playlists sort by the pivot channel_number, falling back to the channel's own
 * - Aliases inherit the option from their effective playlist
 * - Xtream get_live_streams follows the same ordering
 */

use App\Http\Controllers\PlaylistGenerateController;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->groupA = Group::factory()->for($this->playlist)->for($this->user)->create(['sort_order' => 1]);
    $this->groupB = Group::factory()->for($this->playlist)->for($this->user)->create(['sort_order' => 2]);

    $this->makeChannel = fn (Group $group, ?int $number, int $sort, string $title) => Channel::factory()
        ->for($this->user)
        ->for($this->playlist)
        ->for($group)
        ->create([
            'enabled' => true,
            'is_vod' => false,
            'channel' => $number,
            'sort' => $sort,
            'title' => $title,
        ]);

    // Group A comes first by group order, but holds the higher channel numbers.
    ($this->makeChannel)($this->groupA, 30, 1, 'A30');
    ($this->makeChannel)($this->groupA, null, 2, 'A-none');
    ($this->makeChannel)($this->groupA, 10, 3, 'A10');
    ($this->makeChannel)($this->groupB, 0, 1, 'B-zero');
    ($this->makeChannel)($this->groupB, 20, 2, 'B20');
    ($this->makeChannel)($this->groupB, 5, 3, 'B5');
});

it('keeps the standard group ordering when sort by channel number is off', function () {
    $titles = PlaylistGenerateController::getChannelQuery($this->playlist)->pluck('title')->all();

    expect($titles)->toBe(['A30', 'A-none', 'A10', 'B-zero', 'B20', 'B5']);
});

it('orders channels flat by channel number with unnumbered channels last', function () {
    $this->playlist->update(['sort_by_channel_number' => true]);

    $titles = PlaylistGenerateController::getChannelQuery($this->playlist)->pluck('title')->all();

    expect($titles)->toBe(['B5', 'A10', 'B20', 'A30', 'A-none', 'B-zero']);
});

it('orders custom playlist channels by pivot channel number, falling back to the channel number', function () {
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create(['sort_by_channel_number' => true]);
    $channels = Channel::query()->pluck('id', 'title');

    $customPlaylist->channels()->attach([
        $channels['A30'] => ['sort' => 1, 'channel_number' => 1],
        $channels['A10'] => ['sort' => 2, 'channel_number' => 0],
        $channels['B20'] => ['sort' => 3, 'channel_number' => 50],
        $channels['A-none'] => ['sort' => 4, 'channel_number' => null],
    ]);

    $titles = PlaylistGenerateController::getChannelQuery($customPlaylist)->pluck('title')->all();

    expect($titles)->toBe(['A30', 'A10', 'B20', 'A-none']);
});

it('inherits sort by channel number on an alias from its source playlist', function () {
    $this->playlist->update(['sort_by_channel_number' => true]);
    $alias = PlaylistAlias::create([
        'name' => 'Alias',
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    expect($alias->sort_by_channel_number)->toBeTrue();

    $titles = PlaylistGenerateController::getChannelQuery($alias)->pluck('title')->all();

    expect($titles)->toBe(['B5', 'A10', 'B20', 'A30', 'A-none', 'B-zero']);
});

it('orders Xtream get_live_streams by channel number when enabled', function () {
    $this->playlist->update(['sort_by_channel_number' => true]);

    $response = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->playlist->uuid).'&action=get_live_streams');

    $response->assertStatus(200);

    expect(array_column($response->json(), 'name'))->toBe(['B5', 'A10', 'B20', 'A30', 'A-none', 'B-zero']);
});
