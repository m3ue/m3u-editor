<?php

use App\Facades\SortFacade;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->createQuietly(['user_id' => $this->user->id]);
    $this->actingAs($this->user);
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

it('recounts selected groups ordered by sort_order, then name and id, with sequential channel numbering', function () {
    $groupA = Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Group A',
        'sort_order' => 20,
    ]);
    $groupB = Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Group B',
        'sort_order' => 10,
    ]);

    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $groupA->id,
        'sort' => 30,
        'channel' => 30,
    ]);

    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $groupA->id,
        'sort' => 10,
        'channel' => 10,
    ]);

    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $groupB->id,
        'sort' => 5,
        'channel' => 50,
    ]);

    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $groupB->id,
        'sort' => 1,
        'channel' => 51,
    ]);

    $groups = Group::query()
        ->whereIn('id', [$groupA->id, $groupB->id])
        ->get();

    SortFacade::bulkRecountGroupsByOrder($groups, 1);

    $groupBChannels = Channel::query()
        ->where('group_id', $groupB->id)
        ->orderBy('sort')
        ->pluck('channel')
        ->all();

    $groupAChannels = Channel::query()
        ->where('group_id', $groupA->id)
        ->orderBy('sort')
        ->pluck('channel')
        ->all();

    expect($groupBChannels)->toBe([1, 2]);
    expect($groupAChannels)->toBe([3, 4]);
});

it('runs the groups table bulk recount across selected groups by group order', function () {
    $groupA = Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Group A',
        'sort_order' => 20,
        'type' => 'live',
    ]);
    $groupB = Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Group B',
        'sort_order' => 10,
        'type' => 'live',
    ]);

    foreach ([30, 10] as $sort) {
        Channel::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_id' => $groupA->id,
            'sort' => $sort,
            'channel' => 900 + $sort,
            'is_vod' => false,
        ]);
    }

    foreach ([5, 1] as $sort) {
        Channel::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_id' => $groupB->id,
            'sort' => $sort,
            'channel' => 800 + $sort,
            'is_vod' => false,
        ]);
    }

    Livewire::test(ListGroups::class)
        ->callTableBulkAction('recount_channels', collect([$groupA, $groupB]), [
            'start' => 100,
        ]);

    expect(Channel::query()
        ->where('group_id', $groupB->id)
        ->orderBy('sort')
        ->pluck('channel')
        ->all())->toBe([100, 101]);

    expect(Channel::query()
        ->where('group_id', $groupA->id)
        ->orderBy('sort')
        ->pluck('channel')
        ->all())->toBe([102, 103]);
});

it('uses stable name and id fallback when sort_order is the same for selected groups', function () {
    $groupAlpha = Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Alpha Group',
        'sort_order' => 5,
    ]);

    $groupAlphaDuplicate = Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Alpha Group',
        'sort_order' => 5,
    ]);

    $groupZulu = Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Zulu Group',
        'sort_order' => 5,
    ]);

    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $groupAlpha->id,
        'sort' => 1,
        'channel' => 11,
    ]);

    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $groupAlphaDuplicate->id,
        'sort' => 1,
        'channel' => 12,
    ]);

    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $groupZulu->id,
        'sort' => 1,
        'channel' => 13,
    ]);

    $groups = Group::query()
        ->whereIn('id', [$groupZulu->id, $groupAlpha->id, $groupAlphaDuplicate->id])
        ->get();

    SortFacade::bulkRecountGroupsByOrder($groups, 10);

    $alphaChannels = Channel::query()
        ->where('group_id', $groupAlpha->id)
        ->orderBy('sort')
        ->pluck('channel')
        ->all();

    $alphaDuplicateChannels = Channel::query()
        ->where('group_id', $groupAlphaDuplicate->id)
        ->orderBy('sort')
        ->pluck('channel')
        ->all();

    $zuluChannels = Channel::query()
        ->where('group_id', $groupZulu->id)
        ->orderBy('sort')
        ->pluck('channel')
        ->all();

    expect($alphaChannels)->toBe([10]);
    expect($alphaDuplicateChannels)->toBe([11]);
    expect($zuluChannels)->toBe([12]);
});

