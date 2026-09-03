<?php

use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\DynamicGroups\Pages\ListDynamicGroups;
use App\Filament\Resources\DynamicGroups\Pages\ViewDynamicGroup;
use App\Filament\Resources\DynamicGroups\RelationManagers\ChannelsRelationManager;
use App\Filament\Resources\DynamicGroups\RelationManagers\SeriesRelationManager;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('non-admin sees only their own dynamic groups on the list page', function () {
    // Another user's dynamic group on the same playlist (forced via raw
    // user_id) — should NOT show up on the current user's list.
    $otherUser = User::factory()->create();
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $otherUser->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Theirs',
    ]);
    $mine = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Mine',
    ]);

    Livewire::test(ListDynamicGroups::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([DynamicGroup::where('user_id', $otherUser->id)->first()]);
});

it('shows the channels_count for vod-type rows and series_count for series-type rows in the items column', function () {
    $vodGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'VOD Trending',
    ]);
    $seriesGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Series Trending',
    ]);
    // 3 channels attached to $vodGroup, 2 series attached to $seriesGroup.
    $channels = [];
    for ($i = 0; $i < 3; $i++) {
        $channels[] = Channel::factory()->for($this->user)->for($this->playlist)->create(['is_vod' => true]);
    }
    foreach ($channels as $channel) {
        DB::table('dynamic_group_items')->insert([
            'dynamic_group_id' => $vodGroup->id,
            'item_type' => Channel::class,
            'item_id' => $channel->id,
        ]);
    }
    $series = [];
    for ($i = 0; $i < 2; $i++) {
        $series[] = Series::factory()->for($this->user)->for($this->playlist)->create();
    }
    foreach ($series as $s) {
        DB::table('dynamic_group_items')->insert([
            'dynamic_group_id' => $seriesGroup->id,
            'item_type' => Series::class,
            'item_id' => $s->id,
        ]);
    }

    Livewire::test(ListDynamicGroups::class)
        ->assertOk()
        ->loadTable()
        ->assertTableColumnFormattedStateSet('item_count', '3', $vodGroup)
        ->assertTableColumnFormattedStateSet('item_count', '2', $seriesGroup);
});

it('shows the Channels relation manager on vod-type dynamic groups and hides it on series-type', function () {
    $vodGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'VOD',
    ]);
    $seriesGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Series',
    ]);

    expect(ChannelsRelationManager::canViewForRecord($vodGroup, ViewDynamicGroup::class))->toBeTrue()
        ->and(ChannelsRelationManager::canViewForRecord($seriesGroup, ViewDynamicGroup::class))->toBeFalse()
        ->and(SeriesRelationManager::canViewForRecord($vodGroup, ViewDynamicGroup::class))->toBeFalse()
        ->and(SeriesRelationManager::canViewForRecord($seriesGroup, ViewDynamicGroup::class))->toBeTrue();
});

it('lists the real synced dynamic_group_items members on the Channels relation manager table', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Netflix',
    ]);
    $attached = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'is_vod' => true, 'title' => 'Attached Movie',
    ]);
    $notAttached = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'is_vod' => true, 'title' => 'Not Attached',
    ]);
    DB::table('dynamic_group_items')->insert([
        'dynamic_group_id' => $group->id,
        'item_type' => Channel::class,
        'item_id' => $attached->id,
    ]);

    Livewire::test(ChannelsRelationManager::class, [
        'ownerRecord' => $group,
        'pageClass' => ViewDynamicGroup::class,
    ])
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$attached])
        ->assertCanNotSeeTableRecords([$notAttached]);
});

it('lists the real synced dynamic_group_items members on the Series relation manager table', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Netflix Shows',
    ]);
    $attached = Series::factory()->for($this->user)->for($this->playlist)->create(['name' => 'Attached Show']);
    DB::table('dynamic_group_items')->insert([
        'dynamic_group_id' => $group->id,
        'item_type' => Series::class,
        'item_id' => $attached->id,
    ]);

    Livewire::test(SeriesRelationManager::class, [
        'ownerRecord' => $group,
        'pageClass' => ViewDynamicGroup::class,
    ])
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$attached]);
});

it('exposes no edit or create routes — read-only resource', function () {
    // canCreate() must be false so the navigation doesn't render a Create button.
    expect(DynamicGroupResource::canCreate())->toBeFalse()
        // Only 'index' and 'view' routes are registered. Anything else would
        // mean a write capability slipped in.
        ->and(DynamicGroupResource::getPages())->toHaveKeys(['index', 'view'])
        ->and(DynamicGroupResource::getPages())->not->toHaveKey('create')
        ->and(DynamicGroupResource::getPages())->not->toHaveKey('edit');
});
