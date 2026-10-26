<?php

use App\Models\Bouquet;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Services\PlaylistService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('merges selected group internal names into the bouquet with dedupe', function () {
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_groups' => ['Existing']],
    ]);
    $groups = collect([
        Group::factory()->for($this->playlist)->for($this->user)->create(['name_internal' => 'Sports', 'type' => 'live']),
        Group::factory()->for($this->playlist)->for($this->user)->create(['name_internal' => 'Existing', 'type' => 'live']),
    ]);

    PlaylistService::addGroupRecordsToBouquet($groups, $bouquet->id, 'live');

    expect($bouquet->refresh()->getSelectedLiveGroupNames())
        ->toEqualCanonicalizing(['Existing', 'Sports']);
});

it('aborts on a cross-playlist selection without writing', function () {
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_groups' => []],
    ]);
    $otherPlaylist = Playlist::factory()->for($this->user)->create();
    $groups = collect([
        Group::factory()->for($this->playlist)->for($this->user)->create(['name_internal' => 'A', 'type' => 'live']),
        Group::factory()->for($otherPlaylist)->for($this->user)->create(['name_internal' => 'B', 'type' => 'live']),
    ]);

    PlaylistService::addGroupRecordsToBouquet($groups, $bouquet->id, 'live');

    expect($bouquet->refresh()->getSelectedLiveGroupNames())->toBe([]);
});

it('writes vod and category keys per type', function () {
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);
    $vodGroup = Group::factory()->for($this->playlist)->for($this->user)->create(['name_internal' => 'Movies', 'type' => 'vod']);

    PlaylistService::addGroupRecordsToBouquet(collect([$vodGroup]), $bouquet->id, 'vod');

    expect($bouquet->refresh()->getSelectedVodGroupNames())->toBe(['Movies'])
        ->and($bouquet->getSelectedLiveGroupNames())->toBe([]);
});

it('adds custom groups by id, leaving the provider names alone', function () {
    // Custom groups (user-created, `custom = true`) have no provider name, so they are
    // added by id and match the channels sitting in them, wherever they came from.
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_groups' => ['Existing']],
    ]);
    $groups = collect([
        Group::factory()->for($this->playlist)->for($this->user)->create(['name_internal' => 'Custom A', 'type' => 'live', 'custom' => true]),
        Group::factory()->for($this->playlist)->for($this->user)->create(['name_internal' => 'Custom B', 'type' => 'live', 'custom' => true]),
    ]);

    PlaylistService::addGroupRecordsToBouquet($groups, $bouquet->id, 'live');

    $bouquet->refresh();
    expect($bouquet->getSelectedLiveGroupNames())->toBe(['Existing'])
        ->and($bouquet->group_selections['selected_group_ids'])->toBe($groups->pluck('id')->all());
});

it('splits a mixed selection into provider names and custom group ids', function () {
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_groups' => []],
    ]);
    $groups = collect([
        Group::factory()->for($this->playlist)->for($this->user)->create(['name_internal' => 'Sports', 'type' => 'live', 'custom' => false]),
        Group::factory()->for($this->playlist)->for($this->user)->create(['name_internal' => 'Custom A', 'type' => 'live', 'custom' => true]),
    ]);

    PlaylistService::addGroupRecordsToBouquet($groups, $bouquet->id, 'live');

    $bouquet->refresh();
    expect($bouquet->getSelectedLiveGroupNames())->toBe(['Sports'])
        ->and($bouquet->group_selections['selected_group_ids'])->toBe([$groups[1]->id]);
});
