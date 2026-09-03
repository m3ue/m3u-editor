<?php

/**
 * Tests for issue #1325 ("Playlist alias channel filter for custom playlists").
 *
 * An alias of a custom playlist previously ignored group_filter entirely — the form
 * hid the filter fieldset and channels()/series() returned early before applying it.
 *
 * Custom playlists group their content with per-playlist Spatie tags instead of provider
 * groups, and content with no tag falls back to its provider group/category name in the
 * generated output. Both paths must be honoured so the filter matches the categories the
 * client is actually shown.
 */

use App\Filament\Resources\PlaylistAliases\Pages\CreatePlaylistAlias;
use App\Filament\Resources\PlaylistAliases\Pages\EditPlaylistAlias;
use App\Filament\Tables\CustomPlaylistGroupsTable;
use App\Http\Controllers\PlaylistGenerateController;
use App\Models\Bouquet;
use App\Models\Category;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\Series;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\TableSelect\Livewire\TableSelectLivewireComponent;
use Livewire\Livewire;
use Spatie\Tags\Tag;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeCustomAlias(User $user, CustomPlaylist $customPlaylist, array $groupFilter = [], array $attributes = []): PlaylistAlias
{
    return PlaylistAlias::create(array_merge([
        'name' => 'Custom Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $user->id,
        'custom_playlist_id' => $customPlaylist->id,
        'xtream_config' => null,
        'group_filter' => $groupFilter ?: null,
    ], $attributes));
}

/**
 * Create a group tag on the custom playlist and attach it to the given channels.
 *
 * @param  array<Channel>  $channels
 */
function tagChannels(CustomPlaylist $customPlaylist, string $name, array $channels): Tag
{
    $tag = Tag::findOrCreate($name, $customPlaylist->uuid);
    $customPlaylist->attachTag($tag);

    foreach ($channels as $channel) {
        $channel->attachTag($tag);
    }

    return $tag;
}

/**
 * Create a category tag on the custom playlist and attach it to the given series.
 *
 * @param  array<Series>  $series
 */
function tagSeries(CustomPlaylist $customPlaylist, string $name, array $series): Tag
{
    $tag = Tag::findOrCreate($name, $customPlaylist->uuid.'-category');
    $customPlaylist->attachTag($tag);

    foreach ($series as $item) {
        $item->attachTag($tag);
    }

    return $tag;
}

/**
 * The group names the alias filter picker would offer, as the modal table lists them.
 *
 * @return array<string, string>
 */
function filterableGroupNames(CustomPlaylist $customPlaylist, bool $isVod): array
{
    return $customPlaylist->filterableGroupsQuery($isVod)->orderBy('name')->pluck('name', 'name')->all();
}

/**
 * The series category names the alias filter picker would offer.
 *
 * @return array<string, string>
 */
function filterableCategoryNames(CustomPlaylist $customPlaylist): array
{
    return $customPlaylist->filterableCategoriesQuery()->orderBy('name')->pluck('name', 'name')->all();
}

function addCustomChannel(User $user, Playlist $playlist, CustomPlaylist $customPlaylist, array $attributes = []): Channel
{
    $channel = Channel::factory()->for($user)->for($playlist)->create(array_merge([
        'enabled' => true,
        'is_vod' => false,
    ], $attributes));

    $customPlaylist->channels()->attach($channel->id);

    return $channel;
}

function addCustomSeries(User $user, Playlist $playlist, CustomPlaylist $customPlaylist, array $attributes = []): Series
{
    $series = Series::factory()->for($user)->for($playlist)->create(array_merge([
        'enabled' => true,
    ], $attributes));

    $customPlaylist->series()->attach($series->id);

    return $series;
}

beforeEach(function () {
    // Admin so the panel pages exercised below are testing this feature rather than
    // PlaylistAliasPolicy, which is covered separately.
    $this->user = User::factory()->admin()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
});

// ── channels() live group filter ─────────────────────────────────────────────

