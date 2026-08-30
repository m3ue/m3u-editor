<?php

use App\Models\Category;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Xtream API controllers talk to Redis for rate-limit checks; the
    // Playlist model listener dispatches into the sync pipeline (also Redis).
    // Bus::fake() neutralises the dispatcher so we don't need a live Redis.
    Bus::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->username = 'dyncat_'.Str::random(5);
    $this->password = 'testpass';

    $playlistAuth = PlaylistAuth::create([
        'name' => 'DynCat Test Auth',
        'username' => $this->username,
        'password' => $this->password,
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($playlistAuth);
});

// Helper: build Xtream API URL with a unique name to avoid redeclaration
// collision with XtreamApiControllerTest::getXtreamApiUrl().
function dynCatXtreamUrl(string $username, string $password, string $action, array $params = []): string
{
    $queryParams = array_merge([
        'username' => $username,
        'password' => $password,
        'action' => $action,
    ], $params);

    return route('xtream.api.player').'?'.http_build_query($queryParams);
}

// ──────────────────────────────────────────────────────────────────────────────
// get_vod_categories: dynamic category is prepended
// ──────────────────────────────────────────────────────────────────────────────

it('prepends enabled dynamic VOD categories to get_vod_categories', function () {
    // A regular group with one enabled VOD member — provides the baseline.
    $regularGroup = Group::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'name' => 'Regular VOD',
    ]);
    $regChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $regularGroup->id,
        'is_vod' => true,
        'enabled' => true,
    ]);

    // A dynamic group with one enabled VOD member.
    $dynGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Trending Now',
        'sort_order' => 0,
        'enabled' => true,
    ]);
    DB::table('dynamic_group_items')->insert([
        'dynamic_group_id' => $dynGroup->id,
        'item_type' => Channel::class,
        'item_id' => $regChannel->id,
    ]);

    // A dynamic group with zero enabled members — must be omitted.
    $emptyDyn = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'popular',
        'name' => 'Popular',
        'sort_order' => 1,
        'enabled' => true,
    ]);

    $response = $this->get(dynCatXtreamUrl($this->username, $this->password, 'get_vod_categories'));
    $response->assertOk();

    $categories = $response->json();

    $dynamicIds = array_values(array_filter(array_map(
        fn ($c) => is_numeric($c['category_id']) && (int) $c['category_id'] >= DynamicGroup::XTREAM_CATEGORY_ID_OFFSET
            ? (int) $c['category_id']
            : null,
        $categories,
    )));

    expect($dynamicIds)->toContain(DynamicGroup::XTREAM_CATEGORY_ID_OFFSET + $dynGroup->id)
        ->and($dynamicIds)->not->toContain(DynamicGroup::XTREAM_CATEGORY_ID_OFFSET + $emptyDyn->id);

    // The dynamic category must come before the regular group (offset id is
    // numerically larger, so we assert by name position, not id order).
    $dynNamePos = array_search('Trending Now', array_column($categories, 'category_name'), true);
    $regNamePos = array_search('Regular VOD', array_column($categories, 'category_name'), true);
    expect($dynNamePos)->not->toBeFalse()
        ->and($regNamePos)->not->toBeFalse()
        ->and($dynNamePos)->toBeLessThan($regNamePos);
});

// ──────────────────────────────────────────────────────────────────────────────
// get_vod_streams: filtering by dynamic category id returns only its members
// ──────────────────────────────────────────────────────────────────────────────

