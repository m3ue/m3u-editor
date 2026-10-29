<?php

/**
 * Allow and block bouquets, and hiding an alias's own picks.
 */

use App\Filament\Forms\Components\SourceGroupModalSelect;
use App\Filament\Resources\Bouquets\Pages\ListBouquets;
use App\Filament\Resources\PlaylistAliases\Pages\EditPlaylistAlias;
use App\Filament\Resources\PlaylistAliases\PlaylistAliasResource;
use App\Models\Bouquet;
use App\Models\Category;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\Series;
use App\Models\SourceCategory;
use App\Models\SourceGroup;
use App\Models\User;
use App\Services\XtreamCategoryService;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Tags\Tag;

function modesAlias(User $user, array $attributes = []): PlaylistAlias
{
    return PlaylistAlias::create(array_merge([
        'name' => 'Modes Alias',
        'uuid' => Str::uuid()->toString(),
        'user_id' => $user->id,
        'xtream_config' => null,
    ], $attributes));
}

function modesGroup(User $user, Playlist $playlist, string $name, string $type = 'live', bool $custom = false): Group
{
    return Group::factory()->for($playlist)->for($user)->create([
        'name' => $name,
        'name_internal' => $custom ? null : $name,
        'type' => $type,
        'custom' => $custom,
    ]);
}

/**
 * A provider channel from $providerGroup, sitting in $group (the same group unless the
 * user moved it into another one).
 */
function modesChannel(User $user, Playlist $playlist, string $providerGroup, ?Group $group = null, bool $isVod = false, array $attributes = []): Channel
{
    return Channel::factory()->for($playlist)->for($user)->create(array_merge([
        'enabled' => true,
        'is_vod' => $isVod,
        'group_internal' => $providerGroup,
        'group' => $group?->name ?? $providerGroup,
        'group_id' => $group?->id,
    ], $attributes));
}

/**
 * @return array<int, int>
 */
function deliveredIds(PlaylistAlias $alias, bool $isVod = false): array
{
    return $alias->fresh()->channels()->where('channels.is_vod', $isVod)->pluck('channels.id')->sort()->values()->all();
}

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->sports = modesChannel($this->user, $this->playlist, 'UK: SPORTS');
    $this->news = modesChannel($this->user, $this->playlist, 'UK: NEWS');
    $this->film = modesChannel($this->user, $this->playlist, 'ACTION', isVod: true);
});

describe('allow and block bouquets', function () {
    it('hides a block bouquet\'s groups when nothing else is selected', function () {
        $block = Bouquet::factory()->block()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['UK: NEWS'], 'selected_vod_groups' => ['ACTION']],
        ]);
        $alias = modesAlias($this->user, ['playlist_id' => $this->playlist->id]);
        $alias->bouquets()->attach($block);

        expect($alias->fresh()->hasLiveGroupFilter())->toBeTrue()
            ->and(deliveredIds($alias))->toBe([$this->sports->id])
            ->and(deliveredIds($alias, isVod: true))->toBe([]);
    });

    it('lets a block bouquet win over an allow bouquet', function () {
        $allow = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['UK: SPORTS', 'UK: NEWS']],
        ]);
        $block = Bouquet::factory()->block()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['UK: NEWS']],
        ]);
        $alias = modesAlias($this->user, ['playlist_id' => $this->playlist->id]);
        $alias->bouquets()->attach([$allow->id, $block->id]);

        expect(deliveredIds($alias))->toBe([$this->sports->id]);
    });

    it('does not count a block bouquet as an allow-list', function () {
        $block = Bouquet::factory()->block()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['UK: NEWS']],
        ]);
        $alias = modesAlias($this->user, ['playlist_id' => $this->playlist->id]);
        $alias->bouquets()->attach($block);

        expect($alias->fresh()->getAllowedLiveGroupNames())->toBe([]);
    });
});

