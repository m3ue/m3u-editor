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
use App\Services\PlaylistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

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

it('only offers eligible unattached VOD groups for auto-sync group options', function () {
    $liveGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Live News',
        'type' => 'live',
    ]);
    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $liveGroup->id,
        'is_vod' => false,
    ]);

    $attachedVodGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Already Added VOD',
        'type' => 'vod',
    ]);
    $attachedVodChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $attachedVodGroup->id,
        'is_vod' => true,
    ]);
    $this->customPlaylist->channels()->attach($attachedVodChannel->id);

    $eligibleVodGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Eligible VOD',
        'type' => 'vod',
    ]);
    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $eligibleVodGroup->id,
        'is_vod' => true,
    ]);

    $options = PlaylistService::getEligibleAutoSyncGroupOptions($this->playlist, $this->customPlaylist->id, 'vod_groups');

    expect($options)
        ->toHaveKey($eligibleVodGroup->id)
        ->not->toHaveKey($attachedVodGroup->id)
        ->not->toHaveKey($liveGroup->id);
});

it('keeps partially attached VOD groups eligible when they still contain unattached VOD channels', function () {
    $group = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Partially Added VOD',
        'type' => 'vod',
    ]);

    $attachedChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $group->id,
        'is_vod' => true,
    ]);
    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $group->id,
        'is_vod' => true,
    ]);
    $this->customPlaylist->channels()->attach($attachedChannel->id);

    $options = PlaylistService::getEligibleAutoSyncGroupOptions($this->playlist, $this->customPlaylist->id, 'vod_groups');

    expect($options)->toHaveKey($group->id);
});

it('only offers eligible unattached live groups for auto-sync group options', function () {
    $attachedLiveGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Already Added Live',
        'type' => 'live',
    ]);
    $attachedLiveChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $attachedLiveGroup->id,
        'is_vod' => false,
    ]);
    $this->customPlaylist->channels()->attach($attachedLiveChannel->id);

    $eligibleLiveGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Eligible Live',
        'type' => 'live',
    ]);
    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $eligibleLiveGroup->id,
        'is_vod' => false,
    ]);

    $vodGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'VOD Movies',
        'type' => 'vod',
    ]);
    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $vodGroup->id,
        'is_vod' => true,
    ]);

    $options = PlaylistService::getEligibleAutoSyncGroupOptions($this->playlist, $this->customPlaylist->id, 'live_groups');

    expect($options)
        ->toHaveKey($eligibleLiveGroup->id)
        ->not->toHaveKey($attachedLiveGroup->id)
        ->not->toHaveKey($vodGroup->id);
});

it('only offers eligible unattached series categories for auto-sync group options', function () {
    $attachedCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Already Added Series',
    ]);
    $attachedSeries = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $attachedCategory->id,
    ]);
    $this->customPlaylist->series()->attach($attachedSeries->id);

    $eligibleCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Eligible Series',
    ]);
    Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $eligibleCategory->id,
    ]);

    $options = PlaylistService::getEligibleAutoSyncGroupOptions($this->playlist, $this->customPlaylist->id, 'series_categories');

    expect($options)
        ->toHaveKey($eligibleCategory->id)
        ->not->toHaveKey($attachedCategory->id);
});

it('includes empty VOD groups in auto-sync options so they can be targeted before channels are synced', function () {
    $emptyVodGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'PPV Events',
        'type' => 'vod',
    ]);

    $options = PlaylistService::getEligibleAutoSyncGroupOptions($this->playlist, $this->customPlaylist->id, 'vod_groups');

    expect($options)->toHaveKey($emptyVodGroup->id);
});

it('includes empty live groups in auto-sync options so they can be targeted before channels are synced', function () {
    $emptyLiveGroup = Group::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Sports Events',
        'type' => 'live',
    ]);

    $options = PlaylistService::getEligibleAutoSyncGroupOptions($this->playlist, $this->customPlaylist->id, 'live_groups');

    expect($options)->toHaveKey($emptyLiveGroup->id);
});

it('includes empty series categories in auto-sync options so they can be targeted before series are synced', function () {
    $emptyCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Upcoming Shows',
    ]);

    $options = PlaylistService::getEligibleAutoSyncGroupOptions($this->playlist, $this->customPlaylist->id, 'series_categories');

    expect($options)->toHaveKey($emptyCategory->id);
});
