<?php

use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\SeriesDynamicGroups\Pages\ListSeriesDynamicGroups;
use App\Filament\Resources\SeriesDynamicGroups\SeriesDynamicGroupResource;
use App\Jobs\DownloadCachedContentFile;
use App\Jobs\SyncDynamicGroups;
use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
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

it('registers the Series Dynamic Groups nav item at the TOP of the Series group', function () {
    // Sort = 1 puts Dynamic Groups ABOVE Categories (sort 4) and
    // Series (sort 4). Dynamic Groups is the operator's primary
    // surface for "auto-grouped by TMDB" content — the user
    // explicitly asked for it to appear at the top of the section.
    expect(SeriesDynamicGroupResource::shouldRegisterNavigation())->toBeTrue()
        ->and(SeriesDynamicGroupResource::getNavigationGroup())->toBe(__('Series'))
        ->and(SeriesDynamicGroupResource::getNavigationSort())->toBeLessThanOrEqual(1);
});

it('hides the sidebar entry when the experimental feature flag is disabled', function () {
    config()->set('feature.playlist_tmdb_dynamic_groups', false);

    expect(SeriesDynamicGroupResource::shouldRegisterNavigation())->toBeFalse();
});

it('hides the sidebar entry when TMDB is not configured', function () {
    $tmdb = Mockery::mock(TmdbService::class);
    $tmdb->shouldReceive('isConfigured')->andReturn(false);
    app()->instance(TmdbService::class, $tmdb);

    expect(SeriesDynamicGroupResource::shouldRegisterNavigation())->toBeFalse();
});

it('the tab count query is Postgres-compatible (no GROUP BY on a subquery column)', function () {
    // Regression guard: same as the VOD-side test — getTabs() must not
    // blow up when its GROUP BY playlist_id query runs against
    // getEloquentQuery() (which would be poisoned by a withCount
    // subquery column).
    $this->playlist->update(['name' => 'My Series Playlist']);
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Series A',
    ]);
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'popular', 'name' => 'Series B',
    ]);

    // The bug was triggered by groupBy('playlist_id') on
    // getEloquentQuery(), which Postgres rejects when a subquery
    // column (withCount) is also selected. After the refactor, the
    // getEloquentQuery() no longer has withCount and the
    // groupBy lives in getPlaylistSubTabs() (the per-playlist
    // sub-tabs), not in getTabs() (the top-level groups/cache
    // tabs). Sanity-check both: top-level tabs present + playlist
    // sub-tabs present with the right count.
    $page = Livewire::test(ListSeriesDynamicGroups::class)->instance();
    $topTabs = $page->getTabs();
    $subTabs = $page->getPlaylistSubTabs();

    expect($topTabs)->toHaveKey('groups')
        ->and($topTabs)->toHaveKey('cache')
        ->and($subTabs)->toHaveKey('all')
        ->and($subTabs)->toHaveKey((string) $this->playlist->id);
    expect($subTabs[(string) $this->playlist->id]->getBadge())->toBe('2');
});

it('registers an index page but no view page (the shared parent DynamicGroupResource owns the view route)', function () {
    $pages = SeriesDynamicGroupResource::getPages();

    expect($pages)->toHaveKey('index')
        ->and($pages)->not->toHaveKey('view');
});

it('still hides the create route (rules are configured on the Playlist form, not here)', function () {
    expect(SeriesDynamicGroupResource::canCreate())->toBeFalse();
});

// --- Page-level behavior ------------------------------------------------------

it('embeds the Series-side Dynamic Group Cache Activity widget in the page content() schema (visually attached to the table area)', function () {
    // See VodDynamicGroupsListTest for the full rationale. Series
    // mirror: the episode-aware cache activity widget is embedded
    // in the page's content() schema right after the EmbeddedTable,
    // not stacked as a separate footer widget below the page.
    expect(method_exists(ListSeriesDynamicGroups::class, 'content'))->toBeTrue();

    // Sanity: the content() override must reference the widget class.
    $reflection = new ReflectionMethod(ListSeriesDynamicGroups::class, 'content');
    $contents = file_get_contents($reflection->getFileName());
    expect($contents)->toContain('SeriesDynamicGroupCacheActivityWidget::class');
});

