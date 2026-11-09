<?php

use App\Jobs\ProcessM3uImport;
use App\Models\Category;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\SourceGroup;
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

it('keeps matching an already-enabled group the provider renames mid-sync (issue #1649)', function () {
    Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Movies',
        'name_internal' => 'MOVIES OLD NAME',
        'type' => 'vod',
        'enabled' => true,
    ]);

    $job = new ProcessM3uImport($this->playlist);

    // Snapshot built in the constructor still holds the pre-rename name.
    expect($job->enabledVodGroups->all())->toBe(['MOVIES OLD NAME']);

    $method = new ReflectionMethod($job, 'applyRenamesToEnabledGroupSnapshot');
    $method->invoke($job, ['MOVIES OLD NAME' => 'MOVIES NEW NAME'], 'vod');

    expect($job->enabledVodGroups->all())->toBe(['MOVIES NEW NAME'])
        ->and($job->enabledGroups->all())->toBe(['MOVIES NEW NAME']);
});

it('only rewrites the enabled-groups snapshot for the renamed type', function () {
    Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'News',
        'name_internal' => 'NEWS OLD NAME',
        'type' => 'live',
        'enabled' => true,
    ]);
    Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Movies',
        'name_internal' => 'MOVIES OLD NAME',
        'type' => 'vod',
        'enabled' => true,
    ]);

    $job = new ProcessM3uImport($this->playlist);
    $method = new ReflectionMethod($job, 'applyRenamesToEnabledGroupSnapshot');

    // A VOD-type rename must not touch the live snapshot.
    $method->invoke($job, ['MOVIES OLD NAME' => 'MOVIES NEW NAME'], 'vod');

    expect($job->enabledLiveGroups->all())->toBe(['NEWS OLD NAME'])
        ->and($job->enabledVodGroups->all())->toBe(['MOVIES NEW NAME']);
});

it('resolves the provider rename map from syncSourceGroupType for the enabled-groups fix', function () {
    $this->playlist->update(['import_prefs' => []]);

    SourceGroup::create([
        'name' => 'MOVIES OLD NAME', 'playlist_id' => $this->playlist->id,
        'source_group_id' => 555, 'type' => 'vod',
    ]);
    Group::factory()->for($this->playlist)->for($this->user)->create([
        'name' => 'Movies',
        'name_internal' => 'MOVIES OLD NAME',
        'type' => 'vod',
        'enabled' => true,
    ]);

    $job = new ProcessM3uImport($this->playlist);
    $syncMethod = new ReflectionMethod($job, 'syncSourceGroupType');
    [, , $renames] = $syncMethod->invoke(
        $job,
        collect([['category_id' => 555, 'category_name' => 'MOVIES NEW NAME']]),
        'vod',
        'selected_vod_groups',
        $job->selectedVodGroups,
        $this->playlist,
    );

    expect($renames)->toBe(['MOVIES OLD NAME' => 'MOVIES NEW NAME']);

    $applyMethod = new ReflectionMethod($job, 'applyRenamesToEnabledGroupSnapshot');
    $applyMethod->invoke($job, $renames, 'vod');

    expect($job->enabledVodGroups->all())->toBe(['MOVIES NEW NAME']);
});