it('filters series by provider category, allowed or blocked', function () {
    SourceCategory::create(['playlist_id' => $this->playlist->id, 'source_category_id' => 10, 'name' => 'Drama']);
    $drama = Series::factory()->for($this->playlist)->for($this->user)->create(['enabled' => true, 'source_category_id' => 10]);
    $comedy = Series::factory()->for($this->playlist)->for($this->user)->create(['enabled' => true, 'source_category_id' => 20]);
    $uncategorised = Series::factory()->for($this->playlist)->for($this->user)->create(['enabled' => true, 'source_category_id' => null]);

    $allowed = modesAlias($this->user, [
        'playlist_id' => $this->playlist->id,
        'group_filter' => ['selected_categories' => ['Drama']],
    ]);
    $block = Bouquet::factory()->block()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_categories' => ['Drama']],
    ]);
    $blocked = modesAlias($this->user, ['playlist_id' => $this->playlist->id]);
    $blocked->bouquets()->attach($block);

    expect($allowed->series()->pluck('series.id')->all())->toBe([$drama->id])
        ->and($blocked->fresh()->series()->pluck('series.id')->sort()->values()->all())->toBe([$comedy->id, $uncategorised->id]);
});

describe('other targets', function () {
    it('scopes a merged alias block bouquet to the source it was picked from', function () {
        $other = Playlist::factory()->for($this->user)->createQuietly();
        $otherSports = modesChannel($this->user, $other, 'UK: SPORTS');
        $merged = MergedPlaylist::factory()->for($this->user)->create();
        $merged->playlists()->attach([$this->playlist->id, $other->id]);
        $block = Bouquet::factory()->forMergedPlaylist($merged)->block()->create([
            'user_id' => $this->user->id,
            'group_selections' => ['selected_groups' => [['playlist_id' => $this->playlist->id, 'name' => 'UK: SPORTS']]],
        ]);
        $alias = modesAlias($this->user, ['merged_playlist_id' => $merged->id]);
        $alias->bouquets()->attach($block);

        $delivered = deliveredIds($alias);
        expect($delivered)->toContain($otherSports->id)
            ->and($delivered)->toContain($this->news->id)
            ->and($delivered)->not->toContain($this->sports->id);
    });

    it('hides a custom playlist\'s tagged group with a block bouquet', function () {
        $custom = CustomPlaylist::factory()->for($this->user)->create();
        $custom->channels()->attach([$this->sports->id, $this->news->id]);
        $tag = Tag::findOrCreate('Favourites', $custom->uuid);
        $custom->attachTag($tag);
        $this->sports->attachTag($tag);
        $block = Bouquet::factory()->forCustomPlaylist($custom)->block()->create([
            'user_id' => $this->user->id,
            'group_selections' => ['selected_groups' => ['Favourites']],
        ]);

        $alias = modesAlias($this->user, ['custom_playlist_id' => $custom->id]);
        $alias->bouquets()->attach($block);

        expect($alias->fresh()->channels()->pluck('channels.id')->all())->toBe([$this->news->id]);
    });
});

