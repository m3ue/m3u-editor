<?php

use App\Jobs\DuplicateCustomPlaylist;
use App\Jobs\DuplicatePlaylist;
use App\Models\Bouquet;
use App\Models\Category;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\Series;
use App\Models\SourceCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
});

it('DuplicateCustomPlaylist copies bouquets without alias attachments', function () {
    $custom = CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id, 'id_channel_by' => 'stream_id']);
    $bouquet = Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => null,
        'custom_playlist_id' => $custom->id,
        'group_selections' => ['selected_groups' => ['My Group']],
    ]);
    $alias = PlaylistAlias::create([
        'name' => 'A', 'uuid' => fake()->uuid(), 'user_id' => $this->user->id,
        'playlist_id' => null, 'custom_playlist_id' => $custom->id, 'xtream_config' => null,
    ]);
    $alias->bouquets()->attach($bouquet);

    (new DuplicateCustomPlaylist($custom, name: 'CP Copy'))->handle();

    $copy = CustomPlaylist::where('name', 'CP Copy')->firstOrFail();
    $copiedBouquet = Bouquet::where('custom_playlist_id', $copy->id)->firstOrFail();

    expect($copiedBouquet->group_selections)->toBe(['selected_groups' => ['My Group']])
        ->and($copiedBouquet->playlist_id)->toBeNull()
        ->and(DB::table('bouquet_playlist_alias')->where('bouquet_id', $copiedBouquet->id)->count())->toBe(0)
        // Source attachments and selections untouched.
        ->and($alias->bouquets()->count())->toBe(1)
        ->and($bouquet->refresh()->custom_playlist_id)->toBe($custom->id);
});

it('DuplicatePlaylist copies standard-target bouquets', function () {
    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
    Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'group_selections' => ['selected_groups' => ['Sports'], 'selected_vod_groups' => ['Movies']],
        'auto_include_new_live' => true,
    ]);

    (new DuplicatePlaylist($playlist, name: 'P Copy'))->handle();

    $copy = Playlist::where('name', 'P Copy')->firstOrFail();
    $copiedBouquet = Bouquet::where('playlist_id', $copy->id)->firstOrFail();

    expect($copiedBouquet->getSelectedLiveGroupNames())->toBe(['Sports'])
        ->and($copiedBouquet->getSelectedVodGroupNames())->toBe(['Movies'])
        ->and($copiedBouquet->auto_include_new_live)->toBeTrue();
});

it('DuplicatePlaylist copies SourceCategory rows so a copied bouquet\'s category filter still resolves series (issue #1689)', function () {
    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());

    $category = Category::factory()->for($this->user)->for($playlist)->create([
        'name' => 'Kids', 'source_category_id' => 4242,
    ]);
    SourceCategory::create(['name' => 'Kids', 'source_category_id' => 4242, 'playlist_id' => $playlist->id]);

    $series = Series::factory()->for($this->user)->for($playlist)->for($category)->create([
        'enabled' => true, 'name' => 'Synthetic Kids Show', 'source_category_id' => 4242,
    ]);

    Bouquet::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'group_selections' => ['selected_categories' => ['Kids']],
    ]);

    (new DuplicatePlaylist($playlist, name: 'P Copy'))->handle();

    $copy = Playlist::where('name', 'P Copy')->firstOrFail();
    $copiedSeries = Series::where('playlist_id', $copy->id)->firstOrFail();
    $copiedBouquet = Bouquet::where('playlist_id', $copy->id)->firstOrFail();

    expect(SourceCategory::where('playlist_id', $copy->id)->count())->toBe(1)
        ->and(SourceCategory::where('playlist_id', $copy->id)->first()->source_category_id)->toBe(4242)
        ->and($copiedBouquet->getSelectedCategoryNames())->toBe(['Kids']);

    $alias = PlaylistAlias::create([
        'name' => 'Copy Alias', 'uuid' => fake()->uuid(), 'user_id' => $this->user->id,
        'playlist_id' => $copy->id, 'xtream_config' => null,
    ]);
    $alias->bouquets()->attach($copiedBouquet);

    expect($alias->series()->pluck('series.id'))->toContain($copiedSeries->id);
});

it('DuplicatePlaylist repoints merged groups and categories at their copied parents (issue #1816)', function () {
    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());

    // The child sorts first by id, so its parent has not been copied yet when it is reached.
    $childGroup = Group::factory()->for($this->user)->for($playlist)->create(['name' => 'Child', 'type' => 'live']);
    $parentGroup = Group::factory()->for($this->user)->for($playlist)->create(['name' => 'Parent', 'type' => 'live', 'is_merged' => true]);
    $childGroup->update(['parent_id' => $parentGroup->id]);
    Channel::factory()->for($this->user)->for($playlist)->create(['group_id' => $childGroup->id, 'name' => 'In child']);

    $childCategory = Category::factory()->for($this->user)->for($playlist)->create(['name' => 'Child cat']);
    $parentCategory = Category::factory()->for($this->user)->for($playlist)->create(['name' => 'Parent cat', 'is_merged' => true]);
    $childCategory->update(['parent_id' => $parentCategory->id]);

    (new DuplicatePlaylist($playlist, name: 'P Copy'))->handle();

    $copy = Playlist::where('name', 'P Copy')->firstOrFail();
    $copiedChildGroup = Group::where('playlist_id', $copy->id)->where('name', 'Child')->firstOrFail();
    $copiedParentGroup = Group::where('playlist_id', $copy->id)->where('name', 'Parent')->firstOrFail();
    $copiedChildCategory = Category::where('playlist_id', $copy->id)->where('name', 'Child cat')->firstOrFail();
    $copiedParentCategory = Category::where('playlist_id', $copy->id)->where('name', 'Parent cat')->firstOrFail();

    expect($copiedChildGroup->parent_id)->toBe($copiedParentGroup->id)
        ->and($copiedParentGroup->is_merged)->toBeTrue()
        ->and($copiedChildGroup->channels()->count())->toBe(1)
        ->and($copiedChildCategory->parent_id)->toBe($copiedParentCategory->id)
        // Source hierarchy untouched.
        ->and($childGroup->refresh()->parent_id)->toBe($parentGroup->id)
        ->and($childCategory->refresh()->parent_id)->toBe($parentCategory->id);
});