it('returns only the member channels when filtering VOD streams by a dynamic category id', function () {
    $groupA = Group::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'name' => 'Group A',
    ]);
    $groupB = Group::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'name' => 'Group B',
    ]);

    $chanA = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $groupA->id,
        'is_vod' => true,
        'enabled' => true,
        'title' => 'Movie A',
    ]);
    $chanB = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $groupB->id,
        'is_vod' => true,
        'enabled' => true,
        'title' => 'Movie B',
    ]);

    $dynGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Trending',
        'sort_order' => 0,
        'enabled' => true,
    ]);
    DB::table('dynamic_group_items')->insert([
        'dynamic_group_id' => $dynGroup->id,
        'item_type' => Channel::class,
        'item_id' => $chanA->id,
    ]);

    $dynamicCategoryId = (string) $dynGroup->xtreamCategoryId();

    $response = $this->get(dynCatXtreamUrl(
        $this->username,
        $this->password,
        'get_vod_streams',
        ['category_id' => $dynamicCategoryId],
    ));
    $response->assertOk();

    $body = $response->streamedContent();
    $decoded = json_decode($body, true);

    expect($decoded)->toBeArray()
        ->and(count($decoded))->toBe(1)
        ->and($decoded[0]['title'])->toBe('Movie A')
        // Echoed category_id must be the requested dynamic id, not the
        // member's underlying group_id.
        ->and((string) $decoded[0]['category_id'])->toBe($dynamicCategoryId)
        ->and((int) $decoded[0]['category_ids'][0])->toBe((int) $dynamicCategoryId);

    // Regression guard: filtering by a real group_id still echoes that id.
    $response2 = $this->get(dynCatXtreamUrl(
        $this->username,
        $this->password,
        'get_vod_streams',
        ['category_id' => (string) $groupA->id],
    ));
    $body2 = $response2->streamedContent();
    $decoded2 = json_decode($body2, true);

    expect($decoded2[0]['title'])->toBe('Movie A')
        ->and((string) $decoded2[0]['category_id'])->toBe((string) $groupA->id);
});

// ──────────────────────────────────────────────────────────────────────────────
// get_series_categories: dynamic category prepended + get_series filter
// ──────────────────────────────────────────────────────────────────────────────

it('handles dynamic categories for series end-to-end', function () {
    // A regular category with one enabled series — baseline.
    $regularCat = Category::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Drama',
    ]);
    $seriesA = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $regularCat->id,
        'enabled' => true,
        'name' => 'Show A',
    ]);
    $seriesB = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $regularCat->id, // same real category, but only B in dynamic
        'enabled' => true,
        'name' => 'Show B',
    ]);

    $dynGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'series',
        'source' => 'tmdb_network',
        'name' => 'Netflix Originals',
        'sort_order' => 0,
        'enabled' => true,
    ]);
    DB::table('dynamic_group_items')->insert([
        'dynamic_group_id' => $dynGroup->id,
        'item_type' => Series::class,
        'item_id' => $seriesB->id,
    ]);

    $response = $this->get(dynCatXtreamUrl($this->username, $this->password, 'get_series_categories'));
    $response->assertOk();

    $categories = $response->json();
    $dynNames = array_filter(array_column($categories, 'category_name'), fn ($n) => $n === 'Netflix Originals');
    expect($dynNames)->not->toBeEmpty();

    $dynamicCategoryId = (string) $dynGroup->xtreamCategoryId();

    $response2 = $this->get(dynCatXtreamUrl(
        $this->username,
        $this->password,
        'get_series',
        ['category_id' => $dynamicCategoryId],
    ));
    $body = $response2->streamedContent();
    $decoded = json_decode($body, true);

    expect($decoded)->toBeArray()
        ->and(count($decoded))->toBe(1)
        ->and($decoded[0]['name'])->toBe('Show B')
        ->and((string) $decoded[0]['category_id'])->toBe($dynamicCategoryId);
});

// ──────────────────────────────────────────────────────────────────────────────
// Regression: plain group category_id filtering still works
// ──────────────────────────────────────────────────────────────────────────────

it('keeps regular group category_id filtering unchanged', function () {
    $regularGroup = Group::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'name' => 'Drama',
    ]);
    $otherGroup = Group::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'name' => 'Comedy',
    ]);

    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $regularGroup->id,
        'is_vod' => true,
        'enabled' => true,
        'title' => 'Drama Film',
    ]);
    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $otherGroup->id,
        'is_vod' => true,
        'enabled' => true,
        'title' => 'Comedy Film',
    ]);

    $response = $this->get(dynCatXtreamUrl(
        $this->username,
        $this->password,
        'get_vod_streams',
        ['category_id' => (string) $regularGroup->id],
    ));
    $body = $response->streamedContent();
    $decoded = json_decode($body, true);

    expect(count($decoded))->toBe(1)
        ->and($decoded[0]['title'])->toBe('Drama Film');
});

// ──────────────────────────────────────────────────────────────────────────────
// Regression: plain streams with no category_id must still return 200
// (catches the "Undefined variable $dynamicGroupId" warning that was
// raised at closure creation when no category filter was applied).
// ──────────────────────────────────────────────────────────────────────────────

