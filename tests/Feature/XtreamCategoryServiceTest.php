<?php

use App\Models\Bouquet;
use App\Models\Category;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\Series;
use App\Models\User;
use App\Services\XtreamCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('resolves a merged group id to itself plus every folded child', function () {
    $merged = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'live', 'custom' => true, 'is_merged' => true,
    ]);
    $childA = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'live', 'parent_id' => $merged->id,
    ]);
    $childB = Group::factory()->for($this->user)->for($this->playlist)->create([
        'type' => 'live', 'parent_id' => $merged->id,
    ]);
    $unrelated = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'live']);

    $ids = XtreamCategoryService::resolveGroupFilterIds($merged->id);

    expect($ids)->toContain($merged->id, $childA->id, $childB->id)
        ->and($ids)->not->toContain($unrelated->id);
});

it('resolves a plain group id to just itself', function () {
    $group = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'live']);

    expect(XtreamCategoryService::resolveGroupFilterIds($group->id))->toBe([$group->id]);
});

it('resolves a merged category id to itself plus every folded child', function () {
    $merged = Category::factory()->for($this->user)->for($this->playlist)->create(['is_merged' => true]);
    $child = Category::factory()->for($this->user)->for($this->playlist)->create(['parent_id' => $merged->id]);

    expect(XtreamCategoryService::resolveSeriesCategoryFilterIds($merged->id))
        ->toContain($merged->id, $child->id);
});

it('reports the merged group id for a folded channel and the group id otherwise', function () {
    // Mirrors the shape getChannelQuery() projects: group_id plus an optional merged_group_id.
    $folded = (object) ['group_id' => 10, 'merged_group_id' => 3];
    $plain = (object) ['group_id' => 10, 'merged_group_id' => null];
    $ungrouped = (object) ['group_id' => null, 'merged_group_id' => null];

    expect(XtreamCategoryService::channelStreamCategoryId($folded))->toBe('3')
        ->and(XtreamCategoryService::channelStreamCategoryId($plain))->toBe('10')
        ->and(XtreamCategoryService::channelStreamCategoryId($ungrouped))->toBe('all');
});

it('reports the merged category id for a folded series and the category id otherwise', function () {
    $merged = Category::factory()->for($this->user)->for($this->playlist)->create(['is_merged' => true]);
    $child = Category::factory()->for($this->user)->for($this->playlist)->create(['parent_id' => $merged->id]);
    $plain = Category::factory()->for($this->user)->for($this->playlist)->create();

    $foldedSeries = Series::factory()->for($this->user)->for($this->playlist)->create(['category_id' => $child->id]);
    $plainSeries = Series::factory()->for($this->user)->for($this->playlist)->create(['category_id' => $plain->id]);
    $uncategorised = Series::factory()->for($this->user)->for($this->playlist)->create(['category_id' => null]);

    $foldedSeries->load('category.parent');
    $plainSeries->load('category.parent');

    expect(XtreamCategoryService::seriesStreamCategoryId($foldedSeries))->toBe((string) $merged->id)
        ->and(XtreamCategoryService::seriesStreamCategoryId($plainSeries))->toBe((string) $plain->id)
        ->and(XtreamCategoryService::seriesStreamCategoryId($uncategorised))->toBe('all');
});

it('lists a merged group once, in the parent sort slot, for group categories', function () {
    $merged = Group::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'Nordics', 'type' => 'live', 'custom' => true, 'is_merged' => true, 'sort_order' => 1,
    ]);
    $child = Group::factory()->for($this->user)->for($this->playlist)->create([
        'name' => 'Denmark', 'type' => 'live', 'parent_id' => $merged->id, 'sort_order' => 50,
    ]);
    Channel::factory()->for($this->playlist)->for($child)->create([
        'user_id' => $this->user->id, 'enabled' => true, 'is_vod' => false,
    ]);

    $categories = XtreamCategoryService::groupCategories($this->playlist, isVod: false);

    expect($categories)->toHaveCount(1)
        ->and($categories[0]['category_id'])->toBe((string) $merged->id)
        ->and($categories[0]['category_name'])->toBe('Nordics')
        ->and($categories[0])->not->toHaveKey('_sort');
});

// ──────────────────────────────────────────────────────────────────────────────
// Dynamic (TMDB) group projection
// ──────────────────────────────────────────────────────────────────────────────

