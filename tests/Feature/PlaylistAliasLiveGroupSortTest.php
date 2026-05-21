<?php

/**
 * Tests for per-alias custom live group ordering.
 *
 * Verifies that:
 * - PlaylistAlias::hasCustomLiveGroupSort()/getLiveGroupSortOrder() behave correctly
 * - getChannelQuery() ranks live groups by the alias's saved order, overriding the
 *   source playlist's group sort_order, and falls back for groups not in the list
 * - The M3U output emits group-titles in the custom order
 * - PlaylistAliasResource sort helpers reconcile selection/order and resolve the
 *   imported group's custom name for display
 * - SourceGroup::displayLabelsForIds() prefers the imported custom name
 */

use App\Filament\Resources\PlaylistAliases\Pages\EditPlaylistAlias;
use App\Filament\Resources\PlaylistAliases\PlaylistAliasResource;
use App\Http\Controllers\PlaylistGenerateController;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\SourceGroup;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeSortAlias(User $user, Playlist $playlist, array $groupFilter = []): PlaylistAlias
{
    return PlaylistAlias::create([
        'name' => 'Sort Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'xtream_config' => null,
        'group_filter' => $groupFilter ?: null,
    ]);
}

function makeLiveGroup(User $user, Playlist $playlist, string $name, float $sortOrder, ?string $customName = null): Group
{
    return Group::factory()->for($playlist)->for($user)->create([
        'name' => $customName ?? $name,
        'name_internal' => $name,
        'type' => 'live',
        'sort_order' => $sortOrder,
    ]);
}

function makeLiveChannel(User $user, Playlist $playlist, Group $group, string $title): Channel
{
    return Channel::factory()->for($user)->for($playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'group' => $group->name,
        'group_internal' => $group->name_internal,
        'title' => $title,
        'url' => 'http://example.com/'.Str::slug($title),
    ]);
}

// ── Model helpers ─────────────────────────────────────────────────────────────

describe('PlaylistAlias custom live group sort helpers', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->playlist = Playlist::factory()->for($this->user)->create();
    });

    it('hasCustomLiveGroupSort is false when the toggle is off', function () {
        $alias = makeSortAlias($this->user, $this->playlist, [
            'sort_live_groups_custom' => false,
            'live_group_order' => ['Sports', 'News'],
        ]);

        expect($alias->hasCustomLiveGroupSort())->toBeFalse();
    });

    it('hasCustomLiveGroupSort is false when the order is empty', function () {
        $alias = makeSortAlias($this->user, $this->playlist, [
            'sort_live_groups_custom' => true,
            'live_group_order' => [],
        ]);

        expect($alias->hasCustomLiveGroupSort())->toBeFalse();
    });

    it('hasCustomLiveGroupSort is true when enabled with an order', function () {
        $alias = makeSortAlias($this->user, $this->playlist, [
            'sort_live_groups_custom' => true,
            'live_group_order' => ['Sports', 'News'],
        ]);

        expect($alias->hasCustomLiveGroupSort())->toBeTrue()
            ->and($alias->getLiveGroupSortOrder())->toBe(['Sports', 'News']);
    });

    it('getLiveGroupSortOrder defaults to an empty array', function () {
        $alias = makeSortAlias($this->user, $this->playlist);

        expect($alias->getLiveGroupSortOrder())->toBe([]);
    });
});

// ── getChannelQuery ordering ──────────────────────────────────────────────────

