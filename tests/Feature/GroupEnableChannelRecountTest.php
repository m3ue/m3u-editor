<?php

use App\Facades\SortFacade;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->createQuietly(['user_id' => $this->user->id]);
});

it('recounts channels with offset when enabling a group after another', function () {
    $group1 = Group::factory()->for($this->playlist)->for($this->user)->create(['name' => 'Group A']);
    $group2 = Group::factory()->for($this->playlist)->for($this->user)->create(['name' => 'Group B']);

    // Group A channels: enabled, numbered 1-3
    foreach (range(1, 3) as $i) {
        Channel::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_id' => $group1->id,
            'enabled' => true,
            'channel' => $i,
            'sort' => $i,
        ]);
    }

    // Group B channels: disabled, numbered 1-2 (would duplicate Group A)
    foreach (range(1, 2) as $i) {
        Channel::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_id' => $group2->id,
            'enabled' => false,
            'channel' => $i,
            'sort' => $i,
        ]);
    }

    // Simulate what the enable action now does
    $group2->channels()->update(['enabled' => true]);

    $maxChannel = Channel::query()
        ->where('playlist_id', $group2->playlist_id)
        ->where('group_id', '!=', $group2->id)
        ->where('enabled', true)
        ->max('channel') ?? 0;

    SortFacade::bulkRecountGroupChannels($group2, $maxChannel + 1);

    // Group B channels should now be numbered 4-5 (after Group A's max of 3)
    $group2Channels = Channel::query()
        ->where('group_id', $group2->id)
        ->orderBy('sort')
        ->pluck('channel')
        ->all();

    expect($group2Channels)->toBe([4, 5]);

    // Group A channels should remain unchanged at 1-3
    $group1Channels = Channel::query()
        ->where('group_id', $group1->id)
        ->orderBy('sort')
        ->pluck('channel')
        ->all();

    expect($group1Channels)->toBe([1, 2, 3]);
});

it('recounts channels sequentially when bulk enabling multiple groups', function () {
    $group1 = Group::factory()->for($this->playlist)->for($this->user)->create(['name' => 'Group A']);
    $group2 = Group::factory()->for($this->playlist)->for($this->user)->create(['name' => 'Group B']);
    $group3 = Group::factory()->for($this->playlist)->for($this->user)->create(['name' => 'Group C']);

    // Group A: enabled, channels 1-2
    foreach (range(1, 2) as $i) {
        Channel::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_id' => $group1->id,
            'enabled' => true,
            'channel' => $i,
            'sort' => $i,
        ]);
    }

    // Group B: disabled, channels 1-2
    foreach (range(1, 2) as $i) {
        Channel::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_id' => $group2->id,
            'enabled' => false,
            'channel' => $i,
            'sort' => $i,
        ]);
    }

    // Group C: disabled, channels 1-3
    foreach (range(1, 3) as $i) {
        Channel::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_id' => $group3->id,
            'enabled' => false,
            'channel' => $i,
            'sort' => $i,
        ]);
    }

    // Simulate bulk enable: loop through groups B and C
    foreach ([$group2, $group3] as $record) {
        $record->channels()->update(['enabled' => true]);

        $maxChannel = Channel::query()
            ->where('playlist_id', $record->playlist_id)
            ->where('group_id', '!=', $record->id)
            ->where('enabled', true)
            ->max('channel') ?? 0;

        SortFacade::bulkRecountGroupChannels($record, $maxChannel + 1);
    }

    // Group B: should be numbered 3-4 (after Group A's max of 2)
    $group2Channels = Channel::query()
        ->where('group_id', $group2->id)
        ->orderBy('sort')
        ->pluck('channel')
        ->all();

    expect($group2Channels)->toBe([3, 4]);

    // Group C: should be numbered 5-7 (after Group B's max of 4)
    $group3Channels = Channel::query()
        ->where('group_id', $group3->id)
        ->orderBy('sort')
        ->pluck('channel')
        ->all();

    expect($group3Channels)->toBe([5, 6, 7]);
});

it('starts at 1 when no other enabled channels exist in the playlist', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create(['name' => 'Group A']);

    foreach (range(1, 3) as $i) {
        Channel::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_id' => $group->id,
            'enabled' => false,
            'channel' => 50 + $i,
            'sort' => $i,
        ]);
    }

    // Enable group — no other enabled channels exist
    $group->channels()->update(['enabled' => true]);

    $maxChannel = Channel::query()
        ->where('playlist_id', $group->playlist_id)
        ->where('group_id', '!=', $group->id)
        ->where('enabled', true)
        ->max('channel') ?? 0;

    SortFacade::bulkRecountGroupChannels($group, $maxChannel + 1);

    $channels = Channel::query()
        ->where('group_id', $group->id)
        ->orderBy('sort')
        ->pluck('channel')
        ->all();

    expect($channels)->toBe([1, 2, 3]);
});