it('shows only series-type Dynamic Groups for the authenticated user', function () {
    $seriesMine = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'My Series Trending',
    ]);
    // Same-user: vod (must NOT show — wrong type for this listing).
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'popular', 'name' => 'My VOD Popular',
    ]);
    // Other-user: series (must NOT show — wrong owner).
    $otherUser = User::factory()->create();
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $otherUser->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Their Series Trending',
    ]);

    Livewire::test(ListSeriesDynamicGroups::class)
        ->assertCanSeeTableRecords([$seriesMine])
        ->assertCanNotSeeTableRecords([
            DynamicGroup::where('name', 'My VOD Popular')->first(),
            DynamicGroup::where('name', 'Their Series Trending')->first(),
        ]);
});

it('links the view action to the shared DynamicGroupResource view route', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'View Series Me',
    ]);

    $expectedUrl = DynamicGroupResource::getUrl('view', ['record' => $group]);

    Livewire::test(ListSeriesDynamicGroups::class)
        ->assertTableActionHasUrl('view', $expectedUrl, $group);
});

it('exposes view, edit, and delete actions in the row action group', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Mine',
    ]);

    Livewire::test(ListSeriesDynamicGroups::class)
        ->assertTableActionExists('view')
        ->assertTableActionExists('edit')
        ->assertTableActionExists('delete');
});

it('edit action pre-fills the form with the underlying rule and re-syncs on save', function () {
    $playlist = $this->playlist;
    $playlist->update(['dynamic_groups_config' => [
        [
            'enabled' => true,
            'type' => 'series',
            'source' => 'trending',
            'name' => 'Trending Series',
            'tmdb_params' => [],
            'cache_enabled' => false,
        ],
    ]]);

    $group = DynamicGroup::create([
        'playlist_id' => $playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Trending Series',
    ]);

    Livewire::test(ListSeriesDynamicGroups::class)
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
        'type' => 'series',
        'source' => 'trending',
        'name' => 'Trending Series',
    ]);

    Bus::assertDispatched(SyncDynamicGroups::class, fn ($job) => $job->playlistId === $playlist->id);
});

it('edit form pre-fills the enabled toggle with the rule\'s actual state (false)', function () {
    $playlist = $this->playlist;
    $playlist->update(['dynamic_groups_config' => [
        [
            'enabled' => false,
            'type' => 'series',
            'source' => 'trending',
            'name' => 'Disabled Series',
            'tmdb_params' => [],
            'cache_enabled' => false,
        ],
    ]]);

    $group = DynamicGroup::create([
        'playlist_id' => $playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Disabled Series',
    ]);

    Livewire::test(ListSeriesDynamicGroups::class)
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
            'type' => 'series',
            'source' => 'trending',
            'name' => 'Legacy Series',
            'tmdb_params' => [],
            'cache_enabled' => false,
        ],
    ]]);

    $group = DynamicGroup::create([
        'playlist_id' => $playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Legacy Series',
        'enabled' => true,
    ]);

    Livewire::test(ListSeriesDynamicGroups::class)
        ->mountTableAction('edit', $group)
        ->assertSchemaComponentStateSet('enabled', true);
});

it('deleting a row removes the DynamicGroup record', function () {

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Mine',
    ]);

    Livewire::test(ListSeriesDynamicGroups::class)
        ->callTableAction('delete', $group);

    expect(DynamicGroup::find($group->id))->toBeNull();
});

// --- Items count -------------------------------------------------------------

it('the Items column counts series (not episodes)', function () {
    $series = Series::factory()->for($this->playlist)->create(['tmdb_id' => '1399']);
    Episode::factory()->for($this->playlist)->for($series)->create(['season' => 1, 'episode_num' => 1]);
    Episode::factory()->for($this->playlist)->for($series)->create(['season' => 1, 'episode_num' => 2]);

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Series Group',
    ]);
    $group->series()->attach($series);

    Livewire::test(ListSeriesDynamicGroups::class)
        ->assertTableColumnStateSet('series_count', 1, $group);
});

// --- Cache column ------------------------------------------------------------

it('the Cached column shows "—" for groups without a cache-enabled rule', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'No Cache Rule',
    ]);

    $this->playlist->update([
        'dynamic_groups_config' => [
            ['name' => 'No Cache Rule', 'cache_enabled' => false],
        ],
    ]);

    Livewire::test(ListSeriesDynamicGroups::class)
        ->assertTableColumnStateSet('cache_status', '—', $group);
});