describe('PlaylistAlias::channels() custom playlist live group filter', function () {
    it('returns every channel when no filter is set', function () {
        $alias = makeCustomAlias($this->user, $this->customPlaylist);

        $sports = addCustomChannel($this->user, $this->playlist, $this->customPlaylist);
        $news = addCustomChannel($this->user, $this->playlist, $this->customPlaylist);
        tagChannels($this->customPlaylist, 'Sports', [$sports]);
        tagChannels($this->customPlaylist, 'News', [$news]);

        expect($alias->channels()->count())->toBe(2);
    });

    it('filters live channels to the allowed group tags', function () {
        $alias = makeCustomAlias($this->user, $this->customPlaylist, [
            'selected_groups' => ['Sports'],
        ]);

        $sports = addCustomChannel($this->user, $this->playlist, $this->customPlaylist);
        $news = addCustomChannel($this->user, $this->playlist, $this->customPlaylist);
        tagChannels($this->customPlaylist, 'Sports', [$sports]);
        tagChannels($this->customPlaylist, 'News', [$news]);

        $ids = $alias->channels()->pluck('channels.id');

        expect($ids)->toContain($sports->id)
            ->and($ids)->not->toContain($news->id);
    });

    it('matches untagged channels on the provider group name they fall back to', function () {
        $alias = makeCustomAlias($this->user, $this->customPlaylist, [
            'selected_groups' => ['Sports'],
        ]);

        $untaggedSports = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, [
            'group' => 'Sports',
        ]);
        $untaggedNews = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, [
            'group' => 'News',
        ]);

        $ids = $alias->channels()->pluck('channels.id');

        expect($ids)->toContain($untaggedSports->id)
            ->and($ids)->not->toContain($untaggedNews->id);
    });

    it('ignores the provider group name once a channel carries a group tag', function () {
        $alias = makeCustomAlias($this->user, $this->customPlaylist, [
            'selected_groups' => ['Sports'],
        ]);

        // Provider group says Sports, but the user re-grouped it under News in the
        // custom playlist — the client sees News, so the filter must exclude it.
        $regrouped = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, [
            'group' => 'Sports',
        ]);
        tagChannels($this->customPlaylist, 'News', [$regrouped]);

        expect($alias->channels()->pluck('channels.id'))->not->toContain($regrouped->id);
    });

    it('lets VOD channels through when only a live group filter is active', function () {
        $alias = makeCustomAlias($this->user, $this->customPlaylist, [
            'selected_groups' => ['Sports'],
        ]);

        $movie = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, [
            'is_vod' => true,
            'group' => 'Movies',
        ]);

        expect($alias->channels()->pluck('channels.id'))->toContain($movie->id);
    });
});

// ── channels() VOD group filter ──────────────────────────────────────────────

describe('PlaylistAlias::channels() custom playlist VOD group filter', function () {
    it('filters VOD channels while leaving live channels untouched', function () {
        $alias = makeCustomAlias($this->user, $this->customPlaylist, [
            'selected_vod_groups' => ['Movies'],
        ]);

        $movie = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['is_vod' => true]);
        $documentary = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['is_vod' => true]);
        $live = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['group' => 'News']);
        tagChannels($this->customPlaylist, 'Movies', [$movie]);
        tagChannels($this->customPlaylist, 'Documentaries', [$documentary]);

        $ids = $alias->channels()->pluck('channels.id');

        expect($ids)->toContain($movie->id)
            ->and($ids)->toContain($live->id)
            ->and($ids)->not->toContain($documentary->id);
    });

    it('applies the live and VOD filters independently', function () {
        $alias = makeCustomAlias($this->user, $this->customPlaylist, [
            'selected_groups' => ['Sports'],
            'selected_vod_groups' => ['Movies'],
        ]);

        $sports = addCustomChannel($this->user, $this->playlist, $this->customPlaylist);
        $news = addCustomChannel($this->user, $this->playlist, $this->customPlaylist);
        $movie = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['is_vod' => true]);
        $documentary = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['is_vod' => true]);
        tagChannels($this->customPlaylist, 'Sports', [$sports]);
        tagChannels($this->customPlaylist, 'News', [$news]);
        tagChannels($this->customPlaylist, 'Movies', [$movie]);
        tagChannels($this->customPlaylist, 'Documentaries', [$documentary]);

        $ids = $alias->channels()->pluck('channels.id');

        expect($ids->all())->toEqualCanonicalizing([$sports->id, $movie->id]);
    });
});

