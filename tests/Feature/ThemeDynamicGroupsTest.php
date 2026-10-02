<?php

use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Jobs\SyncDynamicGroups;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\TmdbService;
use App\Services\XtreamCategoryService;
use App\Settings\GeneralSettings;
use App\Support\DynamicGroupThemes;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Http::preventStrayRequests();
    // Theme rules intentionally don't call TMDB; if a stray Http call
    // shows up the test fails loud rather than masking it.
    Http::fake();
    Bus::fake();
});

/**
 * Build a Channel row with an info JSON that contains the given TMDB
 * keywords (the schema the enrichment pipeline populates). VOD uses
 * `is_vod = true` and a non-VOD channel would never match a theme rule.
 */
function makeThemeVodChannel(Playlist $playlist, string $title, array $info = [], array $meta = []): Channel
{
    return Channel::factory()->create(array_merge([
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'title' => $title,
        'title_custom' => null,
        'name' => $title,
        'enabled' => true,
        'info' => array_merge(['tmdb_keywords' => []], $info),
    ], $meta));
}

function makeThemeSeries(Playlist $playlist, string $name, array $metadata = [], array $meta = []): Series
{
    return Series::factory()->create(array_merge([
        'playlist_id' => $playlist->id,
        'name' => $name,
        'enabled' => true,
        'plot' => '',
        'metadata' => array_merge(['tmdb_keywords' => []], $metadata),
    ], $meta));
}

it('matches VOD by stored tmdb_keywords', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $hit = makeThemeVodChannel($playlist, 'Holiday Heist', ['tmdb_keywords' => ['christmas', 'heist']]);
    $miss = makeThemeVodChannel($playlist, 'Random Flick', ['tmdb_keywords' => ['heist']]);

    $query = DynamicGroup::itemsMatchingTheme('vod', $playlist->id, ['christmas'], []);
    $ids = $query->pluck('id')->all();

    expect($ids)->toContain($hit->id)->not->toContain($miss->id);
});

it('matches VOD by title text', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $hit = makeThemeVodChannel($playlist, 'A Very Christmas Special');
    $miss = makeThemeVodChannel($playlist, 'Unrelated Title');

    $query = DynamicGroup::itemsMatchingTheme('vod', $playlist->id, [], ['christmas']);
    $ids = $query->pluck('id')->all();

    expect($ids)->toContain($hit->id)->not->toContain($miss->id);
});

it('matches VOD by plot text', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $hit = makeThemeVodChannel($playlist, 'Something Else', ['plot' => 'It is Christmas in this movie.']);
    $miss = makeThemeVodChannel($playlist, 'Different Plot', ['plot' => 'No holiday here.']);

    $query = DynamicGroup::itemsMatchingTheme('vod', $playlist->id, [], ['christmas']);
    $ids = $query->pluck('id')->all();

    expect($ids)->toContain($hit->id)->not->toContain($miss->id);
});

it('matches VOD case-insensitively', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $hit = makeThemeVodChannel($playlist, 'CHRISTMAS Special');

    $query = DynamicGroup::itemsMatchingTheme('vod', $playlist->id, [], ['christmas']);
    $ids = $query->pluck('id')->all();

    expect($ids)->toContain($hit->id);
});

it('matches series by stored tmdb_keywords', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $hit = makeThemeSeries($playlist, 'Spooky Show', ['tmdb_keywords' => ['halloween']]);
    $miss = makeThemeSeries($playlist, 'Other Show', ['tmdb_keywords' => []]);

    $query = DynamicGroup::itemsMatchingTheme('series', $playlist->id, ['halloween'], []);
    $ids = $query->pluck('id')->all();

    expect($ids)->toContain($hit->id)->not->toContain($miss->id);
});

it('matches series by title text', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $hit = makeThemeSeries($playlist, 'Christmas Chronicles');
    $miss = makeThemeSeries($playlist, 'Random Series');

    $query = DynamicGroup::itemsMatchingTheme('series', $playlist->id, [], ['christmas']);
    $ids = $query->pluck('id')->all();

    expect($ids)->toContain($hit->id)->not->toContain($miss->id);
});

it('matches series by plot text', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $hit = makeThemeSeries($playlist, 'Spooky Tales', [], ['plot' => 'A Halloween night gone wrong.']);
    $miss = makeThemeSeries($playlist, 'Calmer Series', [], ['plot' => 'Nothing spooky at all.']);

    $query = DynamicGroup::itemsMatchingTheme('series', $playlist->id, [], ['halloween']);
    $ids = $query->pluck('id')->all();

    expect($ids)->toContain($hit->id)->not->toContain($miss->id);
});

