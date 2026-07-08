<?php

use App\Events\PlaylistCreated;
use App\Events\PlaylistUpdated;
use App\Jobs\AddGroupsToCustomPlaylist;
use App\Models\Category;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/**
 * Mirrors the options() closure on the groups Select in PlaylistResource,
 * so tests stay in sync with the form's filtering logic.
 */
function groupOptionsForType(Playlist $playlist, string $type): array
{
    if ($type === 'series_categories') {
        return Category::where('playlist_id', $playlist->id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    $groupType = $type === 'vod_groups' ? 'vod' : 'live';

    return Group::where('playlist_id', $playlist->id)
        ->where('type', $groupType)
        ->orderBy('name')
        ->pluck('name', 'id')
        ->toArray();
}

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([PlaylistCreated::class, PlaylistUpdated::class]);
    Notification::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->customPlaylist = CustomPlaylist::factory()->create(['user_id' => $this->user->id]);
});

it('syncs group channels to the custom playlist', function () {
    $group = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Action Movies',
        'name_internal' => 'action_movies',
    ]);

    $channels = Channel::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $group->id,
    ]);

    (new AddGroupsToCustomPlaylist(
        userId: $this->user->id,
        groupIds: [$group->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'playlist' => $this->customPlaylist->id],
        type: 'channel',
    ))->handle();

    foreach ($channels as $channel) {
        expect($this->customPlaylist->channels()->where('channels.id', $channel->id)->exists())->toBeTrue();
    }
});

it('uses the group display name as tag in original mode', function () {
    $group = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'My Custom Name',
        'name_internal' => 'provider_internal_name',
    ]);

    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $group->id,
    ]);

    (new AddGroupsToCustomPlaylist(
        userId: $this->user->id,
        groupIds: [$group->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original', 'playlist' => $this->customPlaylist->id],
        type: 'channel',
    ))->handle();

    $channel->refresh();
    $tagNames = $channel->tags->pluck('name')->all();

    expect($tagNames)->toContain('My Custom Name')
        ->and($tagNames)->not->toContain('provider_internal_name');
});

it('attaches a selected existing tag to all channels in select mode', function () {
    $group = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $channels = Channel::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $group->id,
    ]);

    (new AddGroupsToCustomPlaylist(
        userId: $this->user->id,
        groupIds: [$group->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'playlist' => $this->customPlaylist->id, 'category' => 'My Group Tag'],
        type: 'channel',
    ))->handle();

    foreach ($channels as $channel) {
        $channel->refresh();
        expect($channel->tags->pluck('name')->all())->toContain('My Group Tag');
    }
});

it('creates and attaches a new tag to all channels in create mode', function () {
    $group = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $channels = Channel::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $group->id,
    ]);

    (new AddGroupsToCustomPlaylist(
        userId: $this->user->id,
        groupIds: [$group->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'create', 'playlist' => $this->customPlaylist->id, 'new_category' => 'Brand New Tag'],
        type: 'channel',
    ))->handle();

    foreach ($channels as $channel) {
        $channel->refresh();
        expect($channel->tags->pluck('name')->all())->toContain('Brand New Tag');
    }
});

it('processes multiple groups and uses each group name as tag in original mode', function () {
    $groupA = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Group A',
        'name_internal' => 'group_a',
    ]);

    $groupB = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Group B',
        'name_internal' => 'group_b',
    ]);

    $channelA = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $groupA->id,
    ]);

    $channelB = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $groupB->id,
    ]);

    (new AddGroupsToCustomPlaylist(
        userId: $this->user->id,
        groupIds: [$groupA->id, $groupB->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original', 'playlist' => $this->customPlaylist->id],
        type: 'channel',
    ))->handle();

    $channelA->refresh();
    $channelB->refresh();

    expect($channelA->tags->pluck('name')->all())->toContain('Group A');
    expect($channelB->tags->pluck('name')->all())->toContain('Group B');
});