// ── series() category filter ─────────────────────────────────────────────────

describe('PlaylistAlias::series() custom playlist category filter', function () {
    it('returns every series when no filter is set', function () {
        $alias = makeCustomAlias($this->user, $this->customPlaylist);

        addCustomSeries($this->user, $this->playlist, $this->customPlaylist);
        addCustomSeries($this->user, $this->playlist, $this->customPlaylist);

        expect($alias->series()->count())->toBe(2);
    });

    it('filters series to the allowed category tags', function () {
        $alias = makeCustomAlias($this->user, $this->customPlaylist, [
            'selected_categories' => ['Drama'],
        ]);

        $drama = addCustomSeries($this->user, $this->playlist, $this->customPlaylist);
        $comedy = addCustomSeries($this->user, $this->playlist, $this->customPlaylist);
        tagSeries($this->customPlaylist, 'Drama', [$drama]);
        tagSeries($this->customPlaylist, 'Comedy', [$comedy]);

        $ids = $alias->series()->pluck('series.id');

        expect($ids)->toContain($drama->id)
            ->and($ids)->not->toContain($comedy->id);
    });

    it('matches untagged series on the provider category name they fall back to', function () {
        $alias = makeCustomAlias($this->user, $this->customPlaylist, [
            'selected_categories' => ['Drama'],
        ]);

        $dramaCategory = Category::factory()->for($this->user)->for($this->playlist)->create(['name' => 'Drama']);
        $comedyCategory = Category::factory()->for($this->user)->for($this->playlist)->create(['name' => 'Comedy']);

        $drama = addCustomSeries($this->user, $this->playlist, $this->customPlaylist, ['category_id' => $dramaCategory->id]);
        $comedy = addCustomSeries($this->user, $this->playlist, $this->customPlaylist, ['category_id' => $comedyCategory->id]);

        $ids = $alias->series()->pluck('series.id');

        expect($ids)->toContain($drama->id)
            ->and($ids)->not->toContain($comedy->id);
    });
});

// ── Filter option lists ──────────────────────────────────────────────────────

describe('CustomPlaylist filter option lists', function () {
    it('offers group tags and the fallback groups of untagged channels', function () {
        $tagged = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['group' => 'Ignored']);
        tagChannels($this->customPlaylist, 'Sports', [$tagged]);
        addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['group' => 'News']);

        expect(filterableGroupNames($this->customPlaylist, isVod: false))
            ->toEqualCanonicalizing(['Sports' => 'Sports', 'News' => 'News']);
    });

    it('lists fallback groups when the playlist has no custom group tags at all', function () {
        // Regression: with zero tags the tag-name collection came back empty and stayed an
        // Eloquent collection, so merging the plain-string fallback names blew up with
        // "Call to a member function getKey() on string" as soon as the form rendered.
        addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['group' => 'News']);
        addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['group' => 'Sports']);

        expect($this->customPlaylist->groupTags()->count())->toBe(0)
            ->and(filterableGroupNames($this->customPlaylist, isVod: false))
            ->toEqualCanonicalizing(['News' => 'News', 'Sports' => 'Sports']);
    });

    it('returns no options for a playlist with no content', function () {
        expect(filterableGroupNames($this->customPlaylist, isVod: false))->toBe([])
            ->and(filterableCategoryNames($this->customPlaylist))->toBe([]);
    });

    it('de-duplicates a tag and a provider group that share a name', function () {
        $tagged = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['group' => 'Sports']);
        tagChannels($this->customPlaylist, 'Sports', [$tagged]);
        addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['group' => 'Sports']);

        expect(filterableGroupNames($this->customPlaylist, isVod: false))->toBe(['Sports' => 'Sports']);
    });

    it('keeps live and VOD group options separate', function () {
        $live = addCustomChannel($this->user, $this->playlist, $this->customPlaylist);
        $vod = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['is_vod' => true]);
        tagChannels($this->customPlaylist, 'Sports', [$live]);
        tagChannels($this->customPlaylist, 'Movies', [$vod]);

        expect(filterableGroupNames($this->customPlaylist, isVod: false))->toBe(['Sports' => 'Sports'])
            ->and(filterableGroupNames($this->customPlaylist, isVod: true))->toBe(['Movies' => 'Movies']);
    });

    it('excludes disabled channels from the group options', function () {
        addCustomChannel($this->user, $this->playlist, $this->customPlaylist, [
            'group' => 'Disabled Group',
            'enabled' => false,
        ]);

        expect(filterableGroupNames($this->customPlaylist, isVod: false))->toBe([]);
    });

    it('offers category tags and the fallback categories of untagged series', function () {
        $tagged = addCustomSeries($this->user, $this->playlist, $this->customPlaylist);
        tagSeries($this->customPlaylist, 'Drama', [$tagged]);

        $comedyCategory = Category::factory()->for($this->user)->for($this->playlist)->create(['name' => 'Comedy']);
        addCustomSeries($this->user, $this->playlist, $this->customPlaylist, ['category_id' => $comedyCategory->id]);

        expect(filterableCategoryNames($this->customPlaylist))
            ->toEqualCanonicalizing(['Drama' => 'Drama', 'Comedy' => 'Comedy']);
    });
});

