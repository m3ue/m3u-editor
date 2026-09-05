<?php

use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\Tag;

function makeResolutionAlias(User $user, Playlist $playlist, array $overrides = []): PlaylistAlias
{
    return PlaylistAlias::create(array_merge([
        'name' => 'Test Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'xtream_config' => null,
    ], $overrides));
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

describe('accessor union', function () {
    it('returns just the manual selection when no bouquets are attached', function () {
        $alias = makeResolutionAlias($this->user, $this->playlist, [
            'group_filter' => ['selected_groups' => ['Sports', 'Sports']],  // duplicate on purpose
        ]);

        // The bouquet-less path returns the manual selection with no bouquet
        // union. The accessor normalises the stored selection (dedupe, and parse
        // the {playlist_id, name} pair shape merged aliases use) via
        // PlaylistAlias::selectionNames(), so a stored duplicate collapses.
        expect($alias->getAllowedLiveGroupNames())->toBe(['Sports'])
            ->and($alias->getAllowedVodGroupNames())->toBe([])
            ->and($alias->getAllowedCategoryNames())->toBe([]);
    });

    it('unions manual and bouquet selections with dedupe, per type independently', function () {
        $alias = makeResolutionAlias($this->user, $this->playlist, [
            'group_filter' => ['selected_groups' => ['Sports'], 'selected_categories' => ['Drama']],
        ]);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => [
                'selected_groups' => ['Sports', 'News'],
                'selected_vod_groups' => ['Movies 4K'],
            ],
        ]);
        $alias->bouquets()->attach($bouquet);
        $alias->refresh();

        expect($alias->getAllowedLiveGroupNames())->toEqualCanonicalizing(['Sports', 'News'])
            ->and($alias->getAllowedVodGroupNames())->toBe(['Movies 4K'])
            ->and($alias->getAllowedCategoryNames())->toBe(['Drama']);
    });

    it('reports hasGroupFilter() true for a bouquet-only alias', function () {
        $alias = makeResolutionAlias($this->user, $this->playlist, ['group_filter' => null]);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['Sports']],
        ]);
        $alias->bouquets()->attach($bouquet);
        $alias->refresh();

        expect($alias->hasGroupFilter())->toBeTrue();
    });

    it('fails open when every attached bouquet is empty and there is no manual filter', function () {
        $alias = makeResolutionAlias($this->user, $this->playlist, ['group_filter' => null]);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => null,
        ]);
        $alias->bouquets()->attach($bouquet);
        $alias->refresh();

        expect($alias->getAllowedLiveGroupNames())->toBe([])
            ->and($alias->hasGroupFilter())->toBeFalse();
    });

    it('filters channels() through the union end to end', function () {
        $alias = makeResolutionAlias($this->user, $this->playlist, [
            'group_filter' => ['selected_groups' => ['Sports']],
        ]);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['News']],
        ]);
        $alias->bouquets()->attach($bouquet);
        $alias->refresh();

        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'Sports', 'is_vod' => false, 'enabled' => true,
        ]);
        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'News', 'is_vod' => false, 'enabled' => true,
        ]);
        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'Docs', 'is_vod' => false, 'enabled' => true,
        ]);

        $groups = $alias->channels()->pluck('channels.group_internal');
        expect($groups)->toContain('Sports')
            ->and($groups)->toContain('News')
            ->and($groups)->not->toContain('Docs');
    });

    it('a vanished bouquet name is harmless in the whereIn', function () {
        $alias = makeResolutionAlias($this->user, $this->playlist);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['Ghost Group', 'Sports']],
        ]);
        $alias->bouquets()->attach($bouquet);
        $alias->refresh();

        Channel::factory()->for($this->playlist)->for($this->user)->create([
            'group_internal' => 'Sports', 'is_vod' => false, 'enabled' => true,
        ]);

        expect($alias->channels()->count())->toBe(1);
    });
});

describe('custom-target resolution', function () {
    it('unions bouquet tag names into the custom constraint path', function () {
        $custom = CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id, 'id_channel_by' => 'stream_id']);
        $alias = makeResolutionAlias($this->user, $this->playlist, [
            'playlist_id' => null,
            'custom_playlist_id' => $custom->id,
            'group_filter' => null,
        ]);

        $tagged = Channel::factory()->for($this->playlist)->for($this->user)->create([
            'is_vod' => false, 'enabled' => true, 'group' => null,
        ]);
        $fallback = Channel::factory()->for($this->playlist)->for($this->user)->create([
            'is_vod' => false, 'enabled' => true, 'group' => 'Provider News',
        ]);
        $excluded = Channel::factory()->for($this->playlist)->for($this->user)->create([
            'is_vod' => false, 'enabled' => true, 'group' => 'Provider Docs',
        ]);
        $custom->channels()->attach([$tagged->id, $fallback->id, $excluded->id]);

        $tag = Tag::findOrCreate('My Custom Group', $custom->uuid);
        $tagged->attachTag($tag);

        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => null,
            'custom_playlist_id' => $custom->id,
            'group_selections' => ['selected_groups' => ['My Custom Group', 'Provider News']],
        ]);
        $alias->bouquets()->attach($bouquet);
        $alias->refresh();

        $ids = $alias->channels()->pluck('channels.id');
        expect($ids)->toContain($tagged->id)
            ->and($ids)->toContain($fallback->id)
            ->and($ids)->not->toContain($excluded->id);
    });
});

describe('query cost', function () {
    it('memoizes the bouquet lookup: one pivot query across repeated accessor calls', function () {
        $alias = makeResolutionAlias($this->user, $this->playlist);
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['Sports']],
        ]);
        $alias->bouquets()->attach($bouquet);
        $alias = PlaylistAlias::find($alias->id);

        DB::enableQueryLog();
        $alias->getAllowedLiveGroupNames();
        $alias->getAllowedVodGroupNames();
        $alias->getAllowedCategoryNames();
        $alias->hasGroupFilter();
        $bouquetQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_contains($entry['query'], 'bouquet'));
        DB::disableQueryLog();

        expect($bouquetQueries)->toHaveCount(1);
    });

    it('runs zero bouquet queries for a merged-playlist alias', function () {
        $merged = MergedPlaylist::create(['name' => 'MP', 'user_id' => $this->user->id, 'id_channel_by' => 'stream_id']);
        $alias = makeResolutionAlias($this->user, $this->playlist, [
            'playlist_id' => null,
            'merged_playlist_id' => $merged->id,
        ]);
        $alias = PlaylistAlias::find($alias->id);

        DB::enableQueryLog();
        $alias->getAllowedLiveGroupNames();
        $alias->hasGroupFilter();
        $bouquetQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_contains($entry['query'], 'bouquet'));
        DB::disableQueryLog();

        expect($bouquetQueries)->toHaveCount(0);
    });
});
