<?php

/**
 * Issue #1457 (Pass 2): per-alias group/category filtering for aliases that wrap a
 * MergedPlaylist. Selections are stored as {playlist_id, name} pairs so a group or
 * category can be allowed from one source playlist without also allowing a
 * same-named group from another source.
 */

use App\Filament\GuestPanel\Resources\Series\SeriesResource as GuestSeriesResource;
use App\Filament\GuestPanel\Resources\Vods\VodResource as GuestVodResource;
use App\Filament\Resources\PlaylistAliases\Pages\CreatePlaylistAlias;
use App\Filament\Resources\PlaylistAliases\Pages\EditPlaylistAlias;
use App\Filament\Resources\PlaylistAliases\PlaylistAliasResource;
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

function mergedFilterGroup(User $user, Playlist $playlist, string $name, string $type): Group
{
    return Group::factory()->for($playlist)->for($user)->create([
        'name' => $name,
        'name_internal' => $name,
        'type' => $type,
    ]);
}

function mergedFilterChannel(User $user, Playlist $playlist, Group $group, string $title, bool $isVod): Channel
{
    return Channel::factory()->for($user)->for($playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => $isVod,
        'group' => $group->name,
        'group_internal' => $group->name_internal,
        'title' => $title,
        'name' => $title,
        'url' => 'http://example.com/'.Str::slug($title),
    ]);
}

function mergedFilterAlias(User $user, MergedPlaylist $merged, array $groupFilter): PlaylistAlias
{
    return PlaylistAlias::create([
        'merged_playlist_id' => $merged->id,
        'user_id' => $user->id,
        'name' => 'Merged Filter Alias',
        'uuid' => Str::uuid()->toString(),
        'xtream_config' => null,
        'group_filter' => $groupFilter,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'owner']);

    $this->sourceA = Playlist::factory()->for($this->user)->createQuietly(['name' => 'Provider A']);
    $this->sourceB = Playlist::factory()->for($this->user)->createQuietly(['name' => 'Provider B']);

    $this->merged = MergedPlaylist::factory()->for($this->user)->create();
    $this->merged->playlists()->attach([$this->sourceA->id, $this->sourceB->id]);

    // Both sources expose a live "Sports" group and a VOD "Movies" group; A also has "News".
    $this->groupASports = mergedFilterGroup($this->user, $this->sourceA, 'Sports', 'live');
    $this->groupANews = mergedFilterGroup($this->user, $this->sourceA, 'News', 'live');
    $this->groupBSports = mergedFilterGroup($this->user, $this->sourceB, 'Sports', 'live');
    $this->groupAMovies = mergedFilterGroup($this->user, $this->sourceA, 'Movies', 'vod');
    $this->groupBMovies = mergedFilterGroup($this->user, $this->sourceB, 'Movies', 'vod');

    $this->channelASports = mergedFilterChannel($this->user, $this->sourceA, $this->groupASports, 'A Sports', false);
    $this->channelANews = mergedFilterChannel($this->user, $this->sourceA, $this->groupANews, 'A News', false);
    $this->channelBSports = mergedFilterChannel($this->user, $this->sourceB, $this->groupBSports, 'B Sports', false);
    $this->channelAMovies = mergedFilterChannel($this->user, $this->sourceA, $this->groupAMovies, 'A Movies', true);
    $this->channelBMovies = mergedFilterChannel($this->user, $this->sourceB, $this->groupBMovies, 'B Movies', true);

    // Provider-side group records the alias picker selects from.
    $this->sourceGroupASports = SourceGroup::create(['playlist_id' => $this->sourceA->id, 'name' => 'Sports', 'type' => 'live']);
    $this->sourceGroupANews = SourceGroup::create(['playlist_id' => $this->sourceA->id, 'name' => 'News', 'type' => 'live']);
    $this->sourceGroupBSports = SourceGroup::create(['playlist_id' => $this->sourceB->id, 'name' => 'Sports', 'type' => 'live']);
    SourceGroup::create(['playlist_id' => $this->sourceA->id, 'name' => 'Movies', 'type' => 'vod']);
    $this->sourceGroupBMovies = SourceGroup::create(['playlist_id' => $this->sourceB->id, 'name' => 'Movies', 'type' => 'vod']);

    // Series categories: provider A's category 5 is "Drama"; provider B reuses id 5 for
    // "Kids" and has its own "Drama" as id 7. Filtering by name alone would be wrong.
    $this->categoryADrama = Category::factory()->for($this->sourceA)->for($this->user)->create([
        'name' => 'Drama', 'name_internal' => 'Drama', 'source_category_id' => 5,
    ]);
    $this->categoryBKids = Category::factory()->for($this->sourceB)->for($this->user)->create([
        'name' => 'Kids', 'name_internal' => 'Kids', 'source_category_id' => 5,
    ]);
    $this->categoryBDrama = Category::factory()->for($this->sourceB)->for($this->user)->create([
        'name' => 'Drama', 'name_internal' => 'Drama', 'source_category_id' => 7,
    ]);
    $this->sourceCategoryADrama = SourceCategory::create(['playlist_id' => $this->sourceA->id, 'source_category_id' => 5, 'name' => 'Drama']);
    SourceCategory::create(['playlist_id' => $this->sourceB->id, 'source_category_id' => 5, 'name' => 'Kids']);
    SourceCategory::create(['playlist_id' => $this->sourceB->id, 'source_category_id' => 7, 'name' => 'Drama']);

    $this->seriesADrama = Series::factory()->for($this->sourceA)->for($this->user)->create([
        'name' => 'A Drama Show', 'enabled' => true, 'source_category_id' => 5, 'category_id' => $this->categoryADrama->id,
    ]);
    $this->seriesBKids = Series::factory()->for($this->sourceB)->for($this->user)->create([
        'name' => 'B Kids Show', 'enabled' => true, 'source_category_id' => 5, 'category_id' => $this->categoryBKids->id,
    ]);
    $this->seriesBDrama = Series::factory()->for($this->sourceB)->for($this->user)->create([
        'name' => 'B Drama Show', 'enabled' => true, 'source_category_id' => 7, 'category_id' => $this->categoryBDrama->id,
    ]);
});

