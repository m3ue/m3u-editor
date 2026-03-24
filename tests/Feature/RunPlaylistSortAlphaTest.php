<?php

use App\Jobs\RunPlaylistSortAlpha;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;

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