describe('hiding the selection', function () {
    it('delivers everything except the picks when they are hidden', function () {
        $alias = modesAlias($this->user, [
            'playlist_id' => $this->playlist->id,
            'group_filter' => ['selected_groups' => ['UK: NEWS'], 'hide_selected_groups' => true],
        ]);

        expect($alias->fresh()->hasLiveGroupFilter())->toBeTrue()
            ->and($alias->fresh()->getAllowedLiveGroupNames())->toBe([])
            ->and(deliveredIds($alias))->toBe([$this->sports->id])
            ->and(deliveredIds($alias, isVod: true))->toBe([$this->film->id]);
    });

    it('delivers everything when hiding with nothing picked', function () {
        $alias = modesAlias($this->user, [
            'playlist_id' => $this->playlist->id,
            'group_filter' => ['selected_groups' => [], 'hide_selected_groups' => true],
        ]);

        expect($alias->fresh()->hasLiveGroupFilter())->toBeFalse()
            ->and(deliveredIds($alias))->toBe([$this->sports->id, $this->news->id]);
    });

    it('hides VOD and series picks per type', function () {
        $comedy = modesChannel($this->user, $this->playlist, 'COMEDY', isVod: true);
        SourceCategory::create(['playlist_id' => $this->playlist->id, 'source_category_id' => 10, 'name' => 'Drama']);
        Series::factory()->for($this->playlist)->for($this->user)->create(['enabled' => true, 'source_category_id' => 10]);
        $other = Series::factory()->for($this->playlist)->for($this->user)->create(['enabled' => true, 'source_category_id' => 20]);
        $alias = modesAlias($this->user, [
            'playlist_id' => $this->playlist->id,
            'group_filter' => [
                'selected_vod_groups' => ['ACTION'],
                'hide_selected_vod_groups' => true,
                'selected_categories' => ['Drama'],
                'hide_selected_categories' => true,
            ],
        ]);

        expect(deliveredIds($alias, isVod: true))->toBe([$comedy->id])
            ->and(deliveredIds($alias))->toBe([$this->sports->id, $this->news->id])
            ->and($alias->fresh()->series()->pluck('series.id')->all())->toBe([$other->id]);
    });

    it('hides the picks from what an allow bouquet delivers', function () {
        $extra = modesChannel($this->user, $this->playlist, 'UK: MUSIC');
        $allow = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['UK: SPORTS', 'UK: NEWS']],
        ]);
        $alias = modesAlias($this->user, [
            'playlist_id' => $this->playlist->id,
            'group_filter' => ['selected_groups' => ['UK: NEWS'], 'hide_selected_groups' => true],
        ]);
        $alias->bouquets()->attach($allow);

        expect(deliveredIds($alias))->toBe([$this->sports->id])
            ->and(deliveredIds($alias))->not->toContain($extra->id);
    });

    it('hides a merged alias pick only from the source it was picked from', function () {
        $other = Playlist::factory()->for($this->user)->createQuietly();
        $otherSports = modesChannel($this->user, $other, 'UK: SPORTS');
        $merged = MergedPlaylist::factory()->for($this->user)->create();
        $merged->playlists()->attach([$this->playlist->id, $other->id]);
        $alias = modesAlias($this->user, [
            'merged_playlist_id' => $merged->id,
            'group_filter' => [
                'selected_groups' => [['playlist_id' => $this->playlist->id, 'name' => 'UK: SPORTS']],
                'hide_selected_groups' => true,
            ],
        ]);

        $delivered = deliveredIds($alias);
        expect($delivered)->toContain($otherSports->id)
            ->and($delivered)->toContain($this->news->id)
            ->and($delivered)->not->toContain($this->sports->id);
    });

    it('leaves hidden picks out of the Xtream category list', function () {
        $sportsGroup = modesGroup($this->user, $this->playlist, 'UK: SPORTS');
        $newsGroup = modesGroup($this->user, $this->playlist, 'UK: NEWS');
        $this->sports->update(['group_id' => $sportsGroup->id]);
        $this->news->update(['group_id' => $newsGroup->id]);
        $alias = modesAlias($this->user, [
            'playlist_id' => $this->playlist->id,
            'group_filter' => ['selected_groups' => ['UK: SPORTS'], 'hide_selected_groups' => true],
        ]);

        expect(array_column(XtreamCategoryService::groupCategories($alias->fresh(), false), 'category_name'))->toBe(['UK: NEWS']);
    });

    it('saves the switch from the alias form', function () {
        SourceGroup::create(['playlist_id' => $this->playlist->id, 'name' => 'UK: NEWS', 'type' => 'live']);
        $alias = modesAlias($this->user, [
            'playlist_id' => $this->playlist->id,
            'group_filter' => ['selected_groups' => ['UK: NEWS']],
        ]);
        $this->actingAs($this->user);

        Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
            ->assertSee(__('Allowed live groups'))
            ->fillForm([
                'xtream_config' => [['url' => 'http://example.com:8080', 'username' => 'alias-user', 'password' => 'alias-pass']],
                'group_filter.hide_selected_groups' => true,
            ])
            ->assertSee(__('Hidden live groups'))
            ->call('save')
            ->assertHasNoFormErrors();

        $alias->refresh();
        expect($alias->group_filter['hide_selected_groups'])->toBeTrue()
            ->and($alias->group_filter['selected_groups'])->toBe(['UK: NEWS'])
            ->and(deliveredIds($alias))->toBe([$this->sports->id]);
    });

    it('lists every delivered group, custom ones included, in the custom sort pane', function () {
        $sportsGroup = modesGroup($this->user, $this->playlist, 'UK: SPORTS');
        $sportsGroup->update(['sort_order' => 1]);
        $newsGroup = modesGroup($this->user, $this->playlist, 'UK: NEWS');
        $newsGroup->update(['sort_order' => 2]);
        $curated = modesGroup($this->user, $this->playlist, 'My Sports', custom: true);
        $curated->update(['name_internal' => 'My Sports', 'sort_order' => 3]);
        modesGroup($this->user, $this->playlist, 'UK: EMPTY');
        $this->sports->update(['group_id' => $sportsGroup->id]);
        $this->news->update(['group_id' => $newsGroup->id]);
        modesChannel($this->user, $this->playlist, 'UK: MUSIC', $curated);
        $alias = modesAlias($this->user, [
            'playlist_id' => $this->playlist->id,
            'group_filter' => [
                'selected_groups' => ['UK: NEWS'],
                'hide_selected_groups' => true,
                'sort_live_groups_custom' => true,
                'live_group_order' => ['My Sports'],
            ],
        ]);
        $this->actingAs($this->user);

        $livewire = Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()]);

        expect(PlaylistAliasResource::liveGroupSortNames($livewire->get('data.group_filter.live_group_order')))
            ->toBe(['My Sports', 'UK: SPORTS']);
    });
});

