<?php

/**
 * Allow and block bouquets.
 */

use App\Filament\Resources\Bouquets\Pages\ListBouquets;
use App\Models\Bouquet;
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
