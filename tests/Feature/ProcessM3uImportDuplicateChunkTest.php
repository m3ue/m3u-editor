<?php

/**
 * Regression test for issue #1553.
 *
 * Duplicate-title channels (same title + name + group) were only given distinct
 * :dup:N source_ids within a single 50-item chunk Job, so duplicates that spanned
 * a chunk boundary hashed to the same source_id and the later upsert overwrote
 * the earlier row. Hashing now happens per group before chunking.
 */

use App\Jobs\ProcessM3uImport;
use App\Jobs\ProcessM3uImportChunk;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->tempJobsDb = sys_get_temp_dir().'/jobs_test_'.uniqid().'.sqlite';
    touch($this->tempJobsDb);
    config(['database.connections.jobs.database' => $this->tempJobsDb]);
    DB::purge('jobs');

    $migration = require database_path('migrations/2025_02_13_215803_create_jobs_table.php');
    $migration->up();
});

afterEach(function () {
    DB::purge('jobs');
    config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);

    if (isset($this->tempJobsDb) && file_exists($this->tempJobsDb)) {
        @unlink($this->tempJobsDb);
    }
    if (isset($this->tempM3uPath) && file_exists($this->tempM3uPath)) {
        @unlink($this->tempM3uPath);
    }
});

it('keeps duplicate-title channels that span 50-item chunk boundaries', function () {
    $user = User::factory()->create();

    // 120 channels in one group; every 40th shares the title "Dup" so the
    // duplicates land in chunks 1, 2 and 3 respectively.
    $lines = ['#EXTM3U'];
    foreach (range(1, 120) as $i) {
        $title = $i % 40 === 0 ? 'Dup' : "Channel {$i}";
        $lines[] = "#EXTINF:-1 tvg-id=\"ch-{$i}\" tvg-name=\"{$title}\" group-title=\"News\",{$title}";
        $lines[] = "http://example.test/stream/{$i}";
    }

    $this->tempM3uPath = sys_get_temp_dir().'/playlist_dupes_'.uniqid().'.m3u';
    file_put_contents($this->tempM3uPath, implode("\n", $lines));

    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
        'url' => $this->tempM3uPath,
        'xtream' => false,
        'import_prefs' => [],
        'auto_sort' => false,
    ]));

    Bus::fake();
    (new ProcessM3uImport($playlist, force: true, isNew: true))->handle();

    $jobIds = Job::pluck('id')->all();
    expect($jobIds)->toHaveCount(3);

    (new ProcessM3uImportChunk($jobIds, batchCount: 1))->handle();

    expect($playlist->channels()->count())->toBe(120)
        ->and($playlist->channels()->where('title', 'Dup')->pluck('url')->sort()->values()->all())
        ->toBe([
            'http://example.test/stream/120',
            'http://example.test/stream/40',
            'http://example.test/stream/80',
        ]);
});

it('keeps every channel row and source id stable when a multi-chunk playlist is reimported', function () {
    $user = User::factory()->create();

    // 120 channels across two groups, with "Dup" titles in every 50-item chunk
    $writePlaylist = function (array $urlOverrides = [], int $count = 120) {
        $lines = ['#EXTM3U'];
        foreach (range(1, $count) as $i) {
            $title = $i % 40 === 0 ? 'Dup' : "Channel {$i}";
            $group = $i <= 60 ? 'News' : 'Sports';
            $lines[] = "#EXTINF:-1 tvg-id=\"ch-{$i}\" tvg-name=\"{$title}\" group-title=\"{$group}\",{$title}";
            $lines[] = $urlOverrides[$i] ?? "http://example.test/stream/{$i}";
        }
        file_put_contents($this->tempM3uPath, implode("\n", $lines));
    };

    $this->tempM3uPath = sys_get_temp_dir().'/playlist_reimport_chunks_'.uniqid().'.m3u';
    $writePlaylist();

    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
        'url' => $this->tempM3uPath,
        'xtream' => false,
        'import_prefs' => [],
        'auto_sort' => false,
    ]));

    $runImport = function (bool $isNew) use ($playlist) {
        Bus::fake();
        Job::query()->delete();
        (new ProcessM3uImport($playlist->fresh(), force: true, isNew: $isNew))->handle();
        (new ProcessM3uImportChunk(Job::pluck('id')->all(), batchCount: 1))->handle();
    };

    $runImport(isNew: true);

    $firstImport = $playlist->channels()->pluck('source_id', 'id')->all();
    expect($firstImport)->toHaveCount(120)
        ->and(array_unique($firstImport))->toHaveCount(120)
        ->and($playlist->groups()->count())->toBe(2);
    $movedChannelId = $playlist->channels()->where('url', 'http://example.test/stream/80')->value('id');

    // Reimport with one changed stream URL and one new channel appended
    $writePlaylist(urlOverrides: [80 => 'http://example.test/stream/80-moved'], count: 121);
    $runImport(isNew: false);

    $secondImport = $playlist->channels()->pluck('source_id', 'id')->all();
    $added = array_diff_key($secondImport, $firstImport);

    expect($secondImport)->toHaveCount(121)
        ->and(array_intersect_key($secondImport, $firstImport))->toEqual($firstImport)
        ->and($added)->toHaveCount(1)
        ->and($playlist->channels()->whereKey(array_keys($added))->value('title'))->toBe('Channel 121')
        ->and($playlist->channels()->find($movedChannelId)->url)->toBe('http://example.test/stream/80-moved')
        ->and($playlist->groups()->count())->toBe(2);
});

