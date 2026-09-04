<?php

use App\Enums\SyncRunPhase;
use App\Enums\SyncRunStatus;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\DynamicGroups\Pages\ViewDynamicGroup;
use App\Filament\Resources\DynamicGroups\RelationManagers\ChannelsRelationManager;
use App\Filament\Resources\DynamicGroups\RelationManagers\SeriesRelationManager;
use App\Filament\Resources\VodGroups\VodGroupResource;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\DynamicGroupItemSnapshot;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Dynamic Groups are gated behind an experimental feature flag that
    // ships disabled. Enable it so the resource is accessible under test.
    config()->set('feature.playlist_tmdb_dynamic_groups', true);

    // Playlist creation fires PlaylistListener → SyncPipelineService → Redis.
    // Fake the bus so we don't need a live Redis during unit tests.
    Bus::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();

    // The Playlist model's `xtreamStatus` accessor hits Redis via Cache::get
    // and dispatches UpdateXtreamStats::dispatch() on miss. The view page's
    // render touches this accessor, so pre-seed the cache key with an empty
    // array so the accessor early-returns without a live Redis hit.
    Cache::put("p:{$this->playlist->id}:xtream_status", [], 60);
});

it('registers only the view page (no index) and stays out of nav', function () {
    // The standalone index was removed because its auto-generated breadcrumb
    // off the per-playlist widgets landed users on a list page they never
    // asked to be on. The view page is reachable via the widget row's
    // view action (or a click anywhere on the row), with a breadcrumb that
    // chains through the type-appropriate VodGroupResource/CategoryResource
    // index instead of the (now-missing) index or the owning playlist.
    $pages = DynamicGroupResource::getPages();

    expect(DynamicGroupResource::canCreate())->toBeFalse()
        ->and(DynamicGroupResource::shouldRegisterNavigation())->toBeFalse()
        ->and($pages)->toHaveKey('view')
        ->and($pages)->not->toHaveKey('index');
});

it('scopes the eloquent query to the current user when not an admin', function () {
    // Another user's row on the same playlist - forced via raw user_id so
    // it bypasses Playlist ownership constraints. Must NOT leak through.
    $other = User::factory()->create();
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $other->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Theirs',
    ]);
    $mine = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Mine',
    ]);

    // Authenticate as the non-admin user; the resource query must filter.
    $this->actingAs($this->user);

    $ids = DynamicGroupResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($mine->id)
        ->and($ids)->not->toContain($other->id);
});

it('labels common TMDB source slugs', function () {
    expect(DynamicGroupResource::sourceLabelFor('vod')['trending'])->toBe(__('Trending'))
        ->and(DynamicGroupResource::sourceLabelFor('vod')['now_playing'])->toBe(__('In Theatres'))
        ->and(DynamicGroupResource::sourceLabelFor('series')['tmdb_network'])->toBe(__('By TV Network'));

    // Unknown types fall through to the vod map.
    expect(DynamicGroupResource::sourceLabelFor('bogus'))->toBe(DynamicGroupResource::sourceLabelFor('vod'));
});

it('formats tmdb_params as a human-readable key=value list', function () {
    // Empty / missing → placeholder string.
    expect(DynamicGroupResource::formatTmdbParams([]))->toBe('-');

    // Plain values pass through unchanged.
    expect(DynamicGroupResource::formatTmdbParams(['pages' => 5]))
        ->toBe('pages = 5');

    // Array values are comma-joined.
    expect(DynamicGroupResource::formatTmdbParams(['with_genres' => [28, 12]]))
        ->toBe('with_genres = 28, 12');

    // Known network_id resolves to the human name (TMDB canonical list
    // lives in TmdbService::TV_NETWORKS).
    expect(DynamicGroupResource::formatTmdbParams(['network_id' => 213]))
        ->toContain('Netflix')
        ->toContain('213');
});