it('lists only enabled dynamic groups that have an enabled member, in sort order', function () {
    $withMember = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Trending', 'sort_order' => 1, 'enabled' => true,
    ]);
    $first = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'popular', 'name' => 'Popular', 'sort_order' => 0, 'enabled' => true,
    ]);
    // Enabled group with no members — dropped.
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'upcoming', 'name' => 'Coming Soon', 'sort_order' => 2, 'enabled' => true,
    ]);
    // Disabled group with a member — dropped.
    $disabled = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'now_playing', 'name' => 'In Theatres', 'sort_order' => 3, 'enabled' => false,
    ]);
    // Series-type group — excluded from the vod projection.
    DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'Trending Shows', 'sort_order' => 0, 'enabled' => true,
    ]);

    $enabledChannel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id, 'is_vod' => true, 'enabled' => true,
    ]);
    $disabledChannel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id, 'is_vod' => true, 'enabled' => false,
    ]);
    DB::table('dynamic_group_items')->insert([
        ['dynamic_group_id' => $withMember->id, 'item_type' => Channel::class, 'item_id' => $enabledChannel->id],
        ['dynamic_group_id' => $first->id, 'item_type' => Channel::class, 'item_id' => $enabledChannel->id],
        ['dynamic_group_id' => $disabled->id, 'item_type' => Channel::class, 'item_id' => $enabledChannel->id],
    ]);
    // "with member" group only has the disabled channel via a second row — still counts because $enabledChannel is attached above.
    DB::table('dynamic_group_items')->insert([
        'dynamic_group_id' => $withMember->id, 'item_type' => Channel::class, 'item_id' => $disabledChannel->id,
    ]);

    $categories = XtreamCategoryService::dynamicCategories($this->playlist, isVod: true);

    expect(array_column($categories, 'category_name'))->toBe(['Popular', 'Trending'])
        ->and($categories[0]['category_id'])->toBe((string) (DynamicGroup::XTREAM_CATEGORY_ID_OFFSET + $first->id))
        ->and($categories[0]['parent_id'])->toBe(0);
});

it('maps member item ids to their dynamic Xtream category ids', function () {
    $groupA = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'trending', 'name' => 'A', 'sort_order' => 0, 'enabled' => true,
    ]);
    $groupB = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'popular', 'name' => 'B', 'sort_order' => 1, 'enabled' => true,
    ]);
    $disabled = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'series', 'source' => 'top_genre', 'name' => 'C', 'sort_order' => 2, 'enabled' => false,
    ]);

    $s1 = Series::factory()->for($this->user)->for($this->playlist)->create(['enabled' => true]);
    $s2 = Series::factory()->for($this->user)->for($this->playlist)->create(['enabled' => true]);
    // A disabled member must not carry its group's dynamic category id, to
    // stay consistent with dynamicCategories() which hides groups whose only
    // members are disabled.
    $disabledSeries = Series::factory()->for($this->user)->for($this->playlist)->create(['enabled' => false]);
    DB::table('dynamic_group_items')->insert([
        ['dynamic_group_id' => $groupA->id, 'item_type' => Series::class, 'item_id' => $s1->id],
        ['dynamic_group_id' => $groupB->id, 'item_type' => Series::class, 'item_id' => $s1->id],
        ['dynamic_group_id' => $groupA->id, 'item_type' => Series::class, 'item_id' => $s2->id],
        ['dynamic_group_id' => $disabled->id, 'item_type' => Series::class, 'item_id' => $s2->id],
        ['dynamic_group_id' => $groupB->id, 'item_type' => Series::class, 'item_id' => $disabledSeries->id],
    ]);

    $map = XtreamCategoryService::dynamicCategoryIdsByItem($this->playlist, isVod: false);

    expect($map[$s1->id])->toEqualCanonicalizing([
        DynamicGroup::XTREAM_CATEGORY_ID_OFFSET + $groupA->id,
        DynamicGroup::XTREAM_CATEGORY_ID_OFFSET + $groupB->id,
    ])->and($map[$s2->id])->toBe([DynamicGroup::XTREAM_CATEGORY_ID_OFFSET + $groupA->id])
        ->and($map)->not->toHaveKey($disabledSeries->id);
});

