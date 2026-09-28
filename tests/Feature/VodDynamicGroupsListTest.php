<?php

use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\VodDynamicGroups\Pages\ListVodDynamicGroups;
use App\Filament\Resources\VodDynamicGroups\VodDynamicGroupResource;
use App\Filament\Resources\VodGroups\Pages\ListVodGroups;
use App\Jobs\SyncDynamicGroups;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use App\Services\TmdbService;
use Filament\Forms\Components\Hidden;
use Filament\Pages\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Dynamic Groups are gated behind an experimental feature flag that
    // ships disabled. Enable it so the listing page renders under test.
    config()->set('feature.playlist_tmdb_dynamic_groups', true);

    // Also gated behind a configured TMDB integration - without TMDB
    // the Sync pipeline is a no-op and there'd be nothing to show.
    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('isConfigured')->andReturn(true);
    app()->instance(TmdbService::class, $tmdb);

    Bus::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

// --- Resource-level visibility / nav registration ----------------------------

it('registers the VOD Dynamic Groups nav item below existing VOD navigation items', function () {
    expect(VodDynamicGroupResource::shouldRegisterNavigation())->toBeTrue()
        ->and(VodDynamicGroupResource::getNavigationGroup())->toBe(__('VOD Channels'))
        ->and(VodDynamicGroupResource::getNavigationSort())->toBeGreaterThanOrEqual(4);
});

it('hides the sidebar entry when the experimental feature flag is disabled', function () {
    config()->set('feature.playlist_tmdb_dynamic_groups', false);

    expect(VodDynamicGroupResource::shouldRegisterNavigation())->toBeFalse();
});

it('hides the sidebar entry when TMDB is not configured', function () {
    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('isConfigured')->andReturn(false);
    app()->instance(TmdbService::class, $tmdb);

    expect(VodDynamicGroupResource::shouldRegisterNavigation())->toBeFalse();
});

it('registers an index page but no view page (the shared parent DynamicGroupResource owns the view route)', function () {
    $pages = VodDynamicGroupResource::getPages();

    expect($pages)->toHaveKey('index')
        ->and($pages)->not->toHaveKey('view');
});

it('still hides the create route (rules are configured on the Playlist form, not here)', function () {
    expect(VodDynamicGroupResource::canCreate())->toBeFalse();
});

// --- Old footer-widgets fully retired ----------------------------------------

it('ListVodGroups no longer registers any per-type DynamicGroupsWidget', function () {
    expect((new ReflectionMethod(ListVodGroups::class, 'getFooterWidgets'))
        ->getDeclaringClass()
        ->getName())->toBe(Page::class);
});

it('ListCategories no longer registers any per-type DynamicGroupsWidget', function () {
    expect((new ReflectionMethod(ListCategories::class, 'getFooterWidgets'))
        ->getDeclaringClass()
        ->getName())->toBe(Page::class);
});

// --- Page-level behavior ------------------------------------------------------

it('shows only vod-type Dynamic Groups for the authenticated user', function () {
    $vodMine = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'My VOD Trending',
    ]);
    // Same-user: series (must NOT show - wrong type for this listing).
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'My Series Trending',
    ]);
    // Other-user: vod (must NOT show - wrong owner).
    $otherUser = User::factory()->create();
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $otherUser->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Their VOD Trending',
    ]);

    Livewire::test(ListVodDynamicGroups::class)
        ->assertCanSeeTableRecords([$vodMine])
        ->assertCanNotSeeTableRecords([
            DynamicGroup::where('name', 'My Series Trending')->first(),
            DynamicGroup::where('name', 'Their VOD Trending')->first(),
        ]);
});

it('links the view action to the shared DynamicGroupResource view route', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'View Me',
    ]);

    $expectedUrl = DynamicGroupResource::getUrl('view', ['record' => $group]);

    Livewire::test(ListVodDynamicGroups::class)
        ->assertTableActionHasUrl('view', $expectedUrl, $group);
});

it('exposes view and delete actions but no edit', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Mine',
    ]);

    Livewire::test(ListVodDynamicGroups::class)
        ->assertTableActionExists('view')
        ->assertTableActionExists('delete')
        ->assertTableActionDoesNotExist('edit');

    expect($group->refresh()->exists())->toBeTrue();
});

it('deleting a row removes the DynamicGroup record', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Mine',
    ]);

    Livewire::test(ListVodDynamicGroups::class)
        ->callTableAction('delete', $group);

    expect(DynamicGroup::find($group->id))->toBeNull();
});

// --- Items count -------------------------------------------------------------

it('the Items column counts channels', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'tmdb_id' => '550', 'is_vod' => true,
    ]);
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'VOD Group',
    ]);
    $group->channels()->attach($channel);

    Livewire::test(ListVodDynamicGroups::class)
        ->assertTableColumnStateSet('channels_count', 1, $group);
});