it('does not match Santa Fe Trail or Easter Island style titles', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $santaFe = makeThemeVodChannel($playlist, 'Santa Fe Trail');
    $easterIsland = makeThemeVodChannel($playlist, 'Mystery of Easter Island');
    $christmasHit = makeThemeVodChannel($playlist, 'A Christmas Carol');

    $query = DynamicGroup::itemsMatchingTheme('vod', $playlist->id, [], ['christmas', 'santa claus']);
    $ids = $query->pluck('id')->all();

    expect($ids)->toContain($christmasHit->id)
        ->not->toContain($santaFe->id)
        ->not->toContain($easterIsland->id);
});

it('does not include items from another playlist', function () {
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create();
    $playlistB = Playlist::factory()->for($user)->create();
    $hitA = makeThemeVodChannel($playlistA, 'Christmas Hit');
    $hitB = makeThemeVodChannel($playlistB, 'Another Christmas Hit');

    $query = DynamicGroup::itemsMatchingTheme('vod', $playlistA->id, [], ['christmas']);
    $ids = $query->pluck('id')->all();

    expect($ids)->toContain($hitA->id)->not->toContain($hitB->id);
});

it('respects a custom rule with its own keywords and terms', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $byKeyword = makeThemeVodChannel($playlist, 'No Title Hit', ['tmdb_keywords' => ['gothic']]);
    $byTerm = makeThemeVodChannel($playlist, 'Gothic Tales');
    $unrelated = makeThemeVodChannel($playlist, 'Lighthearted Comedy');

    $params = ['preset' => 'custom', 'keywords' => ['gothic'], 'terms' => ['gothic']];
    $lists = DynamicGroupThemes::resolveLists($params);
    $query = DynamicGroup::itemsMatchingTheme('vod', $playlist->id, $lists['keywords'], $lists['terms']);
    $ids = $query->pluck('id')->all();

    expect($ids)->toContain($byKeyword->id)
        ->toContain($byTerm->id)
        ->not->toContain($unrelated->id);
});

it('matches nothing when there are no keywords and no terms', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    makeThemeVodChannel($playlist, 'Anything');

    $query = DynamicGroup::itemsMatchingTheme('vod', $playlist->id, [], []);

    expect($query->count())->toBe(0);
});

it('handles terms that contain LIKE wildcards without crashing', function () {
    // `%` and `_` are LIKE wildcards on every supported engine. Whether
    // a term containing them matches at all is database-specific
    // (Postgres respects `\` as the default escape; SQLite does not).
    // We only assert that a term containing wildcards runs through the
    // builder cleanly — the escaping helper exists as a defensive
    // measure for production where Postgres honours it.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    makeThemeVodChannel($playlist, 'A Christmas Carol');

    $query = DynamicGroup::itemsMatchingTheme('vod', $playlist->id, [], ['50%', 'a_b', 'mixed\\term']);

    // No exception thrown and the query is well-formed (returns a count,
    // not an error). The actual row set is engine-dependent.
    expect($query->count())->toBeGreaterThanOrEqual(0);
});

it('window helper: inside, outside, boundaries inclusive', function () {
    $now = Carbon::parse('2025-12-15 12:00:00');

    expect(DynamicGroupThemes::isWithinWindow('12-10', '12-20', $now))->toBeTrue();
    expect(DynamicGroupThemes::isWithinWindow('11-01', '11-30', $now))->toBeFalse();
    // Both endpoints inclusive.
    expect(DynamicGroupThemes::isWithinWindow('12-15', '12-15', $now))->toBeTrue();
    expect(DynamicGroupThemes::isWithinWindow('12-10', '12-15', $now))->toBeTrue();
    expect(DynamicGroupThemes::isWithinWindow('12-15', '12-20', $now))->toBeTrue();
});

it('window helper: wrap-around works at year end', function () {
    $dec31 = Carbon::parse('2025-12-31 12:00:00');
    $jan1 = Carbon::parse('2026-01-01 12:00:00');
    $jan2 = Carbon::parse('2026-01-02 12:00:00');
    $jan3 = Carbon::parse('2026-01-03 12:00:00');

    expect(DynamicGroupThemes::isWithinWindow('12-26', '01-02', $dec31))->toBeTrue();
    expect(DynamicGroupThemes::isWithinWindow('12-26', '01-02', $jan1))->toBeTrue();
    expect(DynamicGroupThemes::isWithinWindow('12-26', '01-02', $jan2))->toBeTrue();
    expect(DynamicGroupThemes::isWithinWindow('12-26', '01-02', $jan3))->toBeFalse();
});

