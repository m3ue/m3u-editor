<?php

use App\Models\Category;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\XtreamCategoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