// ── Custom live group ordering ───────────────────────────────────────────────

describe('getChannelQuery custom playlist live group ordering', function () {
    it('orders groups by the alias custom order, overriding tag order', function () {
        // Tag order_column would otherwise put News (created first) before Sports.
        $news = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['title' => 'CNN']);
        $sports = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['title' => 'ESPN']);
        tagChannels($this->customPlaylist, 'News', [$news]);
        tagChannels($this->customPlaylist, 'Sports', [$sports]);

        $alias = makeCustomAlias($this->user, $this->customPlaylist, [
            'selected_groups' => ['News', 'Sports'],
            'sort_live_groups_custom' => true,
            'live_group_order' => ['Sports', 'News'],
        ]);

        $ids = PlaylistGenerateController::getChannelQuery($alias)->get()->pluck('id')->all();

        expect($ids)->toBe([$sports->id, $news->id]);
    });

    it('ignores the custom order when the toggle is off', function () {
        $news = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['title' => 'CNN']);
        $sports = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['title' => 'ESPN']);
        tagChannels($this->customPlaylist, 'News', [$news]);
        tagChannels($this->customPlaylist, 'Sports', [$sports]);

        $alias = makeCustomAlias($this->user, $this->customPlaylist, [
            'selected_groups' => ['News', 'Sports'],
            'sort_live_groups_custom' => false,
            'live_group_order' => ['Sports', 'News'],
        ]);

        $ids = PlaylistGenerateController::getChannelQuery($alias)->get()->pluck('id')->all();

        expect($ids)->toBe([$news->id, $sports->id]);
    });

    it('ranks untagged channels by the provider group name they fall back to', function () {
        $tagged = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['title' => 'ESPN']);
        tagChannels($this->customPlaylist, 'Sports', [$tagged]);
        $untagged = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, [
            'title' => 'CNN',
            'group' => 'News',
        ]);

        $alias = makeCustomAlias($this->user, $this->customPlaylist, [
            'sort_live_groups_custom' => true,
            'live_group_order' => ['News', 'Sports'],
        ]);

        $ids = PlaylistGenerateController::getChannelQuery($alias)->get()->pluck('id')->all();

        expect($ids)->toBe([$untagged->id, $tagged->id]);
    });

    it('places groups outside the custom order after the ordered ones', function () {
        $news = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['title' => 'CNN']);
        $sports = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['title' => 'ESPN']);
        $comedy = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['title' => 'Comedy Central']);
        tagChannels($this->customPlaylist, 'News', [$news]);
        tagChannels($this->customPlaylist, 'Sports', [$sports]);
        tagChannels($this->customPlaylist, 'Comedy', [$comedy]);

        $alias = makeCustomAlias($this->user, $this->customPlaylist, [
            'sort_live_groups_custom' => true,
            'live_group_order' => ['Sports'],
        ]);

        $ids = PlaylistGenerateController::getChannelQuery($alias)->get()->pluck('id')->all();

        // Sports first; the rest keep their tag ordering behind it.
        expect($ids[0])->toBe($sports->id)
            ->and(array_slice($ids, 1))->toBe([$news->id, $comedy->id]);
    });

    it('labels M3U groups with the custom tag rather than the provider group', function () {
        // getChannelQuery already resolved custom_group_name for an alias, but the writer
        // only applied it when $type === 'custom', so an alias emitted the provider group
        // while the Xtream API for the same alias reported the tag.
        $channel = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, [
            'title' => 'ESPN',
            'group' => 'Provider Sports',
        ]);
        tagChannels($this->customPlaylist, 'My Sports', [$channel]);

        $alias = makeCustomAlias($this->user, $this->customPlaylist);

        $content = $this->get("/{$alias->uuid}/playlist.m3u")->assertOk()->streamedContent();

        expect($content)->toContain('group-title="My Sports"')
            ->and($content)->not->toContain('group-title="Provider Sports"');
    });

    it('emits M3U group-titles in the alias custom order', function () {
        $news = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['title' => 'CNN']);
        $sports = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['title' => 'ESPN']);
        tagChannels($this->customPlaylist, 'News', [$news]);
        tagChannels($this->customPlaylist, 'Sports', [$sports]);

        $alias = makeCustomAlias($this->user, $this->customPlaylist, [
            'sort_live_groups_custom' => true,
            'live_group_order' => ['Sports', 'News'],
        ]);

        $response = $this->get("/{$alias->uuid}/playlist.m3u");
        $response->assertOk();

        $content = $response->streamedContent();
        $sportsPos = strpos($content, 'group-title="Sports"');
        $newsPos = strpos($content, 'group-title="News"');

        expect($sportsPos)->not->toBeFalse()
            ->and($newsPos)->not->toBeFalse()
            ->and($sportsPos)->toBeLessThan($newsPos);
    });
});

