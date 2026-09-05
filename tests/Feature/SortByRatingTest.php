<?php

use App\Jobs\RunPlaylistSortAlpha;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\SortService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory() triggers PlaylistListener → ProcessM3uImport job,
    // which hits Redis via Horizon. Bus::fake() intercepts the dispatch.
    Bus::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->vodGroup = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod']);
    $this->seriesCategory = Category::factory()->for($this->user)->for($this->playlist)->create();
    $this->service = new SortService;
});

// ────────────────────────────────────────────────────────────────────────────
// bulkSortGroupChannelsByRating — VOD channels within a single group
// ────────────────────────────────────────────────────────────────────────────

it('sorts VOD channels within a group by rating DESC (highest rated first)', function () {
    $low = Channel::factory()->for($this->user)->for($this->playlist)->for($this->vodGroup, 'group')->create(['is_vod' => true, 'title' => 'Low', 'sort' => 99, 'info' => ['rating' => 4.2]]);
    $mid = Channel::factory()->for($this->user)->for($this->playlist)->for($this->vodGroup, 'group')->create(['is_vod' => true, 'title' => 'Mid', 'sort' => 99, 'info' => ['rating' => 7.0]]);
    $hi = Channel::factory()->for($this->user)->for($this->playlist)->for($this->vodGroup, 'group')->create(['is_vod' => true, 'title' => 'Hi', 'sort' => 99, 'info' => ['rating' => 9.1]]);

    $this->service->bulkSortGroupChannelsByRating($this->vodGroup, 'DESC');

    // Channel.sort has a `decimal:4` cast on the model — see Channel::$casts.
    // Cast back to int for the assertion (matches SortByReleaseDateBulkActionsTest pattern).
    expect((int) $hi->refresh()->sort)->toBe(1)
        ->and((int) $mid->refresh()->sort)->toBe(2)
        ->and((int) $low->refresh()->sort)->toBe(3);
});

it('sorts VOD channels within a group by rating ASC and pushes null-rated to the bottom', function () {
    $rated = Channel::factory()->for($this->user)->for($this->playlist)->for($this->vodGroup, 'group')->create(['is_vod' => true, 'title' => 'Rated', 'sort' => 99, 'info' => ['rating' => 5.5]]);
    $unrated = Channel::factory()->for($this->user)->for($this->playlist)->for($this->vodGroup, 'group')->create(['is_vod' => true, 'title' => 'Unrated', 'sort' => 99, 'info' => null]);

    $this->service->bulkSortGroupChannelsByRating($this->vodGroup, 'ASC');

    // Even in ASC order, the unrated channel sinks to the bottom — otherwise
    // nulls would float to the top of an ASC sort, which is never what the
    // user wants for "sort by rating".
    expect((int) $rated->refresh()->sort)->toBe(1)
        ->and((int) $unrated->refresh()->sort)->toBe(2);
});

it('handles missing rating JSON path (info column null) without crashing', function () {
    // No `info` column set at all → JSON_EXTRACT returns NULL → COALESCE → 0.
    $a = Channel::factory()->for($this->user)->for($this->playlist)->for($this->vodGroup, 'group')->create(['is_vod' => true, 'title' => 'A', 'sort' => 1]);
    $b = Channel::factory()->for($this->user)->for($this->playlist)->for($this->vodGroup, 'group')->create(['is_vod' => true, 'title' => 'B', 'sort' => 2]);

    $this->service->bulkSortGroupChannelsByRating($this->vodGroup, 'DESC');

    expect((int) $a->refresh()->sort)->toBeInt()
        ->and((int) $b->refresh()->sort)->toBeInt();
});

// ────────────────────────────────────────────────────────────────────────────
// bulkSortCategorySeriesByRating — series within a single category
// ────────────────────────────────────────────────────────────────────────────

it('sorts series within a category by rating DESC', function () {
    $low = Series::factory()->for($this->user)->for($this->playlist)->for($this->seriesCategory, 'category')->create(['rating' => '5.0', 'sort' => 99]);
    $hi = Series::factory()->for($this->user)->for($this->playlist)->for($this->seriesCategory, 'category')->create(['rating' => '8.8', 'sort' => 99]);
    $unrated = Series::factory()->for($this->user)->for($this->playlist)->for($this->seriesCategory, 'category')->create(['rating' => null, 'sort' => 99]);

    $this->service->bulkSortCategorySeriesByRating($this->seriesCategory, 'DESC');

    expect($hi->refresh()->sort)->toBe(1)
        ->and($low->refresh()->sort)->toBe(2)
        ->and($unrated->refresh()->sort)->toBe(3);
});

// ────────────────────────────────────────────────────────────────────────────
// bulkSortPlaylistVodByRating — all VOD channels across the playlist, globally
// ────────────────────────────────────────────────────────────────────────────

it('sorts all VOD channels in a playlist globally by rating DESC with no group collisions', function () {
    $g1 = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'G1']);
    $g2 = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'G2']);

    // Interleave by group so the test fails if the implementation only sorts
    // within each group rather than across the playlist globally.
    $g1Low = Channel::factory()->for($this->user)->for($this->playlist)->for($g1)->create(['is_vod' => true, 'info' => ['rating' => 3.0]]);
    $g2Hi = Channel::factory()->for($this->user)->for($this->playlist)->for($g2)->create(['is_vod' => true, 'info' => ['rating' => 9.5]]);
    $g1Hi = Channel::factory()->for($this->user)->for($this->playlist)->for($g1)->create(['is_vod' => true, 'info' => ['rating' => 8.0]]);
    $g2Low = Channel::factory()->for($this->user)->for($this->playlist)->for($g2)->create(['is_vod' => true, 'info' => ['rating' => 4.0]]);

    $this->service->bulkSortPlaylistVodByRating($this->playlist, 'DESC');

    // Cross-group order: highest rating wins regardless of source group.
    // 1: g2Hi (9.5), 2: g1Hi (8.0), 3: g2Low (4.0), 4: g1Low (3.0)
    expect((int) $g2Hi->refresh()->sort)->toBe(1)
        ->and((int) $g1Hi->refresh()->sort)->toBe(2)
        ->and((int) $g2Low->refresh()->sort)->toBe(3)
        ->and((int) $g1Low->refresh()->sort)->toBe(4);
});