it('syncs series to the custom playlist for categories', function () {
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Drama',
        'name_internal' => 'drama_internal',
    ]);

    $seriesItems = Series::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $category->id,
    ]);

    (new AddGroupsToCustomPlaylist(
        userId: $this->user->id,
        groupIds: [$category->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original', 'playlist' => $this->customPlaylist->id],
        type: 'series',
    ))->handle();

    foreach ($seriesItems as $series) {
        expect($this->customPlaylist->series()->where('series.id', $series->id)->exists())->toBeTrue();
    }
});

it('uses category display name as tag in original mode for series', function () {
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'My Drama Category',
        'name_internal' => 'drama_provider_name',
    ]);

    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $category->id,
    ]);

    (new AddGroupsToCustomPlaylist(
        userId: $this->user->id,
        groupIds: [$category->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'original', 'playlist' => $this->customPlaylist->id],
        type: 'series',
    ))->handle();

    $series->refresh();
    $tagNames = $series->tags->pluck('name')->all();

    expect($tagNames)->toContain('My Drama Category')
        ->and($tagNames)->not->toContain('drama_provider_name');
});

it('completes without errors for a group with no channels', function () {
    $group = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    // No channels created — should complete silently
    (new AddGroupsToCustomPlaylist(
        userId: $this->user->id,
        groupIds: [$group->id],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'playlist' => $this->customPlaylist->id],
        type: 'channel',
    ))->handle();

    expect($this->customPlaylist->channels()->count())->toBe(0);
});

it('skips missing group ids gracefully', function () {
    (new AddGroupsToCustomPlaylist(
        userId: $this->user->id,
        groupIds: [99999],
        customPlaylistId: $this->customPlaylist->id,
        data: ['mode' => 'select', 'playlist' => $this->customPlaylist->id],
        type: 'channel',
    ))->handle();

    expect($this->customPlaylist->channels()->count())->toBe(0);
});

it('only shows live groups for live_groups type', function () {
    $liveGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Live News',
        'type' => 'live',
    ]);

    $vodGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'VOD Movies',
        'type' => 'vod',
    ]);

    $options = groupOptionsForType($this->playlist, 'live_groups');

    expect($options)
        ->toHaveKey($liveGroup->id)
        ->not->toHaveKey($vodGroup->id);
});

it('only shows VOD groups for vod_groups type', function () {
    $liveGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Live Sports',
        'type' => 'live',
    ]);

    $vodGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'VOD Action',
        'type' => 'vod',
    ]);

    $options = groupOptionsForType($this->playlist, 'vod_groups');

    expect($options)
        ->toHaveKey($vodGroup->id)
        ->not->toHaveKey($liveGroup->id);
});

it('shows series categories for series_categories type and not live or VOD groups', function () {
    Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Live Sports',
        'type' => 'live',
    ]);

    Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'VOD Action',
        'type' => 'vod',
    ]);

    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Drama',
    ]);

    $options = groupOptionsForType($this->playlist, 'series_categories');

    // Only Category records should appear — no Group names
    expect($options)
        ->toHaveKey($category->id)
        ->and(array_values($options))->toContain('Drama')
        ->and(array_values($options))->not->toContain('Live Sports')
        ->and(array_values($options))->not->toContain('VOD Action');
});

it('still shows a live group that is already attached to the custom playlist', function () {
    $liveGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Attached Live',
        'type' => 'live',
    ]);
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $liveGroup->id,
    ]);
    $this->customPlaylist->channels()->attach($channel->id);

    $options = groupOptionsForType($this->playlist, 'live_groups');

    expect($options)->toHaveKey($liveGroup->id);
});

it('still shows a VOD group that is already attached to the custom playlist', function () {
    $vodGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Attached VOD',
        'type' => 'vod',
    ]);
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $vodGroup->id,
        'is_vod' => true,
    ]);
    $this->customPlaylist->channels()->attach($channel->id);

    $options = groupOptionsForType($this->playlist, 'vod_groups');

    expect($options)->toHaveKey($vodGroup->id);
});
