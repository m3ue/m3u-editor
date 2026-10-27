<?php

/**
 * Allow and block bouquets, and selections of a standard playlist's own groups/categories.
 */

use App\Filament\Resources\Bouquets\Pages\ListBouquets;
use App\Filament\Resources\PlaylistAliases\Pages\EditPlaylistAlias;
use App\Filament\Tables\PlaylistCategoriesTable;
use App\Filament\Tables\PlaylistGroupsTable;
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
use App\Models\User;
use App\Services\XtreamCategoryService;
use Filament\Forms\Components\TableSelect\Livewire\TableSelectLivewireComponent;
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

describe('the playlist\'s own groups and categories', function () {
    it('matches channels by the group they sit in, wherever they came from', function () {
        $curated = modesGroup($this->user, $this->playlist, 'My Sports', custom: true);
        $moved = modesChannel($this->user, $this->playlist, 'UK: ENTERTAINMENT', $curated);

        $alias = modesAlias($this->user, [
            'playlist_id' => $this->playlist->id,
            'group_filter' => ['selected_group_ids' => [$curated->id]],
        ]);

        expect(deliveredIds($alias))->toBe([$moved->id]);
    });

    it('lets a block bouquet hide a group by id', function () {
        $curated = modesGroup($this->user, $this->playlist, 'Adults', custom: true);
        $moved = modesChannel($this->user, $this->playlist, 'UK: ENTERTAINMENT', $curated);
        $block = Bouquet::factory()->block()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_group_ids' => [$curated->id]],
        ]);
        $alias = modesAlias($this->user, ['playlist_id' => $this->playlist->id]);
        $alias->bouquets()->attach($block);

        expect(deliveredIds($alias))->toBe([$this->sports->id, $this->news->id]);
    });

    it('treats an allow bouquet id selection as a restriction', function () {
        $curated = modesGroup($this->user, $this->playlist, 'My Sports', custom: true);
        $moved = modesChannel($this->user, $this->playlist, 'UK: ENTERTAINMENT', $curated);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_group_ids' => [$curated->id]],
        ]);
        $alias = modesAlias($this->user, ['playlist_id' => $this->playlist->id]);
        $alias->bouquets()->attach($bouquet);

        expect(deliveredIds($alias))->toBe([$moved->id]);
    });

    it('filters series by provider category and by playlist category', function () {
        SourceCategory::create(['playlist_id' => $this->playlist->id, 'source_category_id' => 10, 'name' => 'Drama']);
        $genre = Category::factory()->for($this->playlist)->for($this->user)->create(['name' => 'Comedy']);
        $drama = Series::factory()->for($this->playlist)->for($this->user)->create(['enabled' => true, 'source_category_id' => 10]);
        $comedy = Series::factory()->for($this->playlist)->for($this->user)->create(['enabled' => true, 'source_category_id' => 20, 'category_id' => $genre->id]);
        $uncategorised = Series::factory()->for($this->playlist)->for($this->user)->create(['enabled' => true, 'source_category_id' => null, 'category_id' => null]);

        $allowed = modesAlias($this->user, [
            'playlist_id' => $this->playlist->id,
            'group_filter' => ['selected_categories' => ['Drama'], 'selected_category_ids' => [$genre->id]],
        ]);
        $block = Bouquet::factory()->block()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_categories' => ['Drama']],
        ]);
        $blocked = modesAlias($this->user, ['playlist_id' => $this->playlist->id]);
        $blocked->bouquets()->attach($block);

        expect($allowed->series()->pluck('series.id')->sort()->values()->all())->toBe([$drama->id, $comedy->id])
            ->and($blocked->fresh()->series()->pluck('series.id')->sort()->values()->all())->toBe([$comedy->id, $uncategorised->id]);
    });

    it('reports and cleans up deleted groups in id selections', function () {
        $curated = modesGroup($this->user, $this->playlist, 'My Sports', custom: true);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_group_ids' => [$curated->id, 999999]],
        ]);

        expect($bouquet->staleSelectionNames())->toBe(['Deleted group #999999']);

        $bouquet->removeStaleSelectionNames();

        expect($bouquet->fresh()->group_selections['selected_group_ids'])->toBe([$curated->id]);
    });

    it('ignores id selections on merged and custom playlist aliases', function () {
        $merged = MergedPlaylist::factory()->for($this->user)->create();
        $alias = modesAlias($this->user, [
            'merged_playlist_id' => $merged->id,
            'group_filter' => ['selected_group_ids' => [123]],
        ]);

        expect($alias->hasLiveGroupFilter())->toBeFalse();
    });
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

    it('saves own-group picks', function () {
        $curated = modesGroup($this->user, $this->playlist, 'My Sports', custom: true);
        $alias = modesAlias($this->user, ['playlist_id' => $this->playlist->id]);

        Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
            ->fillForm([
                'xtream_config' => [['url' => 'http://example.com:8080', 'username' => 'alias-user', 'password' => 'alias-pass']],
                'group_filter.selected_group_ids' => [(string) $curated->id],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($alias->fresh()->group_filter)
            ->toMatchArray(['selected_group_ids' => [$curated->id]]);
    });

    it('lists only groups with enabled channels in the own-group picker by default', function () {
        $curated = modesGroup($this->user, $this->playlist, 'My Sports', custom: true);
        modesChannel($this->user, $this->playlist, 'UK: SPORTS', $curated);
        modesGroup($this->user, $this->playlist, 'Emptied Provider Group');

        Livewire::test(TableSelectLivewireComponent::class, [
            'tableConfiguration' => base64_encode(PlaylistGroupsTable::class),
            'tableArguments' => ['playlist_id' => $this->playlist->id, 'type' => 'live'],
            'state' => [],
        ])
            ->assertSuccessful()
            ->assertSee('My Sports')
            ->assertDontSee('Emptied Provider Group')
            ->filterTable('has_enabled_channels', null)
            ->assertSee('Emptied Provider Group');
    });

    it('lists only categories with enabled series in the own-category picker by default', function () {
        $comedy = Category::factory()->for($this->playlist)->for($this->user)->create(['name' => 'Comedy']);
        Category::factory()->for($this->playlist)->for($this->user)->create(['name' => 'Empty Category']);
        Series::factory()->for($this->playlist)->for($this->user)->create(['enabled' => true, 'category_id' => $comedy->id]);

        Livewire::test(TableSelectLivewireComponent::class, [
            'tableConfiguration' => base64_encode(PlaylistCategoriesTable::class),
            'tableArguments' => ['playlist_id' => $this->playlist->id],
            'state' => [],
        ])
            ->assertSuccessful()
            ->assertSee('Comedy')
            ->assertDontSee('Empty Category')
            ->filterTable('has_enabled_series', null)
            ->assertSee('Empty Category');
    });

    it('creates a block bouquet with own-group picks', function () {
        $curated = modesGroup($this->user, $this->playlist, 'Adults', custom: true);

        Livewire::test(ListBouquets::class)
            ->callAction('create', [
                'name' => 'No adult',
                'kind' => 'block',
                'target_type' => 'playlist',
                'target_id' => $this->playlist->id,
                'playlist_id' => $this->playlist->id,
                'group_selections' => ['selected_group_ids' => [(string) $curated->id]],
            ])
            ->assertHasNoActionErrors();

        $bouquet = Bouquet::where('name', 'No adult')->firstOrFail();
        expect($bouquet->isBlock())->toBeTrue()
            ->and($bouquet->group_selections['selected_group_ids'])->toBe([$curated->id]);
    });
});