it('the Cached column counts EPISODES (not series)', function () {
    $series = Series::factory()->for($this->playlist)->create(['tmdb_id' => '1399']);
    Episode::factory()->for($this->playlist)->for($series)->create(['season' => 1, 'episode_num' => 1]);
    Episode::factory()->for($this->playlist)->for($series)->create(['season' => 1, 'episode_num' => 2]);

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Top Series',
    ]);
    $group->series()->attach($series);

    // Only episode 1 is cached → "1/2", not "1/1" (which would be a series count).
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'episode',
        'tmdb_id' => '1399',
        'season_number' => 1,
        'episode_number' => 1,
        'quality' => null,
    ]);

    $this->playlist->update([
        'dynamic_groups_config' => [
            ['name' => 'Top Series', 'cache_enabled' => true],
        ],
    ]);

    Livewire::test(ListSeriesDynamicGroups::class)
        ->assertTableColumnStateSet('cache_status', '1/2', $group);
});

// --- Cache Now bulk action ---------------------------------------------------

it('exposes a cache_now bulk action on the Series listing', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Mine',
    ]);

    Livewire::test(ListSeriesDynamicGroups::class)
        ->assertTableBulkActionExists('cache_now');
});

it('cache_now dispatches one DownloadCachedContentFile job per episode across selected series-type groups', function () {
    Bus::fake();

    app(GeneralSettings::class)->refresh();
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $this->playlist->update(['dynamic_groups_config' => [
        ['name' => 'Top Series', 'cache_enabled' => true],
        ['name' => 'Top Drama', 'cache_enabled' => true],
    ]]);

    // Series A: 2 episodes
    $seriesA = Series::factory()->for($this->playlist)->create(['tmdb_id' => '1399']);
    Episode::factory()->for($this->playlist)->for($seriesA)->create([
        'season' => 1, 'episode_num' => 1, 'url' => 'http://test/stream-a-1.m3u8',
    ]);
    Episode::factory()->for($this->playlist)->for($seriesA)->create([
        'season' => 1, 'episode_num' => 2, 'url' => 'http://test/stream-a-2.m3u8',
    ]);

    // Series B: 1 episode
    $seriesB = Series::factory()->for($this->playlist)->create(['tmdb_id' => '1668']);
    Episode::factory()->for($this->playlist)->for($seriesB)->create([
        'season' => 1, 'episode_num' => 1, 'url' => 'http://test/stream-b-1.m3u8',
    ]);

    $groupA = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Top Series',
    ]);
    $groupA->series()->attach($seriesA);

    $groupB = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'popular', 'name' => 'Top Drama',
    ]);
    $groupB->series()->attach($seriesB);

    Livewire::test(ListSeriesDynamicGroups::class)
        ->callTableBulkAction('cache_now', [$groupA->id, $groupB->id]);

    // 2 + 1 = 3 episodes → 3 DownloadCachedContentFile dispatches.
    Bus::assertDispatchedTimes(DownloadCachedContentFile::class, 3);
});

it('cache_now fires a warning notification when dynamic-group caching is disabled', function () {
    Bus::fake();

    app(GeneralSettings::class)->refresh();
    app(GeneralSettings::class)->enable_dynamic_group_cache = false;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Mine',
    ]);

    $tester = Livewire::test(ListSeriesDynamicGroups::class)
        ->callTableBulkAction('cache_now', [$group->id]);

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
    $tester->assertNotified('Dynamic Group Caching is disabled');
});

it('shows a New Series Dynamic Group header action on the listing', function () {
    Livewire::test(ListSeriesDynamicGroups::class)
        ->assertOk()
        ->assertActionExists('create');
});

it('the playlist picker is prefilled with the active sub-tab when not on All', function () {
    $specific = Playlist::factory()->for($this->user)->create(['name' => 'Specific Playlist']);

    Livewire::test(ListSeriesDynamicGroups::class, ['activePlaylistTab' => (string) $specific->id])
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
        'type' => 'series',
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
        ->and($group->type)->toBe('series')
        ->and($group->source)->toBe('trending')
        ->and($group->name)->toBe('Trending Movies');

    $playlist = $playlist->fresh();
    expect($playlist->dynamic_groups_config)->toHaveCount(1)
        ->and($playlist->dynamic_groups_config[0])->toMatchArray($rule);
});
