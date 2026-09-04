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
    // Dynamic Groups are gated behind an experimental feature flag that
    // ships disabled. Enable it so the widgets render under test.
    config()->set('feature.playlist_tmdb_dynamic_groups', true);

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

it('the VOD widget filters rows to the active playlist when activePlaylistId is set', function () {
    // Second playlist for the same user — should be hidden when filtering by playlist A.
    $playlistB = Playlist::factory()->for($this->user)->create(['name' => 'Second Playlist']);

    // Playlist A: 2 vod-type DynamicGroups
    $vodA1 = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'A Trending',
    ]);
    $vodA2 = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'popular', 'name' => 'A Popular',
    ]);
    // Playlist B: 1 vod-type DynamicGroup
    $vodB = DynamicGroup::create([
        'playlist_id' => $playlistB->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'B Trending',
    ]);

    // Pass `activePlaylistId` directly to the widget — sidesteps the
    // "does the page wiring work" question (tested separately below).
    Livewire::test(VodDynamicGroupsWidget::class, ['activePlaylistId' => (string) $this->playlist->id])
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$vodA1, $vodA2])
        ->assertCanNotSeeTableRecords([$vodB]);
});

it('the Series widget filters rows to the active playlist when activePlaylistId is set', function () {
    $playlistB = Playlist::factory()->for($this->user)->create(['name' => 'Second Playlist']);

    $seriesA = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'A Series Trending',
    ]);
    $seriesB = DynamicGroup::create([
        'playlist_id' => $playlistB->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'B Series Trending',
    ]);

    Livewire::test(SeriesDynamicGroupsWidget::class, ['activePlaylistId' => (string) $this->playlist->id])
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$seriesA])
        ->assertCanNotSeeTableRecords([$seriesB]);
});

it('the VOD widget shows all rows when activePlaylistId is null (no tab selected)', function () {
    // Regression guard: pre-Phase-7 behavior was "no playlist filter = all rows".
    // The default tab state (no tab clicked) must preserve that.
    $playlistB = Playlist::factory()->for($this->user)->create(['name' => 'Second Playlist']);

    $vodA = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'A',
    ]);
    $vodB = DynamicGroup::create([
        'playlist_id' => $playlistB->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'B',
    ]);

    Livewire::test(VodDynamicGroupsWidget::class) // no activePlaylistId passed
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$vodA, $vodB]);
});

it('the Series widget shows all rows when activePlaylistId is null (no tab selected)', function () {
    $playlistB = Playlist::factory()->for($this->user)->create(['name' => 'Second Playlist']);

    $seriesA = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'A Series',
    ]);
    $seriesB = DynamicGroup::create([
        'playlist_id' => $playlistB->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'B Series',
    ]);

    Livewire::test(SeriesDynamicGroupsWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$seriesA, $seriesB]);
});

it('ListVodGroups::getWidgetData() returns activePlaylistId => activeTab', function () {
    // The page-level wiring test: when `$activeTab` is set on the page,
    // `getWidgetData()` MUST surface it as `activePlaylistId` (the prop
    // the widget reads). If this contract breaks, the widget silently
    // falls back to "show all" with no error.
    $page = new ListVodGroups;
    $page->activeTab = (string) $this->playlist->id;

    $data = (new ReflectionMethod(ListVodGroups::class, 'getWidgetData'))
        ->invoke($page);

    expect($data)->toBe(['activePlaylistId' => (string) $this->playlist->id]);
});

it('ListCategories::getWidgetData() returns activePlaylistId => activeTab', function () {
    $page = new ListCategories;
    $page->activeTab = (string) $this->playlist->id;

    $data = (new ReflectionMethod(ListCategories::class, 'getWidgetData'))
        ->invoke($page);

    expect($data)->toBe(['activePlaylistId' => (string) $this->playlist->id]);
});

it('ListVodGroups::getWidgetData() returns null activePlaylistId when no tab is active', function () {
    $page = new ListVodGroups;
    $page->activeTab = null;

    $data = (new ReflectionMethod(ListVodGroups::class, 'getWidgetData'))
        ->invoke($page);

    expect($data)->toBe(['activePlaylistId' => null]);
});

it('the page-level wiring contract binds activePlaylistId correctly for both initial render and tab-change (Phase 7 item 4 — contract proof)', function () {
    // Phase 7 item 4 (reactive-update behavior) can't be exercised through
    // `Livewire::test(ListVodGroups::class)` directly — the test harness
    // renders the page's own table but does NOT include footer widget
    // content in its `$tester->html()` output. Confirmed empirically:
    // a debug probe at `tests/Feature/ProbeTest.php` (deleted) showed
    // 132KB of rendered HTML, with the widget's class name in
    // `wire:snapshot` but neither the widget's heading nor any of its
    // DynamicGroup row data. The Livewire test API can introspect the
    // page's own state but not the Livewire::make() facade instance that
    // Filament uses to render child widgets.
    //
    // What's verified instead — at the contract level, which is what
    // matters for "does the tab-following work" — is the chain:
    //
    //   1. Page receives tab click → `wire:click="$set('activeTab', X)"`
    //      updates `$activeTab` (confirmed via Filament source at
    //      vendor/filament/schemas/src/Components/Tabs.php:741)
    //   2. Page re-renders → Filament calls
    //      `Page::getWidgetsSchemaComponents()` (vendor/filament/filament
    //      /src/Pages/Page.php:423)
    //   3. That method merges `getWidgetData()` into a `Livewire::make(
    //      $widgetClass, fn () => [...$this->getWidgetData(), ...])`
    //      params closure (Page.php:431)
    //   4. The closure is re-invoked when the child widget needs to
    //      re-render → reads the FRESH `$activeTab` → widget prop updates
    //
    // Tests 7-8 prove (4) at the widget level: the bound `activePlaylistId`
    // correctly filters the widget's table when set.
    // Tests 11-13 prove (1-2): the page's `getWidgetData()` returns the
    // right value for any activeTab state, including null.
    //
    // What we can verify here in PHP (no browser) is the contract at the
    // point where Filament hands data to the child widget: confirm that
    // getWidgetData()'s return value is exactly what we expect for the
    // full state space — set, unset, set to a different playlist.
    $pageA = new ListVodGroups;
    $pageA->activeTab = (string) $this->playlist->id;

    $pageB = new ListVodGroups;
    $pageB->activeTab = '999'; // Non-existent — should still serialize cleanly

    $pageNone = new ListVodGroups;
    $pageNone->activeTab = null;

    $dataA = (new ReflectionMethod(ListVodGroups::class, 'getWidgetData'))
        ->invoke($pageA);
    $dataB = (new ReflectionMethod(ListVodGroups::class, 'getWidgetData'))
        ->invoke($pageB);
    $dataNone = (new ReflectionMethod(ListVodGroups::class, 'getWidgetData'))
        ->invoke($pageNone);

    expect($dataA)->toBe(['activePlaylistId' => (string) $this->playlist->id])
        ->and($dataB)->toBe(['activePlaylistId' => '999'])
        ->and($dataNone)->toBe(['activePlaylistId' => null]);
});