// ── Model: channels() / series() / groups() ──────────────────────────────────

it('filters merged live channels to the selected source group only', function () {
    $alias = mergedFilterAlias($this->user, $this->merged, [
        'selected_groups' => [['playlist_id' => $this->sourceA->id, 'name' => 'Sports']],
    ]);

    $liveIds = $alias->live_channels()->pluck('channels.id');

    expect($liveIds->all())->toBe([$this->channelASports->id])
        ->and($alias->vod_channels()->count())->toBe(2);
});

it('filters merged VOD channels to the selected source group only', function () {
    $alias = mergedFilterAlias($this->user, $this->merged, [
        'selected_vod_groups' => [['playlist_id' => $this->sourceB->id, 'name' => 'Movies']],
    ]);

    $vodIds = $alias->vod_channels()->pluck('channels.id');

    expect($vodIds->all())->toBe([$this->channelBMovies->id])
        ->and($alias->live_channels()->count())->toBe(3);
});

it('filters merged series by source playlist and category, ignoring a colliding category id from another provider', function () {
    $alias = mergedFilterAlias($this->user, $this->merged, [
        'selected_categories' => [['playlist_id' => $this->sourceA->id, 'name' => 'Drama']],
    ]);

    expect($alias->series()->pluck('series.id')->all())->toBe([$this->seriesADrama->id]);
});

it('exposes distinct names and the raw pairs through the accessors', function () {
    $alias = mergedFilterAlias($this->user, $this->merged, [
        'selected_groups' => [
            ['playlist_id' => $this->sourceA->id, 'name' => 'Sports'],
            ['playlist_id' => $this->sourceB->id, 'name' => 'Sports'],
        ],
    ]);

    expect($alias->getAllowedLiveGroupNames())->toBe(['Sports'])
        ->and($alias->getAllowedLiveGroupSelections())->toHaveCount(2)
        ->and($alias->hasGroupFilter())->toBeTrue()
        ->and($alias->live_channels()->count())->toBe(2);
});

it('scopes the merged alias groups() relation to the selected source groups', function () {
    $alias = mergedFilterAlias($this->user, $this->merged, [
        'selected_groups' => [['playlist_id' => $this->sourceA->id, 'name' => 'Sports']],
    ]);

    $liveGroupIds = $alias->groups()->where('groups.type', 'live')->pluck('groups.id');

    expect($liveGroupIds->all())->toBe([$this->groupASports->id])
        // VOD groups are untouched by a live-only filter.
        ->and($alias->groups()->where('groups.type', 'vod')->count())->toBe(2);
});

