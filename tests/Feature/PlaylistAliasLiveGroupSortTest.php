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
use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\SourceGroup;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
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

    it('ranks a bouquet-contributed group in the CASE ELSE bucket after an explicitly ordered manual group', function () {
        // A Group's natural sort_order (1) would put it first; only the CASE
        // ordering - with B Group explicitly ranked and A Group falling into the
        // ELSE bucket - can put B Group first instead.
        $aGroup = makeLiveGroup($this->user, $this->playlist, 'A Group', 1);
        $bGroup = makeLiveGroup($this->user, $this->playlist, 'B Group', 2);
        $aCh = makeLiveChannel($this->user, $this->playlist, $aGroup, 'A Channel');
        $bCh = makeLiveChannel($this->user, $this->playlist, $bGroup, 'B Channel');

        $alias = makeSortAlias($this->user, $this->playlist, [
            'selected_groups' => ['B Group'],
            'sort_live_groups_custom' => true,
            'live_group_order' => ['B Group'],
        ]);

        // Bouquet contributes 'A Group' to the union - it is never in the manual
        // live_group_order, so it must land in the CASE ELSE bucket.
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['A Group']],
        ]);
        $alias->bouquets()->attach($bouquet);
        $alias->refresh();

        $ids = PlaylistGenerateController::getChannelQuery($alias)->get()->pluck('id')->all();

        expect($ids)->toBe([$bCh->id, $aCh->id]);
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

    it('buildLiveGroupSortItems appends newly selected groups in playlist order', function () {
        makeLiveGroup($this->user, $this->playlist, 'FR| FRANCE 4K', 1);
        makeLiveGroup($this->user, $this->playlist, 'FR| FRANCE VIP', 2);
        makeLiveGroup($this->user, $this->playlist, 'FR| CANAL+ LIVE', 3);

        $items = PlaylistAliasResource::buildLiveGroupSortItems(
            ['Kept'],
            ['FR| CANAL+ LIVE', 'Unknown', 'FR| FRANCE VIP', 'Kept', 'FR| FRANCE 4K'],
            $this->playlist->id,
        );

        expect(array_column(array_values($items), 'name'))
            ->toBe(['Kept', 'FR| FRANCE 4K', 'FR| FRANCE VIP', 'FR| CANAL+ LIVE', 'Unknown']);
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

// ── Bouquet groups in the sort pane ───────────────────────────────────────────

describe('bouquet groups in the custom sort pane', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->playlist = Playlist::factory()->for($this->user)->create();

        // The live picker holds SourceGroup ids while editing, so each group needs
        // a source row as well as an imported one.
        foreach (['Sports', 'News', 'Movies'] as $index => $name) {
            makeLiveGroup($this->user, $this->playlist, $name, $index + 1);
            SourceGroup::create(['playlist_id' => $this->playlist->id, 'name' => $name, 'type' => 'live']);
        }

        $this->bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['News', 'Sports']],
        ]);
    });

    $sortNames = fn (Testable $livewire): array => PlaylistAliasResource::liveGroupSortNames($livewire->get('data.group_filter.live_group_order'));

    // Bouquet-only groups are appended in the playlist's group order (Sports=1,
    // News=2), not the order the bouquet lists them.
    it('lists bouquet-only groups after the saved order when the form loads', function () use ($sortNames) {
        $alias = makeSortAlias($this->user, $this->playlist, [
            'selected_groups' => ['Movies'],
            'sort_live_groups_custom' => true,
            'live_group_order' => ['Movies'],
        ]);
        $alias->bouquets()->attach($this->bouquet);

        $livewire = Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()]);

        expect($sortNames($livewire))->toBe(['Movies', 'Sports', 'News']);
    });

    it('badges each sort item with the bouquets contributing it', function () {
        $second = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'name' => 'Second Bouquet',
            'group_selections' => ['selected_groups' => ['News']],
        ]);
        $alias = makeSortAlias($this->user, $this->playlist, [
            'selected_groups' => ['Movies'],
            'sort_live_groups_custom' => true,
            'live_group_order' => ['Movies'],
        ]);
        $alias->bouquets()->attach([$this->bouquet->id, $second->id]);

        $livewire = Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Second Bouquet');

        $bouquetsByGroup = collect($livewire->get('data.group_filter.live_group_order'))
            ->mapWithKeys(fn (array $item): array => [$item['name'] => $item['bouquets']])
            ->all();

        expect($bouquetsByGroup)->toBe([
            'Movies' => [],
            'Sports' => [$this->bouquet->name],
            'News' => [$this->bouquet->name, 'Second Bouquet'],
        ]);
    });

    it('keeps the saved position of a bouquet group', function () use ($sortNames) {
        $alias = makeSortAlias($this->user, $this->playlist, [
            'selected_groups' => ['Movies'],
            'sort_live_groups_custom' => true,
            'live_group_order' => ['Sports', 'Movies', 'News'],
        ]);
        $alias->bouquets()->attach($this->bouquet);

        $livewire = Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()]);

        expect($sortNames($livewire))->toBe(['Sports', 'Movies', 'News']);
    });

    it('adds and removes bouquet groups as bouquets are assigned', function () use ($sortNames) {
        $alias = makeSortAlias($this->user, $this->playlist, [
            'selected_groups' => ['Movies'],
            'sort_live_groups_custom' => true,
            'live_group_order' => ['Movies'],
        ]);

        $livewire = Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
            ->set('data.bouquets', [$this->bouquet->id]);

        expect($sortNames($livewire))->toBe(['Movies', 'Sports', 'News']);

        $livewire->set('data.bouquets', []);

        expect($sortNames($livewire))->toBe(['Movies']);
    });

    it('seeds the order with bouquet groups in playlist order when custom sort is enabled', function () use ($sortNames) {
        $alias = makeSortAlias($this->user, $this->playlist, ['selected_groups' => ['Movies']]);
        $alias->bouquets()->attach($this->bouquet);

        $livewire = Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
            ->set('data.group_filter.sort_live_groups_custom', true);

        expect($sortNames($livewire))->toBe(['Sports', 'News', 'Movies']);
    });

    it('resets a saved custom order back to playlist order', function () use ($sortNames) {
        $alias = makeSortAlias($this->user, $this->playlist, [
            'selected_groups' => ['Movies'],
            'sort_live_groups_custom' => true,
            'live_group_order' => ['Movies', 'News', 'Sports'],
        ]);
        $alias->bouquets()->attach($this->bouquet);

        $livewire = Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
            ->callAction(TestAction::make('reset_live_group_order')->schemaComponent('group_filter.live_group_order'));

        expect($sortNames($livewire))->toBe(['Sports', 'News', 'Movies']);
    });

    it('persists a bouquet group position that the output honours', function () {
        $sportsGroup = Group::where('playlist_id', $this->playlist->id)->where('name_internal', 'Sports')->sole();
        $newsGroup = Group::where('playlist_id', $this->playlist->id)->where('name_internal', 'News')->sole();
        $sportsCh = makeLiveChannel($this->user, $this->playlist, $sportsGroup, 'Sports Channel');
        $newsCh = makeLiveChannel($this->user, $this->playlist, $newsGroup, 'News Channel');

        $alias = makeSortAlias($this->user, $this->playlist, ['sort_live_groups_custom' => true]);
        $alias->update(['xtream_config' => [[
            'url' => 'http://example.com:8080',
            'username' => 'alias-user',
            'password' => 'alias-pass',
        ]]]);
        $alias->bouquets()->attach($this->bouquet);

        $livewire = Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()]);
        $items = $livewire->get('data.group_filter.live_group_order');
        $byName = collect($items)->mapWithKeys(fn (array $item, string $key): array => [$item['name'] => $key]);

        // Drag News ahead of Sports, overriding the groups' natural sort_order.
        $livewire->set('data.group_filter.live_group_order', [
            $byName['News'] => $items[$byName['News']],
            $byName['Sports'] => $items[$byName['Sports']],
        ])
            ->call('save')
            ->assertHasNoFormErrors();

        $alias->refresh();

        expect($alias->getLiveGroupSortOrder())->toBe(['News', 'Sports'])
            ->and(PlaylistGenerateController::getChannelQuery($alias)->get()->pluck('id')->all())
            ->toBe([$newsCh->id, $sportsCh->id]);
    });
});