it('does not swap which existing row a duplicate-named entry\'s URL/config lands on when the feed reorders them (issue #1664)', function () {
    $user = User::factory()->create();

    $writePlaylist = function (bool $reversed) {
        $entries = [
            ['id' => 'synthetic-a', 'url' => 'http://provider.example.test/live/a.ts'],
            ['id' => 'synthetic-b', 'url' => 'http://provider.example.test/live/b.ts'],
        ];
        if ($reversed) {
            $entries = array_reverse($entries);
        }

        $lines = ['#EXTM3U'];
        foreach ($entries as $entry) {
            $lines[] = "#EXTINF:-1 tvg-id=\"{$entry['id']}\" tvg-name=\"News\" group-title=\"News\",News";
            $lines[] = $entry['url'];
        }
        file_put_contents($this->tempM3uPath, implode("\n", $lines));
    };

    $this->tempM3uPath = sys_get_temp_dir().'/playlist_reorder_dupes_'.uniqid().'.m3u';
    $writePlaylist(reversed: false);

    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
        'url' => $this->tempM3uPath,
        'xtream' => false,
        'import_prefs' => [],
        'auto_sort' => false,
    ]));

    $runImport = function (bool $isNew) use ($playlist) {
        Bus::fake();
        Job::query()->delete();
        (new ProcessM3uImport($playlist->fresh(), force: true, isNew: $isNew))->handle();
        (new ProcessM3uImportChunk(Job::pluck('id')->all(), batchCount: 1))->handle();
    };

    $runImport(isNew: true);

    $channelA = $playlist->channels()->where('url', 'http://provider.example.test/live/a.ts')->firstOrFail();
    $channelB = $playlist->channels()->where('url', 'http://provider.example.test/live/b.ts')->firstOrFail();
    $channelA->update(['enabled' => true]);
    $channelB->update(['enabled' => false]);
    $aId = $channelA->id;
    $bId = $channelB->id;
    $aSourceId = $channelA->source_id;
    $bSourceId = $channelB->source_id;

    // Reverse only the two entries' order in the feed and reimport.
    $writePlaylist(reversed: true);
    $runImport(isNew: false);

    expect($playlist->channels()->count())->toBe(2)
        ->and(Channel::find($aId)->url)->toBe('http://provider.example.test/live/a.ts')
        ->and(Channel::find($aId)->source_id)->toBe($aSourceId)
        ->and(Channel::find($aId)->enabled)->toBeTrue()
        ->and(Channel::find($bId)->url)->toBe('http://provider.example.test/live/b.ts')
        ->and(Channel::find($bId)->source_id)->toBe($bSourceId)
        ->and(Channel::find($bId)->enabled)->toBeFalse();
});

it('keeps duplicate-named rows created by the previous hashing on the first reimport', function () {
    $user = User::factory()->create();
    $this->tempM3uPath = sys_get_temp_dir().'/playlist_upgrade_dupes_'.uniqid().'.m3u';
    file_put_contents($this->tempM3uPath, implode("\n", [
        '#EXTM3U',
        '#EXTINF:-1 tvg-id="synthetic-b" tvg-name="News" group-title="News",News',
        'http://provider.example.test/live/b.ts',
        '#EXTINF:-1 tvg-id="synthetic-a" tvg-name="News" group-title="News",News',
        'http://provider.example.test/live/a.ts',
    ]));

    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
        'url' => $this->tempM3uPath,
        'xtream' => false,
        'import_prefs' => [],
        'auto_sort' => false,
    ]));
    $group = Group::factory()->for($playlist)->for($user)->create([
        'name' => 'News',
        'name_internal' => 'News',
        'type' => 'live',
        'custom' => false,
    ]);

    // Rows as the previous encounter-order hashing left them (a first, then b)
    $sourceKey = 'NewsNewsNews';
    $existing = collect([
        ['id' => 'synthetic-a', 'url' => 'http://provider.example.test/live/a.ts', 'source_id' => md5($sourceKey), 'enabled' => true],
        ['id' => 'synthetic-b', 'url' => 'http://provider.example.test/live/b.ts', 'source_id' => md5($sourceKey.':dup:1'), 'enabled' => false],
    ])->map(fn (array $row) => Channel::factory()->for($playlist)->for($user)->for($group)->create([
        'title' => 'News',
        'name' => 'News',
        'group' => 'News',
        'group_internal' => 'News',
        'stream_id' => $row['id'],
        'url' => $row['url'],
        'source_id' => $row['source_id'],
        'enabled' => $row['enabled'],
        'is_vod' => false,
        'is_custom' => false,
    ]));

    Bus::fake();
    Job::query()->delete();
    (new ProcessM3uImport($playlist->fresh(), force: true, isNew: false))->handle();
    (new ProcessM3uImportChunk(Job::pluck('id')->all(), batchCount: 1))->handle();

    expect($playlist->channels()->count())->toBe(2)
        ->and(Channel::find($existing[0]->id)->url)->toBe('http://provider.example.test/live/a.ts')
        ->and(Channel::find($existing[0]->id)->enabled)->toBeTrue()
        ->and(Channel::find($existing[1]->id)->url)->toBe('http://provider.example.test/live/b.ts')
        ->and(Channel::find($existing[1]->id)->enabled)->toBeFalse();
});
