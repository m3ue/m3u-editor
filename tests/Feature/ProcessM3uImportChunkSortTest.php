<?php

/**
 * Regression tests for auto-sort not applying to existing channels (issue #1264).
 *
 * `sort` was unconditionally excluded from the chunk jobs' upsert update list,
 * so enabling "Auto channel sort" on an already-imported playlist never wrote a
 * sort number to existing channels — only newly inserted ones received it. The
 * chunk jobs now include `sort` in the update list when `Job::variables['autoSort']`
 * is true, which `ProcessM3uImport` sets to the playlist's `auto_sort` value.
 */

use App\Jobs\ProcessM3uImportChunk;
use App\Jobs\ProcessM3uVodImportChunk;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->tempJobsDb = sys_get_temp_dir().'/jobs_test_'.uniqid().'.sqlite';
    touch($this->tempJobsDb);
    config(['database.connections.jobs.database' => $this->tempJobsDb]);
    DB::purge('jobs');

    $migration = require database_path('migrations/2025_02_13_215803_create_jobs_table.php');
    $migration->up();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create([
        'auto_sort' => true,
    ]));
    $this->group = Group::factory()->for($this->playlist)->for($this->user)->create();
});

afterEach(function () {
    DB::purge('jobs');
    config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);

    if (isset($this->tempJobsDb) && file_exists($this->tempJobsDb)) {
        @unlink($this->tempJobsDb);
    }
});

/**
 * Build a full channel payload row as produced by ProcessM3uImport (Xtream path),
 * i.e. containing every column present in the chunk jobs' upsert update list.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chunkSortPayloadRow(Playlist $playlist, array $overrides = []): array
{
    return [
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'enabled' => false,
        'channel' => null,
        'shift' => 0,
        'catchup' => null,
        'catchup_source' => null,
        'title' => 'Channel One',
        'name' => 'Channel One',
        'url' => 'http://provider.test/live/1.ts',
        'logo_internal' => '',
        'group' => '',
        'group_internal' => '',
        'stream_id' => 'ch-1',
        'source_id' => 'src-1',
        'lang' => null,
        'country' => null,
        'import_batch_no' => 'batch-1',
        'extvlcopt' => null,
        'kodidrop' => null,
        'is_vod' => false,
        'container_extension' => null,
        'year' => null,
        'rating' => null,
        'rating_5based' => null,
        'new' => true,
        ...$overrides,
    ];
}

function createChunkSortJob(Playlist $playlist, Group $group, array $rows, bool $autoSort = false): Job
{
    return Job::create([
        'title' => 'test chunk',
        'batch_no' => 'batch-1',
        'payload' => $rows,
        'variables' => [
            'playlistId' => $playlist->id,
            'groupId' => $group->id,
            'groupName' => $group->name,
            'autoSort' => $autoSort,
        ],
    ]);
}

it('updates sort on existing channels when payload rows carry a sort value', function () {
    $existing = Channel::factory()->for($this->playlist)->for($this->user)->for($this->group)->create([
        'source_id' => 'src-1',
        'sort' => null,
    ]);

    $job = createChunkSortJob($this->playlist, $this->group, [
        chunkSortPayloadRow($this->playlist, ['sort' => 7]),
    ], autoSort: true);

    (new ProcessM3uImportChunk([$job->id], batchCount: 1))->handle();

    expect((int) $existing->refresh()->sort)->toBe(7)
        ->and(Channel::count())->toBe(1);
});

it('does not touch sort on existing channels when payload rows carry no sort value', function () {
    $existing = Channel::factory()->for($this->playlist)->for($this->user)->for($this->group)->create([
        'source_id' => 'src-1',
        'sort' => 42,
    ]);

    $job = createChunkSortJob($this->playlist, $this->group, [
        chunkSortPayloadRow($this->playlist),
    ], autoSort: false);

    (new ProcessM3uImportChunk([$job->id], batchCount: 1))->handle();

    expect((int) $existing->refresh()->sort)->toBe(42)
        ->and(Channel::count())->toBe(1);
});

it('updates sort on existing VOD channels when payload rows carry a sort value', function () {
    $existing = Channel::factory()->for($this->playlist)->for($this->user)->for($this->group)->create([
        'source_id' => 'src-1',
        'is_vod' => true,
        'sort' => null,
    ]);

    $job = createChunkSortJob($this->playlist, $this->group, [
        chunkSortPayloadRow($this->playlist, [
            'is_vod' => true,
            'container_extension' => 'mp4',
            'sort' => 3,
        ]),
    ], autoSort: true);

    (new ProcessM3uVodImportChunk([$job->id], batchCount: 1))->handle();

    expect((int) $existing->refresh()->sort)->toBe(3)
        ->and(Channel::count())->toBe(1);
});

it('does not touch sort on existing VOD channels when auto-sort is disabled', function () {
    $existing = Channel::factory()->for($this->playlist)->for($this->user)->for($this->group)->create([
        'source_id' => 'src-1',
        'is_vod' => true,
        'sort' => 99,
    ]);

    $job = createChunkSortJob($this->playlist, $this->group, [
        chunkSortPayloadRow($this->playlist, [
            'is_vod' => true,
            'container_extension' => 'mp4',
        ]),
    ], autoSort: false);

    (new ProcessM3uVodImportChunk([$job->id], batchCount: 1))->handle();

    expect((int) $existing->refresh()->sort)->toBe(99)
        ->and(Channel::count())->toBe(1);
});
