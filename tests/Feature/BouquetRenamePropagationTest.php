<?php

use App\Jobs\ProcessM3uImport;
use App\Models\Bouquet;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\SourceGroup;
use App\Models\User;
use Illuminate\Support\Collection;

function runSyncSourceGroupType(Playlist $playlist, Collection $groups, string $type = 'live', array $currentSelected = []): array
{
    $job = new ProcessM3uImport($playlist, force: true, isNew: false);
    $method = new ReflectionMethod($job, 'syncSourceGroupType');

    $selectedKey = $type === 'vod' ? 'selected_vod_groups' : 'selected_groups';

    return $method->invoke($job, $groups, $type, $selectedKey, $currentSelected, $playlist);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create([
        'import_prefs' => [],
    ]));
});

describe('provider rename propagation', function () {
    beforeEach(function () {
        SourceGroup::create([
            'name' => 'Sports', 'playlist_id' => $this->playlist->id,
            'source_group_id' => 101, 'type' => 'live',
        ]);
    });

    it('rewrites bouquet selections when a tracked group is renamed', function () {
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['Sports', 'News'], 'selected_vod_groups' => ['Sports']],
        ]);

        runSyncSourceGroupType($this->playlist, collect([
            ['category_id' => 101, 'category_name' => 'Sports HD'],
        ]));

        $bouquet->refresh();
        expect($bouquet->getSelectedLiveGroupNames())->toBe(['Sports HD', 'News'])
            // VOD selections are untouched by a live-type rename pass.
            ->and($bouquet->getSelectedVodGroupNames())->toBe(['Sports']);
    });

    it('rewrites alias manual group_filter and live_group_order (companion fix)', function () {
        $alias = PlaylistAlias::create([
            'name' => 'A', 'uuid' => fake()->uuid(), 'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id, 'xtream_config' => null,
            'group_filter' => [
                'selected_groups' => ['Sports'],
                'sort_live_groups_custom' => true,
                'live_group_order' => ['Sports'],
            ],
        ]);

        runSyncSourceGroupType($this->playlist, collect([
            ['category_id' => 101, 'category_name' => 'Sports HD'],
        ]));

        $alias->refresh();
        expect($alias->group_filter['selected_groups'])->toBe(['Sports HD'])
            ->and($alias->group_filter['live_group_order'])->toBe(['Sports HD']);
    });

    it('does not touch bouquets of other playlists or custom-target bouquets', function () {
        $otherPlaylist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create(['import_prefs' => []]));
        $otherBouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $otherPlaylist->id,
            'group_selections' => ['selected_groups' => ['Sports']],
        ]);
        $custom = CustomPlaylist::create(['name' => 'CP', 'user_id' => $this->user->id, 'id_channel_by' => 'stream_id']);
        $customBouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => null,
            'custom_playlist_id' => $custom->id,
            'group_selections' => ['selected_groups' => ['Sports']],
        ]);

        runSyncSourceGroupType($this->playlist, collect([
            ['category_id' => 101, 'category_name' => 'Sports HD'],
        ]));

        expect($otherBouquet->refresh()->getSelectedLiveGroupNames())->toBe(['Sports'])
            ->and($customBouquet->refresh()->getSelectedLiveGroupNames())->toBe(['Sports']);
    });

    it('still propagates renames into import_prefs (existing behavior pinned)', function () {
        [$selected] = runSyncSourceGroupType($this->playlist, collect([
            ['category_id' => 101, 'category_name' => 'Sports HD'],
        ]), 'live', ['Sports']);

        expect($selected)->toBe(['Sports HD'])
            ->and($this->playlist->refresh()->import_prefs['selected_groups'])->toBe(['Sports HD']);
    });
});

describe('auto-include new groups', function () {
    it('appends genuinely new group names to flagged bouquets only', function () {
        SourceGroup::create([
            'name' => 'Existing', 'playlist_id' => $this->playlist->id,
            'source_group_id' => 201, 'type' => 'live',
        ]);
        $flagged = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'auto_include_new_live' => true,
            'group_selections' => ['selected_groups' => ['Existing']],
        ]);
        $unflagged = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'group_selections' => ['selected_groups' => ['Existing']],
        ]);

        runSyncSourceGroupType($this->playlist, collect([
            ['category_id' => 201, 'category_name' => 'Existing'],
            ['category_id' => 202, 'category_name' => 'Brand New'],
        ]));

        expect($flagged->refresh()->getSelectedLiveGroupNames())->toBe(['Existing', 'Brand New'])
            ->and($unflagged->refresh()->getSelectedLiveGroupNames())->toBe(['Existing']);
    });

    it('does not treat a renamed group as new', function () {
        SourceGroup::create([
            'name' => 'Old Name', 'playlist_id' => $this->playlist->id,
            'source_group_id' => 301, 'type' => 'live',
        ]);
        $flagged = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'auto_include_new_live' => true,
            'group_selections' => ['selected_groups' => []],
        ]);

        runSyncSourceGroupType($this->playlist, collect([
            ['category_id' => 301, 'category_name' => 'New Name'],
        ]));

        expect($flagged->refresh()->getSelectedLiveGroupNames())->toBe([]);
    });

    it('respects the vod flag independently', function () {
        $bouquet = Bouquet::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'auto_include_new_live' => false,
            'auto_include_new_vod' => true,
        ]);

        runSyncSourceGroupType($this->playlist, collect([
            ['category_id' => 401, 'category_name' => 'Fresh VOD'],
        ]), 'vod');

        expect($bouquet->refresh()->getSelectedVodGroupNames())->toBe(['Fresh VOD'])
            ->and($bouquet->getSelectedLiveGroupNames())->toBe([]);
    });
});