// ── Xtream API category listings ─────────────────────────────────────────────

describe('Xtream API categories for a filtered custom playlist alias', function () {
    beforeEach(function () {
        $this->aliasCredentials = ['username' => 'alias-user', 'password' => 'alias-pass'];
    });

    it('lists only the allowed live categories', function () {
        $sports = addCustomChannel($this->user, $this->playlist, $this->customPlaylist);
        $news = addCustomChannel($this->user, $this->playlist, $this->customPlaylist);
        tagChannels($this->customPlaylist, 'Sports', [$sports]);
        tagChannels($this->customPlaylist, 'News', [$news]);

        makeCustomAlias($this->user, $this->customPlaylist, ['selected_groups' => ['Sports']], $this->aliasCredentials);

        $response = $this->getJson(route('xtream.api.player', array_merge(
            ['action' => 'get_live_categories'],
            $this->aliasCredentials,
        )));

        $response->assertOk();
        expect(array_column($response->json(), 'category_name'))->toBe(['Sports']);
    });

    it('lists every live category when no filter is set', function () {
        $sports = addCustomChannel($this->user, $this->playlist, $this->customPlaylist);
        $news = addCustomChannel($this->user, $this->playlist, $this->customPlaylist);
        tagChannels($this->customPlaylist, 'Sports', [$sports]);
        tagChannels($this->customPlaylist, 'News', [$news]);

        makeCustomAlias($this->user, $this->customPlaylist, attributes: $this->aliasCredentials);

        $response = $this->getJson(route('xtream.api.player', array_merge(
            ['action' => 'get_live_categories'],
            $this->aliasCredentials,
        )));

        $response->assertOk();
        expect(array_column($response->json(), 'category_name'))->toEqualCanonicalizing(['Sports', 'News']);
    });

    it('lists only the allowed series categories', function () {
        $drama = addCustomSeries($this->user, $this->playlist, $this->customPlaylist);
        $comedy = addCustomSeries($this->user, $this->playlist, $this->customPlaylist);
        tagSeries($this->customPlaylist, 'Drama', [$drama]);
        tagSeries($this->customPlaylist, 'Comedy', [$comedy]);

        makeCustomAlias($this->user, $this->customPlaylist, ['selected_categories' => ['Drama']], $this->aliasCredentials);

        $response = $this->getJson(route('xtream.api.player', array_merge(
            ['action' => 'get_series_categories'],
            $this->aliasCredentials,
        )));

        $response->assertOk();
        expect(array_column($response->json(), 'category_name'))->toBe(['Drama']);
    });
});