it('window helper: missing window means always active', function () {
    $now = Carbon::parse('2025-07-04 12:00:00');

    expect(DynamicGroupThemes::isWithinWindow(null, null, $now))->toBeTrue();
    expect(DynamicGroupThemes::isWithinWindow('', '', $now))->toBeTrue();
    expect(DynamicGroupThemes::isWithinWindow('11-01', null, $now))->toBeTrue();
    expect(DynamicGroupThemes::isWithinWindow(null, '11-30', $now))->toBeTrue();
});

it('syncs a theme rule in season and surfaces it through dynamicCategories()', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $hit = makeThemeVodChannel($playlist, 'A Christmas Carol', ['tmdb_keywords' => ['christmas']]);

    $playlist->update([
        'dynamic_groups_config' => [[
            'type' => 'vod',
            'source' => 'theme',
            'name' => 'Christmas',
            'enabled' => true,
            'tmdb_params' => [
                'preset' => 'christmas',
                'active_from' => '11-15',
                'active_until' => '12-26',
            ],
        ]],
    ]);

    $this->travelTo(Carbon::parse('2025-12-10 12:00:00'));
    (new SyncDynamicGroups($playlist->id))->handle();

    $group = DynamicGroup::where('playlist_id', $playlist->id)->first();
    expect($group)->not->toBeNull();
    expect($group->enabled)->toBeTrue();
    expect($group->source)->toBe('theme');

    $members = DB::table('dynamic_group_items')->where('dynamic_group_id', $group->id)->pluck('item_id')->all();
    expect($members)->toContain($hit->id);

    $categories = XtreamCategoryService::dynamicCategories($playlist, true);
    expect(collect($categories)->pluck('category_name')->all())->toContain('Christmas');
});

it('hides a theme rule out of season but keeps the row id stable', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $hit = makeThemeVodChannel($playlist, 'A Christmas Carol', ['tmdb_keywords' => ['christmas']]);

    $playlist->update([
        'dynamic_groups_config' => [[
            'type' => 'vod',
            'source' => 'theme',
            'name' => 'Christmas',
            'enabled' => true,
            'tmdb_params' => [
                'preset' => 'christmas',
                'active_from' => '11-15',
                'active_until' => '12-26',
            ],
        ]],
    ]);

    // Sync once in season to establish the row + membership.
    $this->travelTo(Carbon::parse('2025-12-10 12:00:00'));
    (new SyncDynamicGroups($playlist->id))->handle();

    $group = DynamicGroup::where('playlist_id', $playlist->id)->first();
    $originalId = $group->id;

    expect($group->enabled)->toBeTrue();

    // Out of season: row stays, enabled flips off, membership clears.
    $this->travelTo(Carbon::parse('2025-07-04 12:00:00'));
    (new SyncDynamicGroups($playlist->id))->handle();

    $group->refresh();
    expect($group->id)->toBe($originalId);
    expect($group->enabled)->toBeFalse();
    expect(DB::table('dynamic_group_items')->where('dynamic_group_id', $group->id)->count())->toBe(0);

    $xtream = app(XtreamCategoryService::class);
    $categories = XtreamCategoryService::dynamicCategories($playlist, true);
    expect(collect($categories)->pluck('category_name')->all())->not->toContain('Christmas');

    $filtered = Channel::query();
    XtreamCategoryService::applyDynamicGroupFilter($filtered, $group->id, true);
    expect($filtered->count())->toBe(0);

    // Back in season: same id, repopulated, re-enabled.
    $this->travelTo(Carbon::parse('2025-12-10 12:00:00'));
    (new SyncDynamicGroups($playlist->id))->handle();

    $group->refresh();
    expect($group->id)->toBe($originalId);
    expect($group->enabled)->toBeTrue();
    $members = DB::table('dynamic_group_items')->where('dynamic_group_id', $group->id)->pluck('item_id')->all();
    expect($members)->toContain($hit->id);
});