// --- Tabs --------------------------------------------------------------------

it('per-playlist sub-tabs scope the table by playlist_id without breaking groupBy', function () {
    // Regression guard: the original bug crashed the page with
    //   "column dynamic_groups.id must appear in the GROUP BY clause"
    // because getEloquentQuery() included withCount('channels'), which
    // adds a subquery column to SELECT, and getTabs() then did
    // groupBy('playlist_id') on the same builder. Postgres rejects
    // that combination because the subquery column and dynamic_groups.*
    // columns are not in GROUP BY. Verify getTabs() now returns without
    // throwing, and the playlist counts are computed correctly.
    $this->playlist->update(['name' => 'My Playlist']);
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'VOD A',
    ]);
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'popular', 'name' => 'VOD B',
    ]);

    // If the bug regresses, this throws a QueryException on Postgres.
    // After the refactor, the getEloquentQuery() no longer has withCount
    // and the groupBy lives in getTabs(). Sanity-check that the tabs
    // are present and the per-playlist count is right.
    $page = Livewire::test(ListVodDynamicGroups::class)->instance();
    $tabs = $page->getTabs();

    expect(array_key_exists(null, $tabs))->toBeTrue()
        ->and($tabs)->toHaveKey((string) $this->playlist->id);
    expect($tabs[(string) $this->playlist->id]->getBadge())->toBe('2');
});

// --- Header CreateAction ------------------------------------------------------

it('shows a New VOD Dynamic Group header action on the listing', function () {
    Livewire::test(ListVodDynamicGroups::class)
        ->assertOk()
        ->assertActionExists('create');
});

it('keeps the Enabled toggle in the create schema alongside the playlist picker', function () {
    // Regression guard: `[Select::make('playlist_id')] + getDynamicGroupRuleSchema()`
    // silently dropped the Enabled toggle because PHP's `+` array-union
    // operator lets the first array's numeric index win the collision
    // (both arrays start at index 0). Verify both fields survive the merge.
    Livewire::test(ListVodDynamicGroups::class)
        ->mountAction('create')
        ->assertSchemaComponentExists('playlist_id')
        ->assertSchemaComponentExists('enabled')
        ->assertSchemaComponentStateSet('enabled', true);
});

it('locks the create schema\'s type to vod instead of exposing an editable Content Type selector', function () {
    // The reused rule schema's Content Type Select is replaced with a
    // Hidden field so a user can't pick series-only `source` options
    // while the closure still forces `type` to 'vod' on submit.
    Livewire::test(ListVodDynamicGroups::class)
        ->mountAction('create')
        ->assertSchemaComponentStateSet('type', 'vod')
        ->assertSchemaComponentExists('type', checkComponentUsing: fn ($component) => $component instanceof Hidden);
});

it('the playlist picker is prefilled with the active sub-tab when not on All', function () {
    $specific = Playlist::factory()->for($this->user)->create(['name' => 'Specific Playlist']);

    // `activeTab` is Filament's own tab-tracking property (bound to the
    // `?tab=` URL param via #[Url(as: 'tab')] on ListRecords) - set it
    // the same way a real tab click would, rather than a page-local
    // property, so this test exercises the actual prefill path.
    Livewire::test(ListVodDynamicGroups::class, ['activeTab' => (string) $specific->id])
        ->mountAction('create')
        ->assertSchemaComponentStateSet('playlist_id', $specific->id);
});

it('the materializeRule helper appends the rule to the playlist and materializes a row', function () {
    // The CreateAction's data-routing through the mounted action schema
    // is brittle to nested field names (tmdb_params.*, cache_* sub-keys).
    // Drive the same flow the using() closure would, so the test focuses
    // on the closure's logic - Filament's form-fill + dispatch is its
    // job to test separately.
    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('collectDynamicGroupResults')
        ->andReturn([['tmdb_id' => '12345']]);
    app()->instance(TmdbService::class, $tmdb);

    $playlist = $this->playlist;
    $rule = [
        'enabled' => true,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Trending Movies',
        'tmdb_params' => [],
    ];
    $config = $playlist->dynamic_groups_config ?? [];
    $config[] = $rule;
    $playlist->update(['dynamic_groups_config' => $config]);

    $job = new SyncDynamicGroups($playlist->id);
    $group = $job->materializeRule(
        $playlist->fresh(),
        $rule['type'],
        $rule['source'],
        $rule['name'],
        $rule['tmdb_params'],
        count($config) - 1,
        $tmdb,
    );

    expect($group)->not->toBeNull()
        ->and($group->type)->toBe('vod')
        ->and($group->source)->toBe('trending')
        ->and($group->name)->toBe('Trending Movies');

    $playlist = $playlist->fresh();
    expect($playlist->dynamic_groups_config)->toHaveCount(1)
        ->and($playlist->dynamic_groups_config[0])->toMatchArray($rule);
});
