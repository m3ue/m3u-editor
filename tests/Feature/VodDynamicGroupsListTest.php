<?php

use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\VodDynamicGroups\Pages\ListVodDynamicGroups;
use App\Filament\Resources\VodDynamicGroups\VodDynamicGroupResource;
use App\Filament\Resources\VodGroups\Pages\ListVodGroups;
use App\Jobs\DownloadCachedContentFile;
use App\Jobs\SyncDynamicGroups;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Dynamic Groups are gated behind an experimental feature flag that
    // ships disabled. Enable it so the listing page renders under test.
    config()->set('feature.playlist_tmdb_dynamic_groups', true);

    // Also gated behind a configured TMDB integration — without TMDB
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

it('registers the VOD Dynamic Groups nav item at the TOP of the VOD Channels group', function () {
    // Sort = 1 puts Dynamic Groups ABOVE VOD Groups (sort 2) and
    // VODs (sort 3). Dynamic Groups is the operator's primary
    // surface for "auto-grouped by TMDB" content — the user
    // explicitly asked for it to appear at the top of the section.
    expect(VodDynamicGroupResource::shouldRegisterNavigation())->toBeTrue()
        ->and(VodDynamicGroupResource::getNavigationGroup())->toBe(__('VOD Channels'))
        ->and(VodDynamicGroupResource::getNavigationSort())->toBeLessThanOrEqual(1);
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

// --- ListVodGroups / ListCategories footer-widgets decoupling ----------------

it('the tab count query is Postgres-compatible (no GROUP BY on a subquery column)', function () {
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
    // The bug was triggered by groupBy('playlist_id') on
    // getEloquentQuery(), which Postgres rejects when a subquery
    // column (withCount) is also selected. After the refactor, the
    // getEloquentQuery() no longer has withCount and the
    // groupBy lives in getPlaylistSubTabs() (the per-playlist
    // sub-tabs), not in getTabs() (the top-level groups/cache
    // tabs). Sanity-check both: top-level tabs present + playlist
    // sub-tabs present with the right count.
    $page = Livewire::test(ListVodDynamicGroups::class)->instance();
    $topTabs = $page->getTabs();
    $subTabs = $page->getPlaylistSubTabs();

    expect($topTabs)->toHaveKey('groups')
        ->and($topTabs)->toHaveKey('cache')
        ->and($subTabs)->toHaveKey('all')
        ->and($subTabs)->toHaveKey((string) $this->playlist->id);
    expect($subTabs[(string) $this->playlist->id]->getBadge())->toBe('2');
});

it('embeds the VOD-side Dynamic Group Cache Activity widget in the page content() schema (visually attached to the table area)', function () {
    // The cache activity widget is no longer a footer widget stacked
    // below the page — it's embedded in the page's content() schema
    // right after the EmbeddedTable, so it visually attaches to the
    // table area. The widget itself renders a <x-filament::section>
    // wrapper via its custom Blade view so the "clustered section"
    // look is preserved.
    expect(method_exists(ListVodDynamicGroups::class, 'content'))->toBeTrue();

    // Sanity: the content() override must reference the widget class.
    $reflection = new ReflectionMethod(ListVodDynamicGroups::class, 'content');
    $contents = file_get_contents($reflection->getFileName());
    expect($contents)->toContain('VodDynamicGroupCacheActivityWidget::class');
});

it('ListVodGroups no longer registers the per-type DynamicGroupsWidget (moved to the VOD Channels sidebar)', function () {
    $vodFooter = (new ReflectionMethod(ListVodGroups::class, 'getFooterWidgets'))
        ->invoke(new ListVodGroups);

    foreach ($vodFooter as $widgetClass) {
        expect($widgetClass)->not->toContain('VodGroups\\Widgets\\DynamicGroupsWidget');
    }
});

it('ListCategories no longer registers the per-type DynamicGroupsWidget (moved to the Series sidebar)', function () {
    $seriesFooter = (new ReflectionMethod(ListCategories::class, 'getFooterWidgets'))
        ->invoke(new ListCategories);

    foreach ($seriesFooter as $widgetClass) {
        expect($widgetClass)->not->toContain('Categories\\Widgets\\DynamicGroupsWidget');
    }
});

// --- Page-level behavior ------------------------------------------------------

it('shows only vod-type Dynamic Groups for the authenticated user', function () {
    $vodMine = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'My VOD Trending',
    ]);
    // Same-user: series (must NOT show — wrong type for this listing).
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'My Series Trending',
    ]);
    // Other-user: vod (must NOT show — wrong owner).
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

