<?php

use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use App\Models\SourceGroup;
use App\Models\User;
use Illuminate\Support\Collection;

function runSyncSourceGroupTypeForPrune(
    Playlist $playlist,
    Collection $groups,
    string $type = 'live',
): array {
    $job = new ProcessM3uImport($playlist, force: true, isNew: false);
    $method = new ReflectionMethod($job, 'syncSourceGroupType');

    $selectedKey = $type === 'vod' ? 'selected_vod_groups' : 'selected_groups';

    return $method->invoke($job, $groups, $type, $selectedKey, [], $playlist);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create([
        'import_prefs' => [],
    ]));
});

describe('SourceGroup stale-prune guard (issue #1530)', function () {
    it('does not prune when the feed comes back empty', function () {
        SourceGroup::create([
            'name' => 'Stale Group', 'playlist_id' => $this->playlist->id,
            'source_group_id' => 101, 'type' => 'live',
        ]);

        runSyncSourceGroupTypeForPrune($this->playlist, collect([]), 'live');

        expect(SourceGroup::where('playlist_id', $this->playlist->id)->where('type', 'live')->count())->toBe(1);
    });

    it('prunes rows no longer in a non-empty feed', function () {
        SourceGroup::create([
            'name' => 'Stale Group', 'playlist_id' => $this->playlist->id,
            'source_group_id' => 101, 'type' => 'live',
        ]);

        runSyncSourceGroupTypeForPrune($this->playlist, collect([
            ['category_id' => 202, 'category_name' => 'Fresh Group'],
        ]), 'live');

        expect(SourceGroup::where('playlist_id', $this->playlist->id)->where('type', 'live')->pluck('name')->all())
            ->toBe(['Fresh Group']);
    });

    it('does not leave the old row behind when a rename collides with a stale row\'s old name', function () {
        // Category id 1 is being renamed from "Old" to "Target" this run. A legacy
        // null-source_group_id row already sits on the name "Target", but the feed no
        // longer references it under any name, so it's stale and should be cleared out
        // of the way before the rename runs, letting category 1 claim "Target" cleanly.
        SourceGroup::create([
            'name' => 'Old', 'playlist_id' => $this->playlist->id,
            'source_group_id' => 1, 'type' => 'live',
        ]);
        SourceGroup::create([
            'name' => 'Target', 'playlist_id' => $this->playlist->id,
            'source_group_id' => null, 'type' => 'live',
        ]);

        runSyncSourceGroupTypeForPrune($this->playlist, collect([
            ['category_id' => 1, 'category_name' => 'Target'],
        ]), 'live');

        $rows = SourceGroup::where('playlist_id', $this->playlist->id)->where('type', 'live')->get();
        expect($rows)->toHaveCount(1);
        expect($rows->first()->name)->toBe('Target');
        expect($rows->first()->source_group_id)->toBe(1);
    });

    it('does not steal source_group_id from a still-current row when two live categories collide on name', function () {
        // Both category ids 1 and 2 are present in the feed this run. Category 1 wants
        // to rename to "Target", which category 2 already legitimately owns. This is a
        // genuine same-run collision (not a stale row), so category 1's upsert must be
        // skipped rather than overwriting category 2's source_group_id.
        SourceGroup::create([
            'name' => 'Old', 'playlist_id' => $this->playlist->id,
            'source_group_id' => 1, 'type' => 'live',
        ]);
        SourceGroup::create([
            'name' => 'Target', 'playlist_id' => $this->playlist->id,
            'source_group_id' => 2, 'type' => 'live',
        ]);

        runSyncSourceGroupTypeForPrune($this->playlist, collect([
            ['category_id' => 1, 'category_name' => 'Target'],
            ['category_id' => 2, 'category_name' => 'Target'],
        ]), 'live');

        $rows = SourceGroup::where('playlist_id', $this->playlist->id)->where('type', 'live')->get()->keyBy('source_group_id');
        expect($rows->get(1)?->name)->toBe('Old');
        expect($rows->get(2)?->name)->toBe('Target');
    });
});
