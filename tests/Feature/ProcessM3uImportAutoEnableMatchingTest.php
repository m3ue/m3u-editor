<?php

use App\Jobs\ProcessM3uImport;
use App\Models\Category;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->createQuietly(['user_id' => $this->user->id, 'xtream' => true]);
});

it('matches enabled categories on the provider name so renamed categories still auto enable', function () {
    Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'MARVEL | DC COMICS',
        'name_internal' => '|FR| MARVEL | DC COMICS',
        'enabled' => true,
    ]);
    Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'MANGA',
        'name_internal' => '|FR| MANGA',
        'enabled' => false,
    ]);

    $job = new ProcessM3uImport($this->playlist);

    expect($job->enabledCategories->all())->toBe(['|FR| MARVEL | DC COMICS']);
});

it('matches enabled groups on the provider name and splits them by type', function () {
    Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'News',
        'name_internal' => '|UK| NEWS',
        'type' => 'live',
        'enabled' => true,
    ]);
    Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Movies',
        'name_internal' => '|UK| MOVIES',
        'type' => 'vod',
        'enabled' => true,
    ]);
    Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => '|UK| SPORTS',
        'name_internal' => '|UK| SPORTS',
        'type' => 'vod',
        'enabled' => false,
    ]);

    $job = new ProcessM3uImport($this->playlist);

    expect($job->enabledLiveGroups->all())->toBe(['|UK| NEWS'])
        ->and($job->enabledVodGroups->all())->toBe(['|UK| MOVIES'])
        ->and($job->enabledGroups->sort()->values()->all())->toBe(['|UK| MOVIES', '|UK| NEWS']);
});

it('falls back to the display name when a group has no provider name', function () {
    Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Legacy',
        'name_internal' => null,
        'type' => 'live',
        'enabled' => true,
    ]);

    $job = new ProcessM3uImport($this->playlist);

    expect($job->enabledLiveGroups->all())->toBe(['Legacy']);
});