it('leaves hidden groups out of the Xtream category list', function () {
    $sportsGroup = modesGroup($this->user, $this->playlist, 'UK: SPORTS');
    $newsGroup = modesGroup($this->user, $this->playlist, 'UK: NEWS');
    $this->sports->update(['group_id' => $sportsGroup->id]);
    $this->news->update(['group_id' => $newsGroup->id]);

    $block = Bouquet::factory()->block()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_groups' => ['UK: SPORTS']],
    ]);
    $alias = modesAlias($this->user, ['playlist_id' => $this->playlist->id]);
    $alias->bouquets()->attach($block);

    expect(array_column(XtreamCategoryService::groupCategories($alias->fresh(), false), 'category_name'))->toBe(['UK: NEWS']);
});

describe('forms', function () {
    beforeEach(fn () => $this->actingAs($this->user));

    it('creates a block bouquet', function () {
        Livewire::test(ListBouquets::class)
            ->callAction('create', [
                'name' => 'No adult',
                'kind' => 'block',
                'target_type' => 'playlist',
                'target_id' => $this->playlist->id,
                'playlist_id' => $this->playlist->id,
            ])
            ->assertHasNoActionErrors();

        $bouquet = Bouquet::where('name', 'No adult')->firstOrFail();
        expect($bouquet->isBlock())->toBeTrue();
    });
});

