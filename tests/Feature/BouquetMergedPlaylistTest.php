<?php

/**
 * Issue #1391, building on #1483: a bouquet can target a merged playlist. Its
 * selections are stored as {playlist_id, name} pairs (the merged-alias
 * group_filter shape) and union into a merged alias's effective per-source
 * filter through the same accessors #1483 added.
 */

use App\Filament\Resources\Bouquets\Pages\ListBouquets;
use App\Models\Bouquet;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\Series;
use App\Models\SourceCategory;
use App\Models\SourceGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function bmGroup(User $user, Playlist $playlist, string $name, string $type): Group
{
    return Group::factory()->for($playlist)->for($user)->create([
        'name' => $name, 'name_internal' => $name, 'type' => $type,
    ]);
}

function bmChannel(User $user, Playlist $playlist, Group $group, string $title, bool $isVod): Channel
{
    return Channel::factory()->for($user)->for($playlist)->for($group)->create([
        'enabled' => true, 'is_vod' => $isVod, 'group' => $group->name,
        'group_internal' => $group->name_internal, 'title' => $title, 'name' => $title,
        'url' => 'http://example.com/'.Str::slug($title),
    ]);
}

function bmMergedAlias(User $user, MergedPlaylist $merged, array $groupFilter = []): PlaylistAlias
{
    return PlaylistAlias::create([
        'merged_playlist_id' => $merged->id,
        'user_id' => $user->id,
        'name' => 'Merged Bouquet Alias',
        'uuid' => Str::uuid()->toString(),
        'xtream_config' => null,
        'group_filter' => $groupFilter,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->sourceA = Playlist::factory()->for($this->user)->createQuietly(['name' => 'Provider A']);
    $this->sourceB = Playlist::factory()->for($this->user)->createQuietly(['name' => 'Provider B']);

    $this->merged = MergedPlaylist::factory()->for($this->user)->create();
    $this->merged->playlists()->attach([$this->sourceA->id, $this->sourceB->id]);

    // Both sources have a live "Sports" group; only A has "News".
    $this->gASports = bmGroup($this->user, $this->sourceA, 'Sports', 'live');
    $this->gANews = bmGroup($this->user, $this->sourceA, 'News', 'live');
    $this->gBSports = bmGroup($this->user, $this->sourceB, 'Sports', 'live');
    $this->gAMovies = bmGroup($this->user, $this->sourceA, 'Movies', 'vod');

    $this->cASports = bmChannel($this->user, $this->sourceA, $this->gASports, 'A Sports', false);
    $this->cANews = bmChannel($this->user, $this->sourceA, $this->gANews, 'A News', false);
    $this->cBSports = bmChannel($this->user, $this->sourceB, $this->gBSports, 'B Sports', false);
    $this->cAMovies = bmChannel($this->user, $this->sourceA, $this->gAMovies, 'A Movies', true);

    SourceGroup::create(['playlist_id' => $this->sourceA->id, 'name' => 'Sports', 'type' => 'live']);
    SourceGroup::create(['playlist_id' => $this->sourceA->id, 'name' => 'News', 'type' => 'live']);
    SourceGroup::create(['playlist_id' => $this->sourceB->id, 'name' => 'Sports', 'type' => 'live']);
    SourceGroup::create(['playlist_id' => $this->sourceA->id, 'name' => 'Movies', 'type' => 'vod']);
});

it('requires exactly one target and rejects a merged target the user does not own', function () {
    $bouquet = new Bouquet(['name' => 'Two targets', 'user_id' => $this->user->id]);
    $bouquet->playlist_id = $this->sourceA->id;
    $bouquet->merged_playlist_id = $this->merged->id;

    expect(fn () => $bouquet->save())->toThrow(InvalidArgumentException::class);

    $otherMerged = MergedPlaylist::factory()->for(User::factory())->create();
    expect(fn () => Bouquet::create([
        'name' => 'Foreign', 'user_id' => $this->user->id, 'merged_playlist_id' => $otherMerged->id,
    ]))->toThrow(InvalidArgumentException::class);
});

it('stores pair selections and exposes them as names and pairs', function () {
    $bouquet = Bouquet::factory()->forMergedPlaylist($this->merged)->create([
        'user_id' => $this->user->id,
        'group_selections' => [
            'selected_groups' => [
                ['playlist_id' => $this->sourceA->id, 'name' => 'Sports'],
                ['playlist_id' => $this->sourceA->id, 'name' => 'News'],
            ],
        ],
    ]);

    expect($bouquet->getSelectedLiveGroupNames())->toEqualCanonicalizing(['Sports', 'News'])
        ->and($bouquet->getSelectedLiveGroupSelections())->toHaveCount(2)
        ->and($bouquet->getSelectedVodGroupSelections())->toBe([]);
});

it('unions bouquet pairs into a merged alias effective selection, scoped per source', function () {
    $alias = bmMergedAlias($this->user, $this->merged);
    $bouquet = Bouquet::factory()->forMergedPlaylist($this->merged)->create([
        'user_id' => $this->user->id,
        'group_selections' => ['selected_groups' => [
            ['playlist_id' => $this->sourceA->id, 'name' => 'Sports'],
        ]],
    ]);
    $alias->bouquets()->attach($bouquet);
    $alias->refresh();

    expect($alias->hasLiveGroupFilter())->toBeTrue()
        ->and($alias->getAllowedLiveGroupSelections())->toBe([
            ['playlist_id' => $this->sourceA->id, 'name' => 'Sports'],
        ]);

    // Provider A's Sports channel passes; provider B's same-named Sports does not,
    // and A's News (not selected) does not.
    $titles = $alias->channels()->pluck('title')->all();
    expect($titles)->toContain('A Sports')
        ->and($titles)->not->toContain('B Sports')
        ->and($titles)->not->toContain('A News');
});

it('unions a manual pair filter with bouquet pairs', function () {
    $alias = bmMergedAlias($this->user, $this->merged, [
        'selected_groups' => [
            ['playlist_id' => $this->sourceA->id, 'name' => 'News'],
        ],
    ]);

    $bouquet = Bouquet::factory()->forMergedPlaylist($this->merged)->create([
        'user_id' => $this->user->id,
        'group_selections' => ['selected_groups' => [
            ['playlist_id' => $this->sourceB->id, 'name' => 'Sports'],
        ]],
    ]);
    $alias->bouquets()->attach($bouquet);
    $alias->refresh();

    // Live channels are the manual + bouquet union; VOD is left unrestricted.
    $liveTitles = $alias->channels()->where('channels.is_vod', false)->pluck('title')->all();
    expect($liveTitles)->toEqualCanonicalizing(['A News', 'B Sports']);
});

it('filters a merged alias series() through a bouquet category selection', function () {
    $catA = Category::factory()->for($this->user)->for($this->sourceA)->create(['name' => 'Kids']);
    SourceCategory::create(['playlist_id' => $this->sourceA->id, 'name' => 'Kids', 'source_category_id' => 4242]);
    $series = Series::factory()->for($this->user)->for($this->sourceA)->for($catA)->create([
        'enabled' => true, 'source_category_id' => 4242, 'name' => 'Kids Show',
    ]);
    Series::factory()->for($this->user)->for($this->sourceA)->create([
        'enabled' => true, 'source_category_id' => 9999, 'name' => 'Other Show',
    ]);

    $alias = bmMergedAlias($this->user, $this->merged);
    $bouquet = Bouquet::factory()->forMergedPlaylist($this->merged)->create([
        'user_id' => $this->user->id,
        'group_selections' => ['selected_categories' => [
            ['playlist_id' => $this->sourceA->id, 'name' => 'Kids'],
        ]],
    ]);
    $alias->bouquets()->attach($bouquet);
    $alias->refresh();

    expect($alias->series()->pluck('name')->all())->toBe(['Kids Show']);
});

it('blocks attaching a merged bouquet to an alias of a different merged playlist', function () {
    $otherMerged = MergedPlaylist::factory()->for($this->user)->create();
    $alias = bmMergedAlias($this->user, $otherMerged);
    $bouquet = Bouquet::factory()->forMergedPlaylist($this->merged)->create(['user_id' => $this->user->id]);

    expect(fn () => $alias->bouquets()->attach($bouquet))->toThrow(InvalidArgumentException::class);
});

it('flags a bouquet pair as stale when its source group is gone, keyed to that source', function () {
    $bouquet = Bouquet::factory()->forMergedPlaylist($this->merged)->create([
        'user_id' => $this->user->id,
        'group_selections' => ['selected_groups' => [
            ['playlist_id' => $this->sourceA->id, 'name' => 'Sports'],
            ['playlist_id' => $this->sourceA->id, 'name' => 'Vanished'],
            ['playlist_id' => $this->sourceB->id, 'name' => 'Sports'],
        ]],
    ]);

    expect($bouquet->staleSelectionNames())->toBe(['Vanished']);

    $bouquet->removeStaleSelectionNames();
    $bouquet->refresh();

    expect($bouquet->getSelectedLiveGroupSelections())->toBe([
        ['playlist_id' => $this->sourceA->id, 'name' => 'Sports'],
        ['playlist_id' => $this->sourceB->id, 'name' => 'Sports'],
    ]);
});

it('propagates a provider group rename into merged bouquet pairs for that source only', function () {
    $bouquet = Bouquet::factory()->forMergedPlaylist($this->merged)->create([
        'user_id' => $this->user->id,
        'group_selections' => ['selected_groups' => [
            ['playlist_id' => $this->sourceA->id, 'name' => 'Sports'],
            ['playlist_id' => $this->sourceB->id, 'name' => 'Sports'],
        ]],
    ]);

    Bouquet::applyProviderRenames($this->sourceA->id, 'live', ['Sports' => 'Live Sports']);
    $bouquet->refresh();

    expect($bouquet->getSelectedLiveGroupSelections())->toBe([
        ['playlist_id' => $this->sourceA->id, 'name' => 'Live Sports'],
        ['playlist_id' => $this->sourceB->id, 'name' => 'Sports'],
    ]);
});

it('creates a merged-target bouquet through the resource, persisting pairs from picker ids', function () {
    $sgA = SourceGroup::where(['playlist_id' => $this->sourceA->id, 'name' => 'Sports'])->firstOrFail();
    $sgB = SourceGroup::where(['playlist_id' => $this->sourceB->id, 'name' => 'Sports'])->firstOrFail();

    Livewire::test(ListBouquets::class)
        ->callAction('create', data: [
            'name' => 'Merged Bouquet',
            'target_type' => 'merged_playlist',
            'target_id' => $this->merged->id,
            'merged_playlist_id' => $this->merged->id,
            'playlist_id' => null,
            'custom_playlist_id' => null,
            'group_selections' => ['selected_groups' => [$sgA->id, $sgB->id]],
        ])
        ->assertHasNoActionErrors();

    $bouquet = Bouquet::where('name', 'Merged Bouquet')->firstOrFail();
    expect($bouquet->merged_playlist_id)->toBe($this->merged->id)
        ->and($bouquet->getSelectedLiveGroupSelections())->toEqualCanonicalizing([
            ['playlist_id' => $this->sourceA->id, 'name' => 'Sports'],
            ['playlist_id' => $this->sourceB->id, 'name' => 'Sports'],
        ]);
});