it('constrains a query to one dynamic group\'s members', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Trending', 'sort_order' => 0, 'enabled' => true,
    ]);
    $member = Channel::factory()->for($this->playlist)->create(['user_id' => $this->user->id, 'is_vod' => true]);
    $other = Channel::factory()->for($this->playlist)->create(['user_id' => $this->user->id, 'is_vod' => true]);
    DB::table('dynamic_group_items')->insert([
        'dynamic_group_id' => $group->id, 'item_type' => Channel::class, 'item_id' => $member->id,
    ]);

    $query = Channel::query();
    XtreamCategoryService::applyDynamicGroupFilter($query, $group->id, isVod: true);

    expect($query->pluck('id')->all())->toBe([$member->id])
        ->and($other->id)->not->toBeIn($query->pluck('id')->all());
});

it('prepends dynamic groups for a standalone playlist but not for a merged playlist or filtered alias', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Trending', 'sort_order' => 0, 'enabled' => true,
    ]);
    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id, 'is_vod' => true, 'enabled' => true,
    ]);
    DB::table('dynamic_group_items')->insert([
        'dynamic_group_id' => $group->id, 'item_type' => Channel::class, 'item_id' => $channel->id,
    ]);

    $base = [['category_id' => '5', 'category_name' => 'Regular', 'parent_id' => 0]];
    $dynamicId = (string) (DynamicGroup::XTREAM_CATEGORY_ID_OFFSET + $group->id);

    // Standalone Playlist, no alias filter → prepended.
    $withDynamic = XtreamCategoryService::prependDynamicGroups($base, $this->playlist, isVod: true);
    expect(array_column($withDynamic, 'category_id'))->toBe([$dynamicId, '5']);

    // Curated alias filter → left untouched.
    $filtered = XtreamCategoryService::prependDynamicGroups($base, $this->playlist, isVod: true, aliasFilter: ['Movies']);
    expect($filtered)->toBe($base);

    // MergedPlaylist request → no source Playlist, left untouched.
    $merged = MergedPlaylist::factory()->for($this->user)->create();
    expect(XtreamCategoryService::prependDynamicGroups($base, $merged, isVod: true))->toBe($base);
});

it('suppresses dynamic groups for a bouquet-only alias exactly as a manual filter, but keeps them for a bouquet-less alias', function () {
    $group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id, 'user_id' => $this->user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Trending', 'sort_order' => 0, 'enabled' => true,
    ]);
    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id, 'is_vod' => true, 'enabled' => true,
    ]);
    DB::table('dynamic_group_items')->insert([
        'dynamic_group_id' => $group->id, 'item_type' => Channel::class, 'item_id' => $channel->id,
    ]);

    $base = [['category_id' => '5', 'category_name' => 'Regular', 'parent_id' => 0]];
    $dynamicId = (string) (DynamicGroup::XTREAM_CATEGORY_ID_OFFSET + $group->id);

    // Bouquet-only alias: no manual group_filter, but an attached bouquet selects a
    // VOD group - getAllowedVodGroupNames() unions that in, so the aliasFilter the
    // controller passes through is non-empty and suppression must kick in exactly
    // as it would for a manual filter.
    $bouquetOnlyAlias = PlaylistAlias::create([
        'name' => 'Bouquet Only Alias', 'uuid' => fake()->uuid(),
        'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id,
        'xtream_config' => null, 'group_filter' => null,
    ]);
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id,
        'group_selections' => ['selected_vod_groups' => ['Movies']],
    ]);
    $bouquetOnlyAlias->bouquets()->attach($bouquet);
    $bouquetOnlyAlias->refresh();

    $suppressed = XtreamCategoryService::prependDynamicGroups(
        $base, $this->playlist, isVod: true, aliasFilter: $bouquetOnlyAlias->getAllowedVodGroupNames(),
    );
    expect($suppressed)->toBe($base);

    // Inverse: an alias with neither a manual filter nor an attached bouquet must
    // still get its dynamic categories prepended.
    $bouquetLessAlias = PlaylistAlias::create([
        'name' => 'Bouquet Less Alias', 'uuid' => fake()->uuid(),
        'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id,
        'xtream_config' => null, 'group_filter' => null,
    ]);

    $kept = XtreamCategoryService::prependDynamicGroups(
        $base, $this->playlist, isVod: true, aliasFilter: $bouquetLessAlias->getAllowedVodGroupNames(),
    );
    expect(array_column($kept, 'category_id'))->toBe([$dynamicId, '5']);
});