describe('getChannelQuery custom live group ordering', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->playlist = Playlist::factory()->for($this->user)->create();
    });

    it('orders live groups by the alias custom order, overriding group sort_order', function () {
        // Default sort_order would yield News (1) before Sports (2).
        $news = makeLiveGroup($this->user, $this->playlist, 'News', 1);
        $sports = makeLiveGroup($this->user, $this->playlist, 'Sports', 2);
        $newsCh = makeLiveChannel($this->user, $this->playlist, $news, 'CNN');
        $sportsCh = makeLiveChannel($this->user, $this->playlist, $sports, 'ESPN');

        $alias = makeSortAlias($this->user, $this->playlist, [
            'selected_groups' => ['News', 'Sports'],
            'sort_live_groups_custom' => true,
            'live_group_order' => ['Sports', 'News'],
        ]);

        $channels = PlaylistGenerateController::getChannelQuery($alias)->get();

        // Custom order puts Sports first, reversing the default sort_order.
        expect($channels->first()->id)->toBe($sportsCh->id)
            ->and($channels->last()->id)->toBe($newsCh->id);
    });

    it('falls back to group sort_order when custom sort is disabled', function () {
        $news = makeLiveGroup($this->user, $this->playlist, 'News', 1);
        $sports = makeLiveGroup($this->user, $this->playlist, 'Sports', 2);
        $newsCh = makeLiveChannel($this->user, $this->playlist, $news, 'CNN');
        $sportsCh = makeLiveChannel($this->user, $this->playlist, $sports, 'ESPN');

        // Order present but the toggle is off → ignored.
        $alias = makeSortAlias($this->user, $this->playlist, [
            'selected_groups' => ['News', 'Sports'],
            'sort_live_groups_custom' => false,
            'live_group_order' => ['Sports', 'News'],
        ]);

        $channels = PlaylistGenerateController::getChannelQuery($alias)->get();

        expect($channels->first()->id)->toBe($newsCh->id)
            ->and($channels->last()->id)->toBe($sportsCh->id);
    });

    it('places groups not in the custom order after the ordered ones', function () {
        $news = makeLiveGroup($this->user, $this->playlist, 'News', 1);
        $sports = makeLiveGroup($this->user, $this->playlist, 'Sports', 2);
        $comedy = makeLiveGroup($this->user, $this->playlist, 'Comedy', 3);
        $newsCh = makeLiveChannel($this->user, $this->playlist, $news, 'CNN');
        $sportsCh = makeLiveChannel($this->user, $this->playlist, $sports, 'ESPN');
        $comedyCh = makeLiveChannel($this->user, $this->playlist, $comedy, 'Comedy Central');

        // Only Sports is explicitly ordered; News & Comedy fall back to sort_order.
        $alias = makeSortAlias($this->user, $this->playlist, [
            'selected_groups' => ['News', 'Sports', 'Comedy'],
            'sort_live_groups_custom' => true,
            'live_group_order' => ['Sports'],
        ]);

        $ids = PlaylistGenerateController::getChannelQuery($alias)->get()->pluck('id')->all();

        expect($ids)->toBe([$sportsCh->id, $newsCh->id, $comedyCh->id]);
    });
});

// ── M3U output order ──────────────────────────────────────────────────────────