// ── Output: M3U and Xtream API ───────────────────────────────────────────────

it('emits only the selected source group in the generated M3U', function () {
    $alias = mergedFilterAlias($this->user, $this->merged, [
        'selected_groups' => [['playlist_id' => $this->sourceA->id, 'name' => 'Sports']],
    ]);

    $content = $this->get("/{$alias->uuid}/playlist.m3u")->assertOk()->streamedContent();

    expect($content)->toContain('A Sports')
        ->and($content)->not->toContain('B Sports')
        ->and($content)->not->toContain('A News');
});

it('lists only the selected source group as an Xtream live category', function () {
    $alias = mergedFilterAlias($this->user, $this->merged, [
        'selected_groups' => [['playlist_id' => $this->sourceA->id, 'name' => 'Sports']],
    ]);

    $categories = $this->getJson(route('xtream.api.player', [
        'action' => 'get_live_categories',
        'username' => 'owner',
        'password' => $alias->uuid,
    ]))->assertOk()->json();

    expect($categories)->toHaveCount(1)
        ->and($categories[0]['category_name'])->toBe('Sports')
        ->and($categories[0]['category_id'])->toBe((string) $this->groupASports->id);
});

it('returns only the selected source group channels from the Xtream live streams', function () {
    $alias = mergedFilterAlias($this->user, $this->merged, [
        'selected_groups' => [['playlist_id' => $this->sourceA->id, 'name' => 'Sports']],
    ]);

    $names = collect($this->getJson(route('xtream.api.player', [
        'action' => 'get_live_streams',
        'username' => 'owner',
        'password' => $alias->uuid,
    ]))->assertOk()->json())->pluck('name');

    expect($names->all())->toBe(['A Sports']);
});

it('lists only the selected source category as an Xtream series category', function () {
    $alias = mergedFilterAlias($this->user, $this->merged, [
        'selected_categories' => [['playlist_id' => $this->sourceA->id, 'name' => 'Drama']],
    ]);

    $categories = $this->getJson(route('xtream.api.player', [
        'action' => 'get_series_categories',
        'username' => 'owner',
        'password' => $alias->uuid,
    ]))->assertOk()->json();

    expect($categories)->toHaveCount(1)
        ->and($categories[0]['category_name'])->toBe('Drama')
        ->and($categories[0]['category_id'])->toBe((string) $this->categoryADrama->id);
});

// ── Filament form ────────────────────────────────────────────────────────────

it('shows the channel filter for a merged alias and hydrates stored pairs back to source group ids', function () {
    $this->actingAs($this->user);

    $alias = mergedFilterAlias($this->user, $this->merged, [
        'selected_groups' => [['playlist_id' => $this->sourceB->id, 'name' => 'Sports']],
        'selected_vod_groups' => [['playlist_id' => $this->sourceB->id, 'name' => 'Movies']],
        'selected_categories' => [['playlist_id' => $this->sourceA->id, 'name' => 'Drama']],
    ]);

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->assertSee(__('Allowed live groups'))
        ->assertFormSet([
            'group_filter.selected_groups' => [$this->sourceGroupBSports->id],
            'group_filter.selected_vod_groups' => [$this->sourceGroupBMovies->id],
            'group_filter.selected_categories' => [$this->sourceCategoryADrama->id],
        ]);
});

