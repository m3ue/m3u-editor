<?php

use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Categories\Widgets\DynamicGroupsWidget as SeriesDynamicGroupsWidget;
use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\VodGroups\Pages\ListVodGroups;
use App\Filament\Resources\VodGroups\Widgets\DynamicGroupsWidget as VodDynamicGroupsWidget;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('the VOD widget on ListVodGroups shows only vod-type Dynamic Groups for the authenticated user', function () {
    // Same-user: vod (should show).
    $vodMine = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'My VOD Trending',
    ]);
    // Same-user: series (should NOT show — wrong type).
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'My Series Trending',
    ]);
    // Other-user: vod (should NOT show — wrong owner).
    $otherUser = User::factory()->create();
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $otherUser->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Their VOD Trending',
    ]);

    Livewire::test(VodDynamicGroupsWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$vodMine])
        ->assertCanNotSeeTableRecords([
            DynamicGroup::where('name', 'My Series Trending')->first(),
            DynamicGroup::where('name', 'Their VOD Trending')->first(),
        ]);
});

it('the Series widget on ListCategories shows only series-type Dynamic Groups for the authenticated user', function () {
    $seriesMine = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'My Series Trending',
    ]);
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'popular', 'name' => 'My VOD Popular',
    ]);

    Livewire::test(SeriesDynamicGroupsWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$seriesMine])
        ->assertCanNotSeeTableRecords([
            DynamicGroup::where('name', 'My VOD Popular')->first(),
        ]);
});

it('the VOD widget\'s view action links to the DynamicGroupResource view route for the same row', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'View Me',
    ]);

    $expectedUrl = DynamicGroupResource::getUrl('view', ['record' => $group]);

    Livewire::test(VodDynamicGroupsWidget::class)
        ->assertOk()
        ->loadTable()
        // The action URL on the table row points at the same view route the
        // resource exposes — same record key, same resource path.
        ->assertTableActionHasUrl('view', $expectedUrl, $group);
});

it('the Series widget\'s view action links to the DynamicGroupResource view route for the same row', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'View Series Me',
    ]);

    $expectedUrl = DynamicGroupResource::getUrl('view', ['record' => $group]);

    Livewire::test(SeriesDynamicGroupsWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertTableActionHasUrl('view', $expectedUrl, $group);
});

it('the VOD widget exposes no edit / delete / toolbar actions (read-only invariant)', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Mine',
    ]);

    Livewire::test(VodDynamicGroupsWidget::class)
        ->assertOk()
        ->loadTable()
        // Only the `view` action should exist on each row — no `edit`,
        // no `delete`, no `view` group with siblings.
        ->assertTableActionExists('view')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');

    // The group should still exist after rendering the widget (no side effects).
    expect($group->refresh()->exists())->toBeTrue();
});

it('both widgets are registered as footer widgets on their respective list pages', function () {
    // getFooterWidgets() is `protected` on Filament's ListRecords — use reflection
    // so the test doesn't depend on the Filament internal lifecycle (the page
    // class is instantiated directly here, not via Livewire::test()).
    $vodFooter = (new ReflectionMethod(ListVodGroups::class, 'getFooterWidgets'))
        ->invoke(new ListVodGroups);
    $seriesFooter = (new ReflectionMethod(ListCategories::class, 'getFooterWidgets'))
        ->invoke(new ListCategories);

    expect($vodFooter)->toContain(VodDynamicGroupsWidget::class)
        ->and($seriesFooter)->toContain(SeriesDynamicGroupsWidget::class);
});