// ────────────────────────────────────────────────────────────────────────────
// bulkSortPlaylistSeriesByRating — all series across the playlist, globally
// ────────────────────────────────────────────────────────────────────────────

it('sorts all series in a playlist globally by rating DESC with no category collisions', function () {
    $c1 = Category::factory()->for($this->user)->for($this->playlist)->create(['name' => 'C1']);
    $c2 = Category::factory()->for($this->user)->for($this->playlist)->create(['name' => 'C2']);

    $c1Low = Series::factory()->for($this->user)->for($this->playlist)->for($c1, 'category')->create(['rating' => '5.0']);
    $c2Hi = Series::factory()->for($this->user)->for($this->playlist)->for($c2, 'category')->create(['rating' => '9.2']);
    $c1Hi = Series::factory()->for($this->user)->for($this->playlist)->for($c1, 'category')->create(['rating' => '7.8']);
    $c2Low = Series::factory()->for($this->user)->for($this->playlist)->for($c2, 'category')->create(['rating' => '4.5']);

    $this->service->bulkSortPlaylistSeriesByRating($this->playlist, 'DESC');

    expect($c2Hi->refresh()->sort)->toBe(1)
        ->and($c1Hi->refresh()->sort)->toBe(2)
        ->and($c1Low->refresh()->sort)->toBe(3)
        ->and($c2Low->refresh()->sort)->toBe(4);
});

// ────────────────────────────────────────────────────────────────────────────
// RunPlaylistSortAlpha job integration — the sort_alpha_config column === 'rating'
// branches dispatch to the new SortService methods.
// ────────────────────────────────────────────────────────────────────────────

it('dispatches bulkSortPlaylistVodByRating when sort_alpha_config targets vod_groups/all/rating', function () {
    $this->playlist->update([
        'sort_alpha_config' => [
            ['enabled' => true, 'target' => 'vod_groups', 'group' => ['all'], 'column' => 'rating', 'sort' => 'DESC'],
        ],
    ]);

    $g = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod']);
    $low = Channel::factory()->for($this->user)->for($this->playlist)->for($g)->create(['is_vod' => true, 'sort' => 99, 'info' => ['rating' => 2.0]]);
    $hi = Channel::factory()->for($this->user)->for($this->playlist)->for($g)->create(['is_vod' => true, 'sort' => 99, 'info' => ['rating' => 9.9]]);

    (new RunPlaylistSortAlpha($this->playlist))->handle();

    expect((int) $hi->refresh()->sort)->toBe(1)
        ->and((int) $low->refresh()->sort)->toBe(2);
});

it('dispatches bulkSortPlaylistSeriesByRating when sort_alpha_config targets series_categories/all/rating', function () {
    $this->playlist->update([
        'sort_alpha_config' => [
            ['enabled' => true, 'target' => 'series_categories', 'group' => ['all'], 'column' => 'rating', 'sort' => 'DESC'],
        ],
    ]);

    $c = Category::factory()->for($this->user)->for($this->playlist)->create();
    $low = Series::factory()->for($this->user)->for($this->playlist)->for($c, 'category')->create(['rating' => '4.0', 'sort' => 99]);
    $hi = Series::factory()->for($this->user)->for($this->playlist)->for($c, 'category')->create(['rating' => '9.0', 'sort' => 99]);

    (new RunPlaylistSortAlpha($this->playlist))->handle();

    expect($hi->refresh()->sort)->toBe(1)
        ->and($low->refresh()->sort)->toBe(2);
});

it('dispatches bulkSortGroupChannelsByRating when sort_alpha_config targets vod_groups with specific groups and column=rating', function () {
    // RunPlaylistSortAlpha filters groups by `name_internal` (the internal
    // provider-supplied group name), not the user-facing `name`. GroupFactory
    // doesn't set name_internal by default, so we set it explicitly to make
    // the filter match — same pattern as the playlist UI auto-populates.
    $otherGroup = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'Other', 'name_internal' => 'Other']);
    $targetGroup = Group::factory()->for($this->user)->for($this->playlist)->create(['type' => 'vod', 'name' => 'Target', 'name_internal' => 'Target']);

    $this->playlist->update([
        'sort_alpha_config' => [
            [
                'enabled' => true,
                'target' => 'vod_groups',
                'group' => ['Target'], // matches name_internal === 'Target'
                'column' => 'rating',
                'sort' => 'DESC',
            ],
        ],
    ]);

    $hit = Channel::factory()->for($this->user)->for($this->playlist)->for($targetGroup)->create(['is_vod' => true, 'sort' => 99, 'info' => ['rating' => 9.0]]);
    $skip = Channel::factory()->for($this->user)->for($this->playlist)->for($otherGroup)->create(['is_vod' => true, 'sort' => 99, 'info' => ['rating' => 9.0]]);

    (new RunPlaylistSortAlpha($this->playlist))->handle();

    // Target group channel was sorted (sort=1); other group channel was NOT touched.
    expect((int) $hit->refresh()->sort)->toBe(1)
        ->and((int) $skip->refresh()->sort)->toBe(99);
});