// ── Filament edit form ───────────────────────────────────────────────────────

it('saves a channel filter from the alias edit form for a custom playlist', function () {
    $this->actingAs($this->user);

    $sports = addCustomChannel($this->user, $this->playlist, $this->customPlaylist);
    tagChannels($this->customPlaylist, 'Sports', [$sports]);
    addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['group' => 'News']);

    $alias = makeCustomAlias($this->user, $this->customPlaylist, attributes: [
        'xtream_config' => [[
            'url' => 'http://example.com:8080',
            'username' => 'alias-user',
            'password' => 'alias-pass',
        ]],
    ]);

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->assertSuccessful()
        ->fillForm(['group_filter' => ['selected_groups' => ['Sports']]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($alias->refresh()->getAllowedLiveGroupNames())->toBe(['Sports']);
});

it('renders the group picker for a custom playlist with no custom group tags', function () {
    // The create form is where the crash surfaced: selecting a custom playlist rendered
    // the filter fieldset, which resolved the selectable group names.
    $this->actingAs($this->user);

    addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['group' => 'News']);
    addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['group' => 'Sports']);

    Livewire::test(CreatePlaylistAlias::class)
        ->fillForm(['custom_playlist_id' => $this->customPlaylist->id])
        ->assertSuccessful()
        ->mountAction(TestAction::make('select')->schemaComponent('group_filter.selected_groups'))
        ->assertSuccessful();
});

it('explains that the picker combines custom and provider groups', function () {
    $this->actingAs($this->user);

    addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['group' => 'News']);

    Livewire::test(CreatePlaylistAlias::class)
        ->fillForm(['custom_playlist_id' => $this->customPlaylist->id])
        ->assertSuccessful()
        ->assertSee('What you can select')
        ->assertSee('combine any groups you created in the custom playlist with the original source playlist groups', escape: false);
});

it('does not show the custom playlist filter note for a standard playlist alias', function () {
    $this->actingAs($this->user);

    Livewire::test(CreatePlaylistAlias::class)
        ->fillForm(['playlist_id' => $this->playlist->id])
        ->assertSuccessful()
        ->assertDontSee('What you can select');
});

it('lists the selectable groups in the picker table', function () {
    // The picker table renders as its own Livewire component, so mounting the modal on the
    // form alone never runs this query — drive the table component directly.
    $this->actingAs($this->user);

    $tagged = addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['group' => 'Ignored']);
    tagChannels($this->customPlaylist, 'Sports', [$tagged]);
    addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['group' => 'News']);
    addCustomChannel($this->user, $this->playlist, $this->customPlaylist, [
        'group' => 'Movies',
        'is_vod' => true,
    ]);

    Livewire::test(TableSelectLivewireComponent::class, [
        'tableConfiguration' => base64_encode(CustomPlaylistGroupsTable::class),
        'tableArguments' => ['custom_playlist_id' => $this->customPlaylist->id, 'type' => 'live'],
        'state' => [],
    ])
        ->assertSuccessful()
        ->assertSee('Sports')
        ->assertSee('News')
        ->assertDontSee('Ignored')
        ->assertDontSee('Movies');
});

it('lists only VOD groups in the VOD picker table', function () {
    $this->actingAs($this->user);

    addCustomChannel($this->user, $this->playlist, $this->customPlaylist, ['group' => 'News']);
    addCustomChannel($this->user, $this->playlist, $this->customPlaylist, [
        'group' => 'Movies',
        'is_vod' => true,
    ]);

    Livewire::test(TableSelectLivewireComponent::class, [
        'tableConfiguration' => base64_encode(CustomPlaylistGroupsTable::class),
        'tableArguments' => ['custom_playlist_id' => $this->customPlaylist->id, 'type' => 'vod'],
        'state' => [],
    ])
        ->assertSuccessful()
        ->assertSee('Movies')
        ->assertDontSee('News');
});