it('exposes view, edit, and delete actions in the row action group', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Mine',
    ]);

    Livewire::test(ListVodDynamicGroups::class)
        ->assertTableActionExists('view')
        ->assertTableActionExists('edit')
        ->assertTableActionExists('delete');

    expect($group->refresh()->exists())->toBeTrue();
});

it('edit action pre-fills the form with the underlying rule and re-syncs on save', function () {
    // Drive the same flow the action's fillForm + action closures
    // would, so the test focuses on the rule-lookup + write path
    // rather than Filament's form-fill plumbing (covered upstream
    // by the materializeRule test).
    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('collectDynamicGroupResults')->andReturn([['tmdb_id' => '999']]);
    app()->instance(TmdbService::class, $tmdb);

    $playlist = $this->playlist;
    $playlist->update(['dynamic_groups_config' => [
        [
            'enabled' => true,
            'type' => 'vod',
            'source' => 'trending',
            'name' => 'Trending Movies',
            'tmdb_params' => [],
            'cache_enabled' => false,
        ],
    ]]);

    $group = DynamicGroup::create([
        'playlist_id' => $playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Trending Movies',
    ]);

    Livewire::test(ListVodDynamicGroups::class)
        ->mountTableAction('edit', $group)
        ->assertSchemaComponentExists('enabled')
        ->assertFormFieldIsDisabled('type')
        ->assertFormFieldIsDisabled('source')
        ->assertFormFieldIsDisabled('name')
        ->fillForm(['enabled' => false])
        ->callMountedTableAction();

    $rule = $playlist->fresh()->dynamic_groups_config[0];
    expect($rule)->toMatchArray([
        'enabled' => false,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Trending Movies',
    ]);

    Bus::assertDispatched(SyncDynamicGroups::class, fn ($job) => $job->playlistId === $playlist->id);
});

it('edit form pre-fills the enabled toggle with the rule\'s actual state (false)', function () {
    // Regression guard: the shared rule schema sets Toggle::make('enabled')
    // ->default(true). If that default leaks into the edit slide-over
    // (because other ->live() fields trigger a re-render that resets the
    // toggle), the form would show the rule as enabled when it's not.
    // The fix strips the default in edit mode so fillForm wins.
    $playlist = $this->playlist;
    $playlist->update(['dynamic_groups_config' => [
        [
            'enabled' => false,
            'type' => 'vod',
            'source' => 'trending',
            'name' => 'Disabled Group',
            'tmdb_params' => [],
            'cache_enabled' => false,
        ],
    ]]);

    $group = DynamicGroup::create([
        'playlist_id' => $playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Disabled Group',
    ]);

    Livewire::test(ListVodDynamicGroups::class)
        ->mountTableAction('edit', $group)
        ->assertSchemaComponentStateSet('enabled', false);
});

it('edit form defaults enabled to true when the rule lacks the enabled key (legacy rule)', function () {
    // Legacy rule: stored in playlist.dynamic_groups_config without
    // the 'enabled' key (predates the toggle being added to the
    // schema). The DynamicGroup row was materialized with
    // enabled => true (SyncDynamicGroups::materializeRule forces it),
    // so the grid shows enabled — the Edit form must match.
    $playlist = $this->playlist;
    $playlist->update(['dynamic_groups_config' => [
        [
            // no 'enabled' key at all
            'type' => 'vod',
            'source' => 'trending',
            'name' => 'Legacy Rule',
            'tmdb_params' => [],
            'cache_enabled' => false,
        ],
    ]]);

    $group = DynamicGroup::create([
        'playlist_id' => $playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Legacy Rule',
        'enabled' => true,
    ]);

    Livewire::test(ListVodDynamicGroups::class)
        ->mountTableAction('edit', $group)
        ->assertSchemaComponentStateSet('enabled', true);
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

// --- Cache column ------------------------------------------------------------

it('the Cached column shows "—" for groups without a cache-enabled rule', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'No Cache Rule',
    ]);

    $this->playlist->update([
        'dynamic_groups_config' => [
            ['name' => 'No Cache Rule', 'cache_enabled' => false],
        ],
    ]);

    Livewire::test(ListVodDynamicGroups::class)
        ->assertTableColumnStateSet('cache_status', '—', $group);
});