it('M3U output emits group-titles in the alias custom order', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $news = makeLiveGroup($user, $playlist, 'News', 1);
    $sports = makeLiveGroup($user, $playlist, 'Sports', 2);
    makeLiveChannel($user, $playlist, $news, 'CNN');
    makeLiveChannel($user, $playlist, $sports, 'ESPN');

    // No credentials → the alias .m3u is served publicly by uuid.
    $alias = makeSortAlias($user, $playlist, [
        'selected_groups' => ['News', 'Sports'],
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

// ── Resource sort helpers ─────────────────────────────────────────────────────

describe('PlaylistAliasResource live group sort helpers', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->playlist = Playlist::factory()->for($this->user)->create();
    });

    it('buildLiveGroupSortItems preserves existing order and appends new selections', function () {
        $items = PlaylistAliasResource::buildLiveGroupSortItems(
            ['Sports', 'News'],
            ['News', 'Sports', 'Comedy'],
            $this->playlist->id,
        );

        expect(array_column(array_values($items), 'name'))->toBe(['Sports', 'News', 'Comedy']);
    });

    it('buildLiveGroupSortItems drops deselected groups', function () {
        $items = PlaylistAliasResource::buildLiveGroupSortItems(
            ['Sports', 'News'],
            ['Sports'],
            $this->playlist->id,
        );

        expect(array_column(array_values($items), 'name'))->toBe(['Sports']);
    });

    it('buildLiveGroupSortItems resolves the imported custom name as the label', function () {
        makeLiveGroup($this->user, $this->playlist, 'Sports', 1, customName: 'UK Sports HD');

        $items = PlaylistAliasResource::buildLiveGroupSortItems([], ['Sports'], $this->playlist->id);
        $first = array_values($items)[0];

        expect($first['name'])->toBe('Sports')
            ->and($first['label'])->toBe('UK Sports HD');
    });

    it('buildLiveGroupSortItems falls back to the internal name when no group is imported', function () {
        $items = PlaylistAliasResource::buildLiveGroupSortItems([], ['News'], $this->playlist->id);
        $first = array_values($items)[0];

        expect($first['label'])->toBe('News');
    });

    it('buildLiveGroupSortItems resolves the live label, ignoring a same-named VOD group', function () {
        // Live "Sports" renamed to "UK Sports HD"; a VOD group shares name_internal
        // "Sports" but is renamed differently. The live label must win.
        makeLiveGroup($this->user, $this->playlist, 'Sports', 1, customName: 'UK Sports HD');
        Group::factory()->for($this->playlist)->for($this->user)->create([
            'name' => 'Movie Sports',
            'name_internal' => 'Sports',
            'type' => 'vod',
        ]);

        $items = PlaylistAliasResource::buildLiveGroupSortItems([], ['Sports'], $this->playlist->id);
        $first = array_values($items)[0];

        expect($first['label'])->toBe('UK Sports HD');
    });

    it('liveGroupSortNames reads both item and flat-string shapes', function () {
        $itemShape = [
            'uuid-1' => ['name' => 'Sports', 'label' => 'UK Sports HD'],
            'uuid-2' => ['name' => 'News', 'label' => 'News'],
        ];

        expect(PlaylistAliasResource::liveGroupSortNames($itemShape))->toBe(['Sports', 'News'])
            ->and(PlaylistAliasResource::liveGroupSortNames(['Sports', 'News']))->toBe(['Sports', 'News'])
            ->and(PlaylistAliasResource::liveGroupSortNames(null))->toBe([]);
    });

    it('liveGroupSortSelectedNames maps source group ids to names, preserving selection order', function () {
        $sports = SourceGroup::create(['playlist_id' => $this->playlist->id, 'name' => 'Sports', 'type' => 'live']);
        $news = SourceGroup::create(['playlist_id' => $this->playlist->id, 'name' => 'News', 'type' => 'live']);

        // Selection order: News then Sports.
        $names = PlaylistAliasResource::liveGroupSortSelectedNames([$news->id, $sports->id], $this->playlist->id);

        expect($names)->toBe(['News', 'Sports']);
    });

    it('liveGroupSortSelectedNames passes through already-stored names', function () {
        $names = PlaylistAliasResource::liveGroupSortSelectedNames(['News', 'Sports'], $this->playlist->id);

        expect($names)->toBe(['News', 'Sports']);
    });
});

// ── SourceGroup display label resolution ──────────────────────────────────────

describe('SourceGroup::displayLabelsForIds', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->playlist = Playlist::factory()->for($this->user)->create();
    });

    it('returns the imported custom name when the group has been imported', function () {
        $sg = SourceGroup::create(['playlist_id' => $this->playlist->id, 'name' => 'Sports', 'type' => 'live']);
        makeLiveGroup($this->user, $this->playlist, 'Sports', 1, customName: 'UK Sports HD');

        $labels = SourceGroup::displayLabelsForIds($this->playlist->id, 'live', [$sg->id]);

        expect($labels[$sg->id])->toBe('UK Sports HD');
    });

    it('falls back to the source name when no imported group exists', function () {
        $sg = SourceGroup::create(['playlist_id' => $this->playlist->id, 'name' => 'News', 'type' => 'live']);

        $labels = SourceGroup::displayLabelsForIds($this->playlist->id, 'live', [$sg->id]);

        expect($labels[$sg->id])->toBe('News');
    });

    it('returns an empty array for an empty id list', function () {
        expect(SourceGroup::displayLabelsForIds($this->playlist->id, 'live', []))->toBe([]);
    });
});

// ── Filament edit form renders with the sort pane ─────────────────────────────

it('renders the alias edit form with the custom sort pane enabled', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $playlist = Playlist::factory()->for($user)->create();
    makeLiveGroup($user, $playlist, 'Sports', 1, customName: 'UK Sports HD');

    $alias = makeSortAlias($user, $playlist, [
        'selected_groups' => ['Sports'],
        'sort_live_groups_custom' => true,
        'live_group_order' => ['Sports'],
    ]);

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->assertSuccessful();
});