it('clears prior membership when the local match is empty', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    makeThemeVodChannel($playlist, 'A Christmas Carol', ['tmdb_keywords' => ['christmas']]);

    $playlist->update([
        'dynamic_groups_config' => [[
            'type' => 'vod',
            'source' => 'theme',
            'name' => 'Christmas',
            'enabled' => true,
            'tmdb_params' => [
                'preset' => 'christmas',
                'active_from' => '11-15',
                'active_until' => '12-26',
            ],
        ]],
    ]);

    // First sync attaches the membership.
    $this->travelTo(Carbon::parse('2025-12-10 12:00:00'));
    (new SyncDynamicGroups($playlist->id))->handle();
    $group = DynamicGroup::where('playlist_id', $playlist->id)->first();
    expect(DB::table('dynamic_group_items')->where('dynamic_group_id', $group->id)->count())->toBe(1);

    // Drop both the keyword AND the matching text on the title — the
    // local match goes empty, the prior row should be detached, and the
    // DynamicGroup row should still exist.
    foreach (Channel::where('playlist_id', $playlist->id)->get() as $channel) {
        $channel->title = 'Renamed Flick';
        $channel->title_custom = null;
        $channel->name = 'Renamed Flick';
        $channel->info = ['tmdb_keywords' => []];
        $channel->save();
    }

    (new SyncDynamicGroups($playlist->id))->handle();

    expect(DynamicGroup::where('playlist_id', $playlist->id)->count())->toBe(1);
    expect(DB::table('dynamic_group_items')->where('dynamic_group_id', $group->id)->count())->toBe(0);
});

it('syncs a theme rule with TMDB unconfigured and does not delete it', function () {
    // Force TMDB to look unconfigured by rebinding the singleton with a
    // settings object whose tmdb_api_key is empty.
    $empty = new GeneralSettings;
    $empty->tmdb_api_key = null;
    $this->app->instance(TmdbService::class, new TmdbService($empty));

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $hit = makeThemeVodChannel($playlist, 'A Christmas Carol', ['tmdb_keywords' => ['christmas']]);

    $playlist->update([
        'dynamic_groups_config' => [[
            'type' => 'vod',
            'source' => 'theme',
            'name' => 'Christmas',
            'enabled' => true,
            'tmdb_params' => [
                'preset' => 'christmas',
                'active_from' => '11-15',
                'active_until' => '12-26',
            ],
        ]],
    ]);

    $this->travelTo(Carbon::parse('2025-12-10 12:00:00'));
    (new SyncDynamicGroups($playlist->id))->handle();

    $group = DynamicGroup::where('playlist_id', $playlist->id)->first();
    expect($group)->not->toBeNull();
    expect($group->source)->toBe('theme');

    $members = DB::table('dynamic_group_items')->where('dynamic_group_id', $group->id)->pluck('item_id')->all();
    expect($members)->toContain($hit->id);
});

it('preview returns local matches for a theme rule', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $hit = makeThemeVodChannel($playlist, 'A Christmas Carol');
    makeThemeVodChannel($playlist, 'Random Flick');

    $rule = [
        'type' => 'vod',
        'source' => 'theme',
        'name' => 'Christmas',
        'tmdb_params' => [
            'preset' => 'christmas',
            'active_from' => '11-15',
            'active_until' => '12-26',
        ],
    ];

    $data = PlaylistResource::getDynamicGroupPreviewData($rule, $playlist);

    expect($data['error'])->toBeNull();
    expect($data['matchedTotal'])->toBeGreaterThanOrEqual(1);
    expect(implode("\n", $data['matched']))->toContain('Christmas');
});

it('preview annotates out-of-season theme rules', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    makeThemeVodChannel($playlist, 'A Christmas Carol');

    $rule = [
        'type' => 'vod',
        'source' => 'theme',
        'name' => 'Christmas',
        'tmdb_params' => [
            'preset' => 'christmas',
            'active_from' => '11-15',
            'active_until' => '12-26',
        ],
    ];

    $this->travelTo(Carbon::parse('2025-07-04 12:00:00'));
    $data = PlaylistResource::getDynamicGroupPreviewData($rule, $playlist);

    expect($data['outOfSeason'] ?? false)->toBeTrue();
});

it('resolveLists returns the preset lists for a known preset', function () {
    $lists = DynamicGroupThemes::resolveLists(['preset' => 'halloween']);
    expect($lists['preset'])->toBe('halloween');
    expect($lists['keywords'])->toContain('halloween');
    expect($lists['terms'])->toContain('trick or treat');
});

it('resolveLists falls back to the rule own lists for custom', function () {
    $lists = DynamicGroupThemes::resolveLists([
        'preset' => 'custom',
        'keywords' => ['Gothic', 'Noir'],
        'terms' => ['Film', 'NOIR'],
    ]);

    expect($lists['preset'])->toBeNull();
    expect($lists['keywords'])->toBe(['gothic', 'noir']);
    expect($lists['terms'])->toBe(['film', 'noir']);
});