describe('custom groups', function () {
    it('allows channels sitting in a custom group, wherever they came from', function () {
        $curated = modesGroup($this->user, $this->playlist, 'My Sports', custom: true);
        $curated->update(['name_internal' => 'My Sports']);
        $moved = modesChannel($this->user, $this->playlist, 'UK: ENTERTAINMENT', $curated);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['My Sports']],
        ]);
        $alias = modesAlias($this->user, ['playlist_id' => $this->playlist->id]);
        $alias->bouquets()->attach($bouquet);

        expect(deliveredIds($alias))->toBe([$moved->id]);
    });

    it('keeps matching after the custom group is renamed', function () {
        $curated = modesGroup($this->user, $this->playlist, 'My Sports', custom: true);
        $curated->update(['name_internal' => 'My Sports']);
        $moved = modesChannel($this->user, $this->playlist, 'UK: ENTERTAINMENT', $curated);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['My Sports']],
        ]);
        $alias = modesAlias($this->user, ['playlist_id' => $this->playlist->id]);
        $alias->bouquets()->attach($bouquet);

        $curated->update(['name' => 'Renamed']);

        expect(deliveredIds($alias))->toBe([$moved->id])
            ->and($bouquet->fresh()->staleSelectionNames())->toBe([]);
    });

    it('lets a block bouquet hide a custom group without dropping ungrouped channels', function () {
        $curated = modesGroup($this->user, $this->playlist, 'Adults', custom: true);
        $curated->update(['name_internal' => 'Adults']);
        modesChannel($this->user, $this->playlist, 'UK: ENTERTAINMENT', $curated);
        $noGroup = modesChannel($this->user, $this->playlist, 'unused', attributes: ['group_internal' => null, 'group' => null, 'group_id' => null]);
        $block = Bouquet::factory()->block()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['Adults']],
        ]);
        $alias = modesAlias($this->user, ['playlist_id' => $this->playlist->id]);
        $alias->bouquets()->attach($block);

        expect(deliveredIds($alias))->toBe([$this->sports->id, $this->news->id, $noGroup->id]);
    });

    it('does not widen a provider pick to a custom group of the same name', function () {
        SourceGroup::create(['playlist_id' => $this->playlist->id, 'name' => 'UK: SPORTS', 'type' => 'live']);
        $sameName = modesGroup($this->user, $this->playlist, 'UK: SPORTS', custom: true);
        $sameName->update(['name_internal' => 'UK: SPORTS']);
        modesChannel($this->user, $this->playlist, 'UK: ENTERTAINMENT', $sameName);
        $alias = modesAlias($this->user, [
            'playlist_id' => $this->playlist->id,
            'group_filter' => ['selected_groups' => ['UK: SPORTS']],
        ]);

        expect(deliveredIds($alias))->toBe([$this->sports->id])
            ->and(SourceGroupModalSelect::customGroupOptions($this->playlist->id, 'live'))->not->toHaveKey('UK: SPORTS');
    });

    it('does not treat a deleted custom group as resolvable', function () {
        $curated = modesGroup($this->user, $this->playlist, 'Gone', custom: true);
        $curated->update(['name_internal' => 'Gone']);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['Gone']],
        ]);
        $curated->delete();

        expect($bouquet->fresh()->staleSelectionNames())->toBe(['Gone']);
    });

    it('saves custom group picks next to the provider names', function () {
        $curated = modesGroup($this->user, $this->playlist, 'My Sports', custom: true);
        $curated->update(['name_internal' => 'My Sports']);
        $this->actingAs($this->user);

        Livewire::test(ListBouquets::class)
            ->callAction('create', [
                'name' => 'Mixed',
                'target_type' => 'playlist',
                'target_id' => $this->playlist->id,
                'playlist_id' => $this->playlist->id,
                'custom_group_picks_selected_groups' => ['My Sports'],
            ])
            ->assertHasNoActionErrors();

        expect(Bouquet::where('name', 'Mixed')->firstOrFail()->group_selections['selected_groups'])->toBe(['My Sports']);
    });

    it('round-trips custom group picks through the edit form', function () {
        $curated = modesGroup($this->user, $this->playlist, 'My Sports', custom: true);
        $curated->update(['name_internal' => 'My Sports']);
        SourceGroup::create(['name' => 'UK: SPORTS', 'playlist_id' => $this->playlist->id, 'type' => 'live']);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['UK: SPORTS', 'My Sports']],
        ]);
        $this->actingAs($this->user);

        Livewire::test(ListBouquets::class)
            ->mountTableAction('edit', $bouquet)
            ->assertTableActionDataSet(['custom_group_picks_selected_groups' => ['My Sports']])
            ->setTableActionData(['custom_group_picks_selected_groups' => []])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        expect($bouquet->fresh()->group_selections['selected_groups'])->toBe(['UK: SPORTS']);
    });

    it('orders a custom group in the alias custom live sort', function () {
        $provider = modesGroup($this->user, $this->playlist, 'UK: SPORTS');
        $provider->update(['sort_order' => 1]);
        $curated = modesGroup($this->user, $this->playlist, 'My Sports', custom: true);
        $curated->update(['name_internal' => 'My Sports', 'sort_order' => 2]);
        $this->sports->update(['group_id' => $provider->id]);
        modesChannel($this->user, $this->playlist, 'UK: NEWS', $curated);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['UK: SPORTS', 'My Sports']],
        ]);
        $alias = modesAlias($this->user, [
            'playlist_id' => $this->playlist->id,
            'group_filter' => ['sort_live_groups_custom' => true, 'live_group_order' => ['My Sports', 'UK: SPORTS']],
        ]);
        $alias->bouquets()->attach($bouquet);

        expect(array_column(XtreamCategoryService::groupCategories($alias->fresh(), false), 'category_name'))
            ->toBe(['My Sports', 'UK: SPORTS']);
    });

    it('labels custom groups so they are not mistaken for provider groups', function () {
        $curated = modesGroup($this->user, $this->playlist, 'UK: SPORTS', custom: true);
        $curated->update(['name_internal' => 'UK: SPORTS']);

        expect(SourceGroupModalSelect::customGroupOptions($this->playlist->id, 'live'))
            ->toBe(['UK: SPORTS' => 'UK: SPORTS (custom)']);
    });

    it('selects series in a category the provider did not supply', function () {
        SourceCategory::create(['playlist_id' => $this->playlist->id, 'source_category_id' => 10, 'name' => 'Drama']);
        $genre = Category::factory()->for($this->playlist)->for($this->user)->create(['name' => 'Comedy', 'name_internal' => 'Comedy']);
        $providerCategory = Category::factory()->for($this->playlist)->for($this->user)->create(['name' => 'Drama', 'name_internal' => 'Drama']);
        $comedy = Series::factory()->for($this->playlist)->for($this->user)->create(['enabled' => true, 'source_category_id' => 20, 'category_id' => $genre->id]);
        $drama = Series::factory()->for($this->playlist)->for($this->user)->create(['enabled' => true, 'source_category_id' => 10, 'category_id' => $providerCategory->id]);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_categories' => ['Comedy']],
        ]);
        $alias = modesAlias($this->user, ['playlist_id' => $this->playlist->id]);
        $alias->bouquets()->attach($bouquet);

        expect(SourceGroupModalSelect::customGroupOptions($this->playlist->id, 'categories'))->toBe(['Comedy' => 'Comedy (custom)'])
            ->and($alias->fresh()->series()->pluck('series.id')->all())->toBe([$comedy->id])
            ->and($bouquet->fresh()->staleSelectionNames())->toBe([]);
    });

    it('selects what a merged custom group holds', function () {
        $parent = modesGroup($this->user, $this->playlist, 'All Sports', custom: true);
        $parent->update(['name_internal' => 'All Sports', 'is_merged' => true]);
        $child = modesGroup($this->user, $this->playlist, 'UK: SPORTS');
        $child->update(['parent_id' => $parent->id]);
        $inChild = modesChannel($this->user, $this->playlist, 'UK: SPORTS', $child);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['All Sports']],
        ]);
        $alias = modesAlias($this->user, ['playlist_id' => $this->playlist->id]);
        $alias->bouquets()->attach($bouquet);

        expect(deliveredIds($alias))->toContain($inChild->id)
            ->and(deliveredIds($alias))->not->toContain($this->news->id);
    });

    it('saves custom picks on the alias form next to the provider picks', function () {
        $curated = modesGroup($this->user, $this->playlist, 'My Sports', custom: true);
        $curated->update(['name_internal' => 'My Sports']);
        $alias = modesAlias($this->user, [
            'playlist_id' => $this->playlist->id,
            'group_filter' => ['selected_groups' => ['My Sports']],
        ]);
        $this->actingAs($this->user);

        $form = Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
            ->assertSchemaStateSet(['custom_group_picks_selected_groups' => ['My Sports']]);

        $form->fillForm([
            'xtream_config' => [['url' => 'http://example.com:8080', 'username' => 'alias-user', 'password' => 'alias-pass']],
            'custom_group_picks_selected_groups' => ['My Sports'],
        ])->call('save')->assertHasNoFormErrors();

        expect($alias->fresh()->group_filter['selected_groups'])->toBe(['My Sports']);

        Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
            ->fillForm(['custom_group_picks_selected_groups' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($alias->fresh()->group_filter['selected_groups'] ?? [])->toBe([]);
    });
});