it('returns 200 for get_vod_streams with no category_id when dynamic groups exist', function () {
    // A regular group with one enabled VOD member — gives the streams list
    // something to echo.
    $group = Group::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'name' => 'Regular VOD',
    ]);
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $group->id,
        'is_vod' => true,
        'enabled' => true,
        'title' => 'Film',
    ]);

    // Add a dynamic group with a member, so the controller reaches the
    // `use ($dynamicGroupId)` capture in the streamed response closure.
    $dynGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Trending',
        'sort_order' => 0,
        'enabled' => true,
    ]);
    DB::table('dynamic_group_items')->insert([
        'dynamic_group_id' => $dynGroup->id,
        'item_type' => Channel::class,
        'item_id' => $channel->id,
    ]);

    // No category_id param — the controller must still resolve cleanly.
    $response = $this->get(dynCatXtreamUrl($this->username, $this->password, 'get_vod_streams'));
    $response->assertOk();

    $body = $response->streamedContent();
    $decoded = json_decode($body, true);

    // Echoed category_id must be the regular group's id, not the dynamic
    // group id (since no dynamic filter was applied).
    expect($decoded)->toHaveCount(1)
        ->and((string) $decoded[0]['category_id'])->toBe((string) $group->id);
});

it('returns 200 for get_series with no category_id when dynamic groups exist', function () {
    $category = Category::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Drama',
    ]);
    $seriesItem = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'category_id' => $category->id,
        'enabled' => true,
        'name' => 'Show',
    ]);

    $dynGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'series',
        'source' => 'tmdb_network',
        'name' => 'Netflix',
        'sort_order' => 0,
        'enabled' => true,
    ]);
    DB::table('dynamic_group_items')->insert([
        'dynamic_group_id' => $dynGroup->id,
        'item_type' => Series::class,
        'item_id' => $seriesItem->id,
    ]);

    $response = $this->get(dynCatXtreamUrl($this->username, $this->password, 'get_series'));
    $response->assertOk();

    $body = $response->streamedContent();
    $decoded = json_decode($body, true);

    expect($decoded)->toHaveCount(1)
        ->and((string) $decoded[0]['category_id'])->toBe((string) $category->id);
});

// ──────────────────────────────────────────────────────────────────────────────
// Regression: MergedPlaylist requests (where $sourcePlaylist is null) must
// not TypeError when dynamic groups exist for some other playlist.
// ──────────────────────────────────────────────────────────────────────────────

it('returns 200 for get_vod_categories on a MergedPlaylist when dynamic groups exist for a Playlist', function () {
    // Create a DynamicGroup + membership for *this* playlist — proves the
    // controller reaches the resolveDynamicCategories() helper on the
    // regular-Playlist branch (no crash for the regular playlist itself).
    $group = Group::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'name' => 'Regular VOD',
    ]);
    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $group->id,
        'is_vod' => true,
        'enabled' => true,
    ]);
    $dynGroup = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'trending',
        'name' => 'Trending',
        'sort_order' => 0,
        'enabled' => true,
    ]);

    // Now hit the Xtream API as a MergedPlaylist. $sourcePlaylist will be
    // null for a MergedPlaylist; the controller must not TypeError on the
    // null check.
    $merged = MergedPlaylist::factory()->create([
        'user_id' => $this->user->id,
    ]);

    // Attach a PlaylistAuth to the MergedPlaylist so it can authenticate.
    $mergedAuth = PlaylistAuth::create([
        'name' => 'Merged Auth',
        'username' => $this->username,
        'password' => $this->password,
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $merged->playlistAuths()->attach($mergedAuth);

    $response = $this->get(dynCatXtreamUrl($this->username, $this->password, 'get_vod_categories'));
    $response->assertOk();

    // Categories must come from the MergedPlaylist only (it has no groups
    // here), so we expect at least the empty-list 'all' fallback. The key
    // assertion is "200 + no crash", but we double-check no dynamic id
    // leaked into the merged response.
    $categories = $response->json();
    foreach ($categories as $cat) {
        $cid = (int) ($cat['category_id'] ?? 0);
        expect($cid < DynamicGroup::XTREAM_CATEGORY_ID_OFFSET)->toBeTrue();
    }
});
