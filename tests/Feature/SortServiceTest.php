<?php

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Services\SortService;

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