it('does not describe the empty picker as being about custom playlist groups only', function () {
    // The model-derived label read "No custom playlist groups", implying the picker ignores
    // source playlist groups, and the stock description invited creating one from here.
    $this->actingAs($this->user);

    Livewire::test(TableSelectLivewireComponent::class, [
        'tableConfiguration' => base64_encode(CustomPlaylistGroupsTable::class),
        'tableArguments' => ['custom_playlist_id' => $this->customPlaylist->id, 'type' => 'live'],
        'state' => [],
    ])
        ->assertSuccessful()
        ->assertSee('No groups found')
        ->assertSee('No groups in this custom playlist or its source playlists.')
        ->assertDontSee('No custom playlist groups')
        ->assertDontSee('to get started');
});

it('renders an empty picker table when the custom playlist cannot be resolved', function () {
    $this->actingAs($this->user);

    Livewire::test(TableSelectLivewireComponent::class, [
        'tableConfiguration' => base64_encode(CustomPlaylistGroupsTable::class),
        'tableArguments' => ['custom_playlist_id' => 0, 'type' => 'live'],
        'state' => [],
    ])->assertSuccessful();
});

it('saves a custom group order from the alias edit form for a custom playlist', function () {
    $this->actingAs($this->user);

    $news = addCustomChannel($this->user, $this->playlist, $this->customPlaylist);
    $sports = addCustomChannel($this->user, $this->playlist, $this->customPlaylist);
    tagChannels($this->customPlaylist, 'News', [$news]);
    tagChannels($this->customPlaylist, 'Sports', [$sports]);

    $alias = makeCustomAlias($this->user, $this->customPlaylist, [
        'selected_groups' => ['News', 'Sports'],
    ], [
        'xtream_config' => [[
            'url' => 'http://example.com:8080',
            'username' => 'alias-user',
            'password' => 'alias-pass',
        ]],
    ]);

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->assertSuccessful()
        ->fillForm([
            'group_filter' => [
                'selected_groups' => ['News', 'Sports'],
                'sort_live_groups_custom' => true,
            ],
        ])
        // Replace the auto-seeded order outright — this is what dragging a row does.
        ->set('data.group_filter.live_group_order', [
            'a' => ['name' => 'Sports', 'label' => 'Sports'],
            'b' => ['name' => 'News', 'label' => 'News'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $alias->refresh();

    expect($alias->hasCustomLiveGroupSort())->toBeTrue()
        ->and($alias->getLiveGroupSortOrder())->toBe(['Sports', 'News']);
});

it('keeps an existing custom playlist filter when the edit form is saved untouched', function () {
    // The standard-playlist selects share their state path with the custom playlist ones
    // and are hydrated even while hidden, so they must not clobber the saved selection.
    $this->actingAs($this->user);

    $sports = addCustomChannel($this->user, $this->playlist, $this->customPlaylist);
    tagChannels($this->customPlaylist, 'Sports', [$sports]);

    $alias = makeCustomAlias($this->user, $this->customPlaylist, ['selected_groups' => ['Sports']], [
        'xtream_config' => [[
            'url' => 'http://example.com:8080',
            'username' => 'alias-user',
            'password' => 'alias-pass',
        ]],
    ]);

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->assertSuccessful()
        ->call('save')
        ->assertHasNoFormErrors();

    expect($alias->refresh()->getAllowedLiveGroupNames())->toBe(['Sports']);
});

it('does not materialize bouquet names into group_filter when the form is saved (R1 guard)', function () {
    $this->actingAs($this->user);

    $sports = addCustomChannel($this->user, $this->playlist, $this->customPlaylist);
    tagChannels($this->customPlaylist, 'Manual Group', [$sports]);

    $alias = makeCustomAlias($this->user, $this->customPlaylist, ['selected_groups' => ['Manual Group']], [
        'xtream_config' => [[
            'url' => 'http://example.com:8080',
            'username' => 'alias-user',
            'password' => 'alias-pass',
        ]],
    ]);

    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => null,
        'custom_playlist_id' => $this->customPlaylist->id,
        'group_selections' => ['selected_groups' => ['Bouquet Group']],
    ]);
    $alias->bouquets()->sync([$bouquet->id]);

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->assertSuccessful()
        ->call('save')
        ->assertHasNoFormErrors();

    expect($alias->refresh()->group_filter['selected_groups'])->toBe(['Manual Group']);
});