it('active_only recount skips disabled channels and leaves them unchanged', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Group A',
        'sort_order' => 1,
        'type' => 'live',
    ]);

    $disabled = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $group->id,
        'enabled' => false,
        'is_vod' => false,
        'sort' => 1,
        'channel' => 999,
    ]);

    foreach ([2, 3] as $sort) {
        Channel::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_id' => $group->id,
            'enabled' => true,
            'is_vod' => false,
            'sort' => $sort,
            'channel' => 700 + $sort,
        ]);
    }

    $groups = Group::query()->where('id', $group->id)->get();
    SortFacade::bulkRecountGroupsByOrder($groups, 5, activeOnly: true);

    expect(Channel::query()->where('group_id', $group->id)->where('enabled', true)->orderBy('sort')->pluck('channel')->all())->toBe([5, 6]);
    expect($disabled->refresh()->channel)->toBe(999);
});

it('active_only recount processes groups in sort_order then name then id order', function () {
    $groupB = Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Group B',
        'sort_order' => 1,
        'type' => 'live',
    ]);
    $groupA = Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Group A',
        'sort_order' => 2,
        'type' => 'live',
    ]);

    $disabledInB = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $groupB->id,
        'enabled' => false,
        'is_vod' => false,
        'sort' => 1,
        'channel' => 777,
    ]);

    foreach ([2, 3] as $sort) {
        Channel::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_id' => $groupB->id,
            'enabled' => true,
            'is_vod' => false,
            'sort' => $sort,
            'channel' => 900 + $sort,
        ]);
    }

    foreach ([1, 2] as $sort) {
        Channel::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_id' => $groupA->id,
            'enabled' => true,
            'is_vod' => false,
            'sort' => $sort,
            'channel' => 800 + $sort,
        ]);
    }

    // Pass groups in reverse order to confirm sorting is applied internally
    $groups = Group::query()->whereIn('id', [$groupA->id, $groupB->id])->get();
    SortFacade::bulkRecountGroupsByOrder($groups, 1, activeOnly: true);

    // Group B (sort_order 1) should be numbered first: 1, 2
    expect(Channel::query()->where('group_id', $groupB->id)->where('enabled', true)->where('is_vod', false)->orderBy('sort')->pluck('channel')->all())->toBe([1, 2]);
    // Group A (sort_order 2) follows: 3, 4
    expect(Channel::query()->where('group_id', $groupA->id)->where('enabled', true)->where('is_vod', false)->orderBy('sort')->pluck('channel')->all())->toBe([3, 4]);
    expect($disabledInB->refresh()->channel)->toBe(777);
});

it('active_only recount via bulk table action respects start number and group order', function () {
    $groupA = Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Group A',
        'sort_order' => 10,
        'type' => 'live',
    ]);
    $groupB = Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Group B',
        'sort_order' => 20,
        'type' => 'live',
    ]);

    $disabled = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $groupA->id,
        'enabled' => false,
        'is_vod' => false,
        'sort' => 1,
        'channel' => 555,
    ]);

    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $groupA->id,
        'enabled' => true,
        'is_vod' => false,
        'sort' => 2,
        'channel' => 900,
    ]);

    foreach ([1, 2] as $sort) {
        Channel::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_id' => $groupB->id,
            'enabled' => true,
            'is_vod' => false,
            'sort' => $sort,
            'channel' => 800 + $sort,
        ]);
    }

    Livewire::test(ListGroups::class)
        ->callTableBulkAction('recount_channels', collect([$groupB, $groupA]), [
            'start' => 100,
            'active_only' => true,
        ]);

    // Group A (sort_order 10) first: 1 active channel → 100
    expect(Channel::query()->where('group_id', $groupA->id)->where('enabled', true)->where('is_vod', false)->orderBy('sort')->pluck('channel')->all())->toBe([100]);
    // Group B (sort_order 20) follows: 2 active channels → 101, 102
    expect(Channel::query()->where('group_id', $groupB->id)->where('enabled', true)->where('is_vod', false)->orderBy('sort')->pluck('channel')->all())->toBe([101, 102]);
    expect($disabled->refresh()->channel)->toBe(555);
});