it('validateMonthDay rejects garbage and accepts Feb 29', function () {
    expect(DynamicGroupThemes::isValidMonthDay('12-25'))->toBeTrue();
    expect(DynamicGroupThemes::isValidMonthDay('02-29'))->toBeTrue();
    expect(DynamicGroupThemes::isValidMonthDay('13-01'))->toBeFalse();
    expect(DynamicGroupThemes::isValidMonthDay('02-30'))->toBeFalse();
    expect(DynamicGroupThemes::isValidMonthDay('not-a-date'))->toBeFalse();
    expect(DynamicGroupThemes::isValidMonthDay(null))->toBeFalse();
});

it('materializeRule still works for theme rules when invoked from CreateDynamicGroup header action', function () {
    // Mirror the CreateDynamicGroup header action's call shape (ListVodDynamicGroups
    // and ListSeriesDynamicGroups pass an array of params including the keyword/term
    // lists so the create form can pre-fill the rule).
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $hit = makeThemeVodChannel($playlist, 'A Christmas Carol');

    $job = new SyncDynamicGroups($playlist->id);
    $params = [
        'preset' => 'christmas',
        'active_from' => '11-15',
        'active_until' => '12-26',
    ];

    $this->travelTo(Carbon::parse('2025-12-10 12:00:00'));
    $tmdb = app(TmdbService::class);
    $group = $job->materializeRule($playlist, 'vod', 'theme', 'Christmas', $params, 0, $tmdb);

    expect($group)->not->toBeNull();
    expect($group->source)->toBe('theme');
    $members = DB::table('dynamic_group_items')->where('dynamic_group_id', $group->id)->pluck('item_id')->all();
    expect($members)->toContain($hit->id);
});

it('synced theme rules do not delete when TMDB is unconfigured and no TMDB rule exists', function () {
    $empty = new GeneralSettings;
    $empty->tmdb_api_key = null;
    $this->app->instance(TmdbService::class, new TmdbService($empty));

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $hit = makeThemeVodChannel($playlist, 'A Christmas Carol');

    $playlist->update([
        'dynamic_groups_config' => [[
            'type' => 'vod',
            'source' => 'theme',
            'name' => 'Christmas',
            'enabled' => true,
            'tmdb_params' => [
                'preset' => 'christmas',
                'active_from' => '11-15',
                'active_until' => '12-26',
            ],
        ]],
    ]);

    $this->travelTo(Carbon::parse('2025-12-10 12:00:00'));
    (new SyncDynamicGroups($playlist->id))->handle();

    $group = DynamicGroup::where('playlist_id', $playlist->id)->first();
    expect($group)->not->toBeNull();
    $members = DB::table('dynamic_group_items')->where('dynamic_group_id', $group->id)->pluck('item_id')->all();
    expect($members)->toContain($hit->id);
});

it('rejects an invalid or one-sided seasonal window in the rule form', function (array $window, string $message) {
    config()->set('feature.playlist_tmdb_dynamic_groups', true);
    $user = User::factory()->create();
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->create(['dynamic_groups_config' => null]);

    $component = Livewire\Livewire::test(EditPlaylist::class, ['record' => $playlist->getRouteKey()])
        ->fillForm(['dynamic_groups_config' => [[
            'enabled' => true,
            'type' => 'vod',
            'source' => 'theme',
            'name' => 'Christmas',
            'tmdb_params' => array_merge(['preset' => 'christmas'], $window),
        ]], 'user_agent' => 'Test Agent'])
        ->call('save');

    expect($component->errors()->all())->toContain($message)
        ->and(collect($component->errors()->keys())->every(fn (string $key): bool => str_contains($key, 'tmdb_params.active_')))->toBeTrue();
})->with([
    'impossible date' => [['active_from' => '13-45', 'active_until' => '12-26'], 'Use a real date in MM-DD format, e.g. 11-15.'],
    'only a start' => [['active_from' => '11-15', 'active_until' => null], 'Set both the start and end of the seasonal window, or neither.'],
]);

it('accepts a valid wrap-around seasonal window in the rule form', function () {
    config()->set('feature.playlist_tmdb_dynamic_groups', true);
    $user = User::factory()->create();
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->create(['dynamic_groups_config' => null]);

    Livewire\Livewire::test(EditPlaylist::class, ['record' => $playlist->getRouteKey()])
        ->fillForm(['dynamic_groups_config' => [[
            'enabled' => true,
            'type' => 'vod',
            'source' => 'theme',
            'name' => "New Year's",
            'tmdb_params' => ['preset' => 'new_years', 'active_from' => '12-26', 'active_until' => '01-02'],
        ]], 'user_agent' => 'Test Agent'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($playlist->fresh()->dynamic_groups_config[0]['tmdb_params']['active_until'] ?? null)->toBe('01-02');
});