it('saves merged picker selections as playlist-scoped pairs', function () {
    $this->actingAs($this->user);

    Livewire::test(CreatePlaylistAlias::class)
        ->fillForm([
            'name' => 'Created Merged Filter Alias',
            'source_type' => 'merged_playlist',
            'source_id' => $this->merged->id,
            'merged_playlist_id' => $this->merged->id,
            'xtream_config' => [[
                'url' => 'http://provider.example.com:8080',
                'username' => 'newuser',
                'password' => 'newpass',
            ]],
            'group_filter.selected_groups' => [$this->sourceGroupBSports->id],
            'group_filter.selected_vod_groups' => [$this->sourceGroupBMovies->id],
            'group_filter.selected_categories' => [$this->sourceCategoryADrama->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $alias = PlaylistAlias::where('name', 'Created Merged Filter Alias')->firstOrFail();

    expect($alias->group_filter['selected_groups'])->toEqual([['playlist_id' => $this->sourceB->id, 'name' => 'Sports']])
        ->and($alias->group_filter['selected_vod_groups'])->toEqual([['playlist_id' => $this->sourceB->id, 'name' => 'Movies']])
        ->and($alias->group_filter['selected_categories'])->toEqual([['playlist_id' => $this->sourceA->id, 'name' => 'Drama']])
        ->and($alias->live_channels()->pluck('channels.id')->all())->toBe([$this->channelBSports->id]);
});

it('resolves live group sort names and display labels across all merged source playlists', function () {
    $playlistIds = [$this->sourceA->id, $this->sourceB->id];

    $names = PlaylistAliasResource::liveGroupSortSelectedNames(
        [$this->sourceGroupBSports->id, $this->sourceGroupANews->id],
        $playlistIds,
    );
    $labels = SourceGroup::displayLabelsForIds($playlistIds, 'live', [$this->sourceGroupBSports->id, $this->sourceGroupANews->id]);

    expect($names)->toBe(['Sports', 'News'])
        ->and($labels[$this->sourceGroupBSports->id])->toContain('Sports')
        ->and($labels[$this->sourceGroupANews->id])->toContain('News');
});

it('limits merged source playlist ids to the sources contributing a content type', function () {
    $this->merged->playlists()->updateExistingPivot($this->sourceA->id, ['include_vod' => false]);

    expect($this->merged->fresh()->sourcePlaylistIds())->toBe([$this->sourceA->id, $this->sourceB->id])
        ->and($this->merged->fresh()->sourcePlaylistIds('vod'))->toBe([$this->sourceB->id])
        ->and($this->merged->fresh()->sourcePlaylistIds('live'))->toBe([$this->sourceA->id, $this->sourceB->id]);
});

// ── Guest panel ──────────────────────────────────────────────────────────────

it('applies the merged alias VOD filter in the guest panel', function () {
    $alias = mergedFilterAlias($this->user, $this->merged, [
        'selected_vod_groups' => [['playlist_id' => $this->sourceB->id, 'name' => 'Movies']],
    ]);
    request()->attributes->set('playlist_uuid', $alias->uuid);

    expect(GuestVodResource::getEloquentQuery()->pluck('channels.id')->all())->toBe([$this->channelBMovies->id]);
});

it('applies the merged alias series filter in the guest panel', function () {
    $alias = mergedFilterAlias($this->user, $this->merged, [
        'selected_categories' => [['playlist_id' => $this->sourceA->id, 'name' => 'Drama']],
    ]);
    request()->attributes->set('playlist_uuid', $alias->uuid);

    expect(GuestSeriesResource::getEloquentQuery()->pluck('series.id')->all())->toBe([$this->seriesADrama->id]);
});

it('scopes the guest panel to the merged playlist content instead of every channel in the database', function () {
    $other = Playlist::factory()->for($this->user)->createQuietly(['name' => 'Unrelated']);
    $otherVod = Channel::factory()->for($this->user)->for($other)->create([
        'enabled' => true, 'is_vod' => true, 'group_id' => null, 'title' => 'Other VOD', 'name' => 'Other VOD',
    ]);
    request()->attributes->set('playlist_uuid', $this->merged->uuid);

    $ids = GuestVodResource::getEloquentQuery()->pluck('channels.id');

    expect($ids->sort()->values()->all())->toBe(collect([$this->channelAMovies->id, $this->channelBMovies->id])->sort()->values()->all())
        ->and($ids)->not->toContain($otherVod->id);
});

// ── Fail closed on malformed selections ──────────────────────────────────────

it('fails closed when a merged alias stores bare names instead of source-scoped pairs', function () {
    $alias = mergedFilterAlias($this->user, $this->merged, [
        'selected_groups' => ['Sports'],
        'selected_vod_groups' => ['Movies'],
        'selected_categories' => ['Drama'],
    ]);

    expect($alias->hasGroupFilter())->toBeTrue()
        ->and($alias->live_channels()->count())->toBe(0)
        ->and($alias->vod_channels()->count())->toBe(0)
        ->and($alias->series()->count())->toBe(0)
        ->and($alias->groups()->count())->toBe(0);
});

it('fails closed in the guest panel when a merged alias stores bare names', function () {
    $alias = mergedFilterAlias($this->user, $this->merged, [
        'selected_vod_groups' => ['Movies'],
        'selected_categories' => ['Drama'],
    ]);
    request()->attributes->set('playlist_uuid', $alias->uuid);

    expect(GuestVodResource::getEloquentQuery()->count())->toBe(0)
        ->and(GuestSeriesResource::getEloquentQuery()->count())->toBe(0);
});

// ── Ownership of the hidden source ids ───────────────────────────────────────

it('rejects a merged playlist the current user does not own when creating an alias', function () {
    $this->actingAs($this->user);
    $otherMerged = MergedPlaylist::factory()->for(User::factory()->create())->create();

    Livewire::test(CreatePlaylistAlias::class)
        ->fillForm([
            'name' => 'Tampered Merged Alias',
            'source_type' => 'merged_playlist',
            'source_id' => $this->merged->id,
            'xtream_config' => [[
                'url' => 'http://provider.example.com:8080',
                'username' => 'newuser',
                'password' => 'newpass',
            ]],
            'merged_playlist_id' => $otherMerged->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['merged_playlist_id']);

    expect(PlaylistAlias::where('name', 'Tampered Merged Alias')->exists())->toBeFalse();
});

it('rejects a playlist the current user does not own when creating an alias', function () {
    $this->actingAs($this->user);
    $otherPlaylist = Playlist::factory()->for(User::factory()->create())->createQuietly();

    Livewire::test(CreatePlaylistAlias::class)
        ->fillForm([
            'name' => 'Tampered Playlist Alias',
            'source_type' => 'playlist',
            'source_id' => $this->sourceA->id,
            'xtream_config' => [[
                'url' => 'http://provider.example.com:8080',
                'username' => 'newuser',
                'password' => 'newpass',
            ]],
            'playlist_id' => $otherPlaylist->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['playlist_id']);

    expect(PlaylistAlias::where('name', 'Tampered Playlist Alias')->exists())->toBeFalse();
});

it('resolves picker source playlist ids only for playlists the current user owns', function () {
    $this->actingAs($this->user);
    $otherUser = User::factory()->create();
    $otherPlaylist = Playlist::factory()->for($otherUser)->createQuietly();
    $otherMerged = MergedPlaylist::factory()->for($otherUser)->create();
    $otherMerged->playlists()->attach($otherPlaylist->id);

    expect(PlaylistAliasResource::mergedSourcePlaylistIds($this->merged->id, null))->toBe([$this->sourceA->id, $this->sourceB->id])
        ->and(PlaylistAliasResource::mergedSourcePlaylistIds($otherMerged->id, null))->toBe([])
        ->and(PlaylistAliasResource::ownedPlaylistIds($this->sourceA->id))->toBe([$this->sourceA->id])
        ->and(PlaylistAliasResource::ownedPlaylistIds($otherPlaylist->id))->toBe([]);
});

// ── Picker labels and form guidance ──────────────────────────────────────────

it('appends the source playlist name to group and category labels only when asked', function () {
    $playlistIds = [$this->sourceA->id, $this->sourceB->id];

    expect(SourceGroup::displayLabelsForIds($playlistIds, 'live', [$this->sourceGroupBSports->id], includePlaylistName: true))
        ->toBe([$this->sourceGroupBSports->id => 'Sports (Provider B)'])
        ->and(SourceCategory::displayLabelsForIds($playlistIds, [$this->sourceCategoryADrama->id], includePlaylistName: true))
        ->toBe([$this->sourceCategoryADrama->id => 'Drama (Provider A)'])
        ->and(SourceGroup::displayLabelsForIds($this->sourceA->id, 'live', [$this->sourceGroupANews->id]))
        ->toBe([$this->sourceGroupANews->id => 'News'])
        ->and(SourceCategory::displayLabelsForIds($this->sourceA->id, [$this->sourceCategoryADrama->id]))
        ->toBe([$this->sourceCategoryADrama->id => 'Drama']);
});

it('shows the merged-alias guidance callout instead of the custom-playlist one', function () {
    $this->actingAs($this->user);

    $alias = mergedFilterAlias($this->user, $this->merged, [
        'selected_groups' => [['playlist_id' => $this->sourceA->id, 'name' => 'Sports']],
    ]);

    Livewire::test(EditPlaylistAlias::class, ['record' => $alias->getRouteKey()])
        ->assertSee(__('Groups and categories are listed per source playlist. A selection only allows that group from the playlist it was picked from, so a same-named group in another source stays filtered out unless you select it too.'))
        ->assertDontSee(__('The lists below combine any groups you created in the custom playlist with the original source playlist groups.'));
});
