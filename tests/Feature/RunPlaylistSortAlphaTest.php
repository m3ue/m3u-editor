<?php

use App\Jobs\RunPlaylistSortAlpha;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'sort_alpha_config' => null,
    ]);
});

it('does nothing when sort_alpha_config is empty', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create(['type' => 'live']);
    Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create(['title' => 'Zebra', 'sort' => 1]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create(['title' => 'Alpha', 'sort' => 2]);

    (new RunPlaylistSortAlpha($this->playlist))->handle();

    // sort values unchanged
    expect($group->channels()->orderBy('sort')->pluck('title')->toArray())
        ->toBe(['Zebra', 'Alpha']);
});

it('skips disabled rules', function () {
    $this->playlist->update([
        'sort_alpha_config' => [
            ['enabled' => false, 'target' => 'live_groups', 'group' => ['all'], 'column' => 'title', 'sort' => 'ASC'],
        ],
    ]);

    $group = Group::factory()->for($this->playlist)->for($this->user)->create(['type' => 'live']);
    Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create(['title' => 'Zebra', 'sort' => 1]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create(['title' => 'Alpha', 'sort' => 2]);

    (new RunPlaylistSortAlpha($this->playlist))->handle();

    expect($group->channels()->orderBy('sort')->pluck('title')->toArray())
        ->toBe(['Zebra', 'Alpha']);
});

it('sorts live group channels alphabetically ASC', function () {
    $this->playlist->update([
        'sort_alpha_config' => [
            ['enabled' => true, 'target' => 'live_groups', 'group' => ['all'], 'column' => 'title', 'sort' => 'ASC'],
        ],
    ]);

    $group = Group::factory()->for($this->playlist)->for($this->user)->create(['type' => 'live']);
    Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create(['title' => 'Zebra', 'sort' => 1]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create(['title' => 'Alpha', 'sort' => 2]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create(['title' => 'Mango', 'sort' => 3]);

    (new RunPlaylistSortAlpha($this->playlist))->handle();

    expect($group->channels()->orderBy('sort')->pluck('title')->toArray())
        ->toBe(['Alpha', 'Mango', 'Zebra']);
});

it('sorts live group channels alphabetically DESC', function () {
    $this->playlist->update([
        'sort_alpha_config' => [
            ['enabled' => true, 'target' => 'live_groups', 'group' => ['all'], 'column' => 'title', 'sort' => 'DESC'],
        ],
    ]);

    $group = Group::factory()->for($this->playlist)->for($this->user)->create(['type' => 'live']);
    Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create(['title' => 'Alpha', 'sort' => 1]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($group)->create(['title' => 'Zebra', 'sort' => 2]);

    (new RunPlaylistSortAlpha($this->playlist))->handle();

    expect($group->channels()->orderBy('sort')->pluck('title')->toArray())
        ->toBe(['Zebra', 'Alpha']);
});

it('only sorts the specified groups when group selection is not all', function () {
    $this->playlist->update([
        'sort_alpha_config' => [
            ['enabled' => true, 'target' => 'live_groups', 'group' => ['Sports'], 'column' => 'title', 'sort' => 'ASC'],
        ],
    ]);

    $sports = Group::factory()->for($this->playlist)->for($this->user)->create(['type' => 'live', 'name_internal' => 'Sports']);
    Channel::factory()->for($this->user)->for($this->playlist)->for($sports)->create(['title' => 'Zebra', 'sort' => 1]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($sports)->create(['title' => 'Alpha', 'sort' => 2]);

    $news = Group::factory()->for($this->playlist)->for($this->user)->create(['type' => 'live', 'name_internal' => 'News']);
    Channel::factory()->for($this->user)->for($this->playlist)->for($news)->create(['title' => 'Zebra', 'sort' => 1]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($news)->create(['title' => 'Alpha', 'sort' => 2]);

    (new RunPlaylistSortAlpha($this->playlist))->handle();

    // Sports group sorted
    expect($sports->channels()->orderBy('sort')->pluck('title')->toArray())
        ->toBe(['Alpha', 'Zebra']);

    // News group untouched
    expect($news->channels()->orderBy('sort')->pluck('title')->toArray())
        ->toBe(['Zebra', 'Alpha']);
});

it('only sorts vod groups when target is vod_groups', function () {
    $this->playlist->update([
        'sort_alpha_config' => [
            ['enabled' => true, 'target' => 'vod_groups', 'group' => ['all'], 'column' => 'title', 'sort' => 'ASC'],
        ],
    ]);

    $liveGroup = Group::factory()->for($this->playlist)->for($this->user)->create(['type' => 'live']);
    Channel::factory()->for($this->user)->for($this->playlist)->for($liveGroup)->create(['title' => 'Zebra', 'sort' => 1]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($liveGroup)->create(['title' => 'Alpha', 'sort' => 2]);

    $vodGroup = Group::factory()->for($this->playlist)->for($this->user)->create(['type' => 'vod']);
    Channel::factory()->for($this->user)->for($this->playlist)->for($vodGroup)->create(['title' => 'Zebra VOD', 'sort' => 1]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($vodGroup)->create(['title' => 'Alpha VOD', 'sort' => 2]);

    (new RunPlaylistSortAlpha($this->playlist))->handle();

    // Live group order unchanged
    expect($liveGroup->channels()->orderBy('sort')->pluck('title')->toArray())
        ->toBe(['Zebra', 'Alpha']);

    // VOD group sorted
    expect($vodGroup->channels()->orderBy('sort')->pluck('title')->toArray())
        ->toBe(['Alpha VOD', 'Zebra VOD']);
});

it('sorts all vod groups by release date across the playlist', function () {
    $this->playlist->update([
        'sort_alpha_config' => [
            ['enabled' => true, 'target' => 'vod_groups', 'group' => ['all'], 'column' => 'release_date', 'sort' => 'DESC'],
        ],
    ]);

    $firstGroup = Group::factory()->for($this->playlist)->for($this->user)->create(['type' => 'vod']);
    $oldest = Channel::factory()->for($this->user)->for($this->playlist)->for($firstGroup)->create([
        'title' => 'Oldest',
        'is_vod' => true,
        'info' => ['release_date' => '2020-01-01'],
        'sort' => 1,
    ]);
    $newest = Channel::factory()->for($this->user)->for($this->playlist)->for($firstGroup)->create([
        'title' => 'Newest',
        'is_vod' => true,
        'info' => ['release_date' => '2024-01-01'],
        'sort' => 2,
    ]);

    $secondGroup = Group::factory()->for($this->playlist)->for($this->user)->create(['type' => 'vod']);
    $middle = Channel::factory()->for($this->user)->for($this->playlist)->for($secondGroup)->create([
        'title' => 'Middle',
        'is_vod' => true,
        'info' => ['release_date' => '2022-01-01'],
        'sort' => 1,
    ]);

    (new RunPlaylistSortAlpha($this->playlist))->handle();

    expect($this->playlist->vod_channels()->orderBy('sort')->pluck('id')->all())
        ->toBe([$newest->id, $middle->id, $oldest->id]);
});

it('sorts only selected vod groups by release date', function () {
    $this->playlist->update([
        'sort_alpha_config' => [
            ['enabled' => true, 'target' => 'vod_groups', 'group' => ['Selected VOD'], 'column' => 'release_date', 'sort' => 'ASC'],
        ],
    ]);

    $selectedGroup = Group::factory()->for($this->playlist)->for($this->user)->create([
        'type' => 'vod',
        'name_internal' => 'Selected VOD',
    ]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($selectedGroup)->create([
        'title' => 'Newer selected',
        'is_vod' => true,
        'info' => ['release_date' => '2024-01-01'],
        'sort' => 1,
    ]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($selectedGroup)->create([
        'title' => 'Older selected',
        'is_vod' => true,
        'info' => ['release_date' => '2020-01-01'],
        'sort' => 2,
    ]);

    $unselectedGroup = Group::factory()->for($this->playlist)->for($this->user)->create([
        'type' => 'vod',
        'name_internal' => 'Unselected VOD',
    ]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($unselectedGroup)->create([
        'title' => 'Newer unselected',
        'is_vod' => true,
        'info' => ['release_date' => '2024-01-01'],
        'sort' => 1,
    ]);
    Channel::factory()->for($this->user)->for($this->playlist)->for($unselectedGroup)->create([
        'title' => 'Older unselected',
        'is_vod' => true,
        'info' => ['release_date' => '2020-01-01'],
        'sort' => 2,
    ]);

    (new RunPlaylistSortAlpha($this->playlist))->handle();

    expect($selectedGroup->channels()->orderBy('sort')->pluck('title')->all())
        ->toBe(['Older selected', 'Newer selected'])
        ->and($unselectedGroup->channels()->orderBy('sort')->pluck('title')->all())
        ->toBe(['Newer unselected', 'Older unselected']);
});

it('sorts all series categories by release date across the playlist', function () {
    $this->playlist->update([
        'sort_alpha_config' => [
            ['enabled' => true, 'target' => 'series_categories', 'group' => ['all'], 'column' => 'release_date', 'sort' => 'DESC'],
        ],
    ]);

    $firstCategory = Category::factory()->for($this->playlist)->for($this->user)->create();
    $oldest = Series::factory()->for($this->user)->for($this->playlist)->for($firstCategory)->create([
        'name' => 'Oldest',
        'release_date' => '2020-01-01',
        'sort' => 1,
    ]);
    $newest = Series::factory()->for($this->user)->for($this->playlist)->for($firstCategory)->create([
        'name' => 'Newest',
        'release_date' => '2024-01-01',
        'sort' => 2,
    ]);

    $secondCategory = Category::factory()->for($this->playlist)->for($this->user)->create();
    $middle = Series::factory()->for($this->user)->for($this->playlist)->for($secondCategory)->create([
        'name' => 'Middle',
        'release_date' => '2022-01-01',
        'sort' => 1,
    ]);

    (new RunPlaylistSortAlpha($this->playlist))->handle();

    expect($this->playlist->series()->orderBy('sort')->pluck('id')->all())
        ->toBe([$newest->id, $middle->id, $oldest->id]);
});

it('sorts only selected series categories by release date', function () {
    $this->playlist->update([
        'sort_alpha_config' => [
            ['enabled' => true, 'target' => 'series_categories', 'group' => ['Selected Series'], 'column' => 'release_date', 'sort' => 'ASC'],
        ],
    ]);

    $selectedCategory = Category::factory()->for($this->playlist)->for($this->user)->create([
        'name_internal' => 'Selected Series',
    ]);
    Series::factory()->for($this->user)->for($this->playlist)->for($selectedCategory)->create([
        'name' => 'Newer selected',
        'release_date' => '2024-01-01',
        'sort' => 1,
    ]);
    Series::factory()->for($this->user)->for($this->playlist)->for($selectedCategory)->create([
        'name' => 'Older selected',
        'release_date' => '2020-01-01',
        'sort' => 2,
    ]);

    $unselectedCategory = Category::factory()->for($this->playlist)->for($this->user)->create([
        'name_internal' => 'Unselected Series',
    ]);
    Series::factory()->for($this->user)->for($this->playlist)->for($unselectedCategory)->create([
        'name' => 'Newer unselected',
        'release_date' => '2024-01-01',
        'sort' => 1,
    ]);
    Series::factory()->for($this->user)->for($this->playlist)->for($unselectedCategory)->create([
        'name' => 'Older unselected',
        'release_date' => '2020-01-01',
        'sort' => 2,
    ]);

    (new RunPlaylistSortAlpha($this->playlist))->handle();

    expect($selectedCategory->series()->orderBy('sort')->pluck('name')->all())
        ->toBe(['Older selected', 'Newer selected'])
        ->and($unselectedCategory->series()->orderBy('sort')->pluck('name')->all())
        ->toBe(['Newer unselected', 'Older unselected']);
});

it('summarizes executed live vod and series rules in the notification', function () {
    $this->playlist->update([
        'sort_alpha_config' => [
            ['enabled' => true, 'target' => 'live_groups', 'group' => ['all'], 'column' => 'title', 'sort' => 'ASC'],
            ['enabled' => true, 'target' => 'vod_groups', 'group' => ['all'], 'column' => 'release_date', 'sort' => 'DESC'],
            ['enabled' => true, 'target' => 'series_categories', 'group' => ['all'], 'column' => 'release_date', 'sort' => 'DESC'],
        ],
    ]);
    NotificationFacade::fake();

    (new RunPlaylistSortAlpha($this->playlist))->handle();

    NotificationFacade::assertSentTo(
        $this->user,
        DatabaseNotification::class,
        fn (DatabaseNotification $notification): bool => $notification->data['title'] === 'Playlist sorting completed'
            && str_contains($notification->data['body'], '1 live rule')
            && str_contains($notification->data['body'], '1 VOD rule')
            && str_contains($notification->data['body'], '1 Series rule'),
    );
});