it('shows the last sync diff inline on the View page with +added/-removed chips', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Trending Now',
    ]);

    $kept = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true, 'enabled' => true, 'title' => 'Kept Title',
    ]);
    $added = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true, 'enabled' => true, 'title' => 'Added Title',
    ]);

    $run1 = SyncRun::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'phases' => [SyncRunPhase::DynamicGroups->value],
        'status' => SyncRunStatus::Completed->value,
    ]);
    DynamicGroupItemSnapshot::insert([
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run1->id, 'item_type' => Channel::class, 'item_id' => $kept->id, 'captured_at' => now()],
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run1->id, 'item_type' => Channel::class, 'item_id' => 999, 'captured_at' => now()],
    ]);

    $run2 = SyncRun::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'phases' => [SyncRunPhase::DynamicGroups->value],
        'status' => SyncRunStatus::Completed->value,
    ]);
    DynamicGroupItemSnapshot::insert([
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run2->id, 'item_type' => Channel::class, 'item_id' => $kept->id, 'captured_at' => now()],
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run2->id, 'item_type' => Channel::class, 'item_id' => $added->id, 'captured_at' => now()],
    ]);

    Livewire::test(ViewDynamicGroup::class, ['record' => $group->id])
        ->assertOk()
        ->assertSee('+1')
        ->assertSee('-1')
        ->assertSee('Added Title');
});

it('does not leak diff info when the owning SyncRun belongs to another user', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Trending Now',
    ]);

    $otherUser = User::factory()->create();
    $run = SyncRun::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $otherUser->id,
        'phases' => [SyncRunPhase::DynamicGroups->value],
        'status' => SyncRunStatus::Completed->value,
    ]);
    DynamicGroupItemSnapshot::insert([
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run->id, 'item_type' => Channel::class, 'item_id' => 1, 'captured_at' => now()],
        ['dynamic_group_id' => $group->id, 'sync_run_id' => $run->id, 'item_type' => Channel::class, 'item_id' => 2, 'captured_at' => now()],
    ]);

    Livewire::test(ViewDynamicGroup::class, ['record' => $group->id])
        ->assertOk()
        ->assertDontSee('+1');
});

it('shows the Movies relation manager tab only on vod-type dynamic groups', function () {
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

it('lists the real synced dynamic_group_items members on the Movies relation manager table', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Trending Now',
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
        'type' => 'series', 'source' => 'trending', 'name' => 'Trending Series',
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

it('chains the View page breadcrumb through VodGroupResource for vod-type groups', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Trending Now',
    ]);

    $breadcrumbs = Livewire::test(ViewDynamicGroup::class, ['record' => $group->id])
        ->assertOk()
        ->instance()
        ->getBreadcrumbs();

    expect($breadcrumbs)->toHaveKey(VodGroupResource::getUrl('index'))
        ->and($breadcrumbs[VodGroupResource::getUrl('index')])->toBe('Groups')
        ->and($breadcrumbs)->toContain('Dynamic')
        ->and($breadcrumbs)->toContain('Trending Now')
        ->and($breadcrumbs)->not->toContain('Dynamic Groups')
        ->and($breadcrumbs)->not->toHaveKey(CategoryResource::getUrl('index'));
});

it('chains the View page breadcrumb through CategoryResource for series-type groups', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Trending Series',
    ]);

    $breadcrumbs = Livewire::test(ViewDynamicGroup::class, ['record' => $group->id])
        ->assertOk()
        ->instance()
        ->getBreadcrumbs();

    expect($breadcrumbs)->toHaveKey(CategoryResource::getUrl('index'))
        ->and($breadcrumbs[CategoryResource::getUrl('index')])->toBe('Categories')
        ->and($breadcrumbs)->toContain('Dynamic')
        ->and($breadcrumbs)->toContain('Trending Series')
        ->and($breadcrumbs)->not->toHaveKey(VodGroupResource::getUrl('index'));
});

it('the View page\'s back action points at Groups for vod-type and Categories for series-type', function () {
    $vodGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'VOD Group',
    ]);
    $seriesGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Series Group',
    ]);

    Livewire::test(ViewDynamicGroup::class, ['record' => $vodGroup->id])
        ->assertOk()
        ->assertSee('Back to Groups')
        ->assertDontSee('Back to Categories');

    Livewire::test(ViewDynamicGroup::class, ['record' => $seriesGroup->id])
        ->assertOk()
        ->assertSee('Back to Categories')
        ->assertDontSee('Back to Groups');
});

it('deletes the DynamicGroup and cascades its dynamic_group_items when the View page delete action runs', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Trending Now',
    ]);
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create(['is_vod' => true]);
    DB::table('dynamic_group_items')->insert([
        'dynamic_group_id' => $group->id,
        'item_type' => Channel::class,
        'item_id' => $channel->id,
    ]);

    Livewire::test(ViewDynamicGroup::class, ['record' => $group->id])
        ->assertOk()
        ->callAction('delete');

    expect(DynamicGroup::find($group->id))->toBeNull()
        ->and(DB::table('dynamic_group_items')->where('dynamic_group_id', $group->id)->exists())->toBeFalse();
});