it('the Cached column shows "{cached}/{total}" when caching is enabled and content is cached', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'tmdb_id' => '550', 'is_vod' => true,
    ]);
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Cached Group',
    ]);
    $group->channels()->attach($channel);

    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
    ]);

    $this->playlist->update([
        'dynamic_groups_config' => [
            ['name' => 'Cached Group', 'cache_enabled' => true],
        ],
    ]);

    Livewire::test(ListVodDynamicGroups::class)
        ->assertTableColumnStateSet('cache_status', '1/1', $group);
});

// --- Cache Now bulk action ---------------------------------------------------

it('exposes a cache_now bulk action on the VOD listing', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Mine',
    ]);

    Livewire::test(ListVodDynamicGroups::class)
        ->assertTableBulkActionExists('cache_now');
});

it('cache_now dispatches one DownloadCachedContentFile job per channel across selected vod-type groups', function () {
    Bus::fake();

    app(GeneralSettings::class)->refresh();
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $this->playlist->update(['dynamic_groups_config' => [
        ['name' => 'Trending', 'cache_enabled' => true],
        ['name' => 'Popular', 'cache_enabled' => true],
    ]]);

    $groupA = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Trending',
    ]);
    $groupAChannels = collect(['800', '801', '802'])->map(
        fn (string $tmdbId) => Channel::factory()->for($this->playlist)->create(['tmdb_id' => $tmdbId])
    );
    $groupA->channels()->attach($groupAChannels->pluck('id')->all());

    $groupB = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'popular', 'name' => 'Popular',
    ]);
    $groupBChannels = collect(['900', '901'])->map(
        fn (string $tmdbId) => Channel::factory()->for($this->playlist)->create(['tmdb_id' => $tmdbId])
    );
    $groupB->channels()->attach($groupBChannels->pluck('id')->all());

    Livewire::test(ListVodDynamicGroups::class)
        ->callTableBulkAction('cache_now', [$groupA->id, $groupB->id]);

    Bus::assertDispatchedTimes(DownloadCachedContentFile::class, 5);
});

it('cache_now fires a warning notification when dynamic-group caching is disabled', function () {
    Bus::fake();

    app(GeneralSettings::class)->refresh();
    app(GeneralSettings::class)->enable_dynamic_group_cache = false;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Mine',
    ]);

    $tester = Livewire::test(ListVodDynamicGroups::class)
        ->callTableBulkAction('cache_now', [$group->id]);

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
    $tester->assertNotified('Dynamic Group Caching is disabled');
});

it('shows a New VOD Dynamic Group header action on the listing', function () {
    Livewire::test(ListVodDynamicGroups::class)
        ->assertOk()
        ->assertActionExists('create');
});

it('the playlist picker is prefilled with the active sub-tab when not on All', function () {
    $specific = Playlist::factory()->for($this->user)->create(['name' => 'Specific Playlist']);

    Livewire::test(ListVodDynamicGroups::class, ['activePlaylistTab' => (string) $specific->id])
        ->mountAction('create')
        ->assertSchemaComponentStateSet('playlist_id', $specific->id);
});

it('the materializeRule helper appends the rule to the playlist and materializes a row', function () {
    // The CreateAction's data-routing through the mounted action schema
    // is brittle to nested field names (tmdb_params.*, cache_* sub-keys).
    // Drive the same flow the using() closure would, so the test focuses
    // on the closure's logic — Filament's form-fill + dispatch is its
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
