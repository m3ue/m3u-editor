<?php

use App\Jobs\ProcessM3uImportChunk;
use App\Jobs\ProcessM3uImportComplete;
use App\Jobs\ProcessM3uImportSeriesChunk;
use App\Jobs\ProcessM3uImportSeriesComplete;
use App\Jobs\ProcessM3uVodImportChunk;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\User;
use App\Sync\Importers\InclusionPolicy;
use App\Sync\Importers\XtreamChainBuilder;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

beforeEach(function () {
    Bus::fake();
});

function makeXtreamPlaylist(array $attrs = []): Playlist
{
    $user = User::factory()->create();

    return Playlist::factory()->for($user)->create($attrs);
}

function makeXtreamBuilder(): XtreamChainBuilder
{
    $policy = new InclusionPolicy(
        useRegex: false,
        selectedGroups: [],
        includedGroupPrefixes: [],
        selectedVodGroups: [],
        includedVodGroupPrefixes: [],
        selectedCategories: [],
        includedCategoryPrefixes: [],
    );

    return new XtreamChainBuilder($policy);
}

function queueChunkJob(string $batchNo, string $type, int $playlistId): void
{
    Job::create([
        'title' => "{$type} chunk",
        'batch_no' => $batchNo,
        'payload' => ['x' => 1],
        'variables' => ['playlistId' => $playlistId, 'type' => $type],
    ]);
}

it('builds a live-only chain with chunk + complete', function () {
    $playlist = makeXtreamPlaylist(['backup_before_sync' => true]);
    $batchNo = (string) Str::uuid();
    queueChunkJob($batchNo, 'live', $playlist->id);

    $jobs = makeXtreamBuilder()->build(
        playlist: $playlist,
        batchNo: $batchNo,
        userId: $playlist->user_id,
        start: Carbon::now(),
        isNew: false,
        maxItemsHit: false,
        liveStreamsEnabled: true,
        vodStreamsEnabled: false,
        seriesCategories: null,
        preprocess: false,
        enabledCategories: collect(),
    );

    expect($jobs)->toHaveCount(2);
    expect($jobs[0])->toBeInstanceOf(ProcessM3uImportChunk::class);
    expect($jobs[1])->toBeInstanceOf(ProcessM3uImportComplete::class);
});

it('builds a live + vod chain when both are enabled', function () {
    $playlist = makeXtreamPlaylist();
    $batchNo = (string) Str::uuid();
    queueChunkJob($batchNo, 'live', $playlist->id);
    queueChunkJob($batchNo, 'vod', $playlist->id);

    $jobs = makeXtreamBuilder()->build(
        playlist: $playlist,
        batchNo: $batchNo,
        userId: $playlist->user_id,
        start: Carbon::now(),
        isNew: true,
        maxItemsHit: false,
        liveStreamsEnabled: true,
        vodStreamsEnabled: true,
        seriesCategories: null,
        preprocess: false,
        enabledCategories: collect(),
    );

    expect($jobs)->toHaveCount(3);
    expect($jobs[0])->toBeInstanceOf(ProcessM3uImportChunk::class);
    expect($jobs[1])->toBeInstanceOf(ProcessM3uVodImportChunk::class);
    expect($jobs[2])->toBeInstanceOf(ProcessM3uImportComplete::class);
});

it('appends a series chunk + series complete per category', function () {
    $playlist = makeXtreamPlaylist(['enable_series' => true]);
    $batchNo = (string) Str::uuid();
    queueChunkJob($batchNo, 'live', $playlist->id);

    $seriesCategories = new Collection([
        ['category_id' => 1, 'category_name' => 'Action'],
        ['category_id' => 2, 'category_name' => 'Drama'],
    ]);

    $jobs = makeXtreamBuilder()->build(
        playlist: $playlist,
        batchNo: $batchNo,
        userId: $playlist->user_id,
        start: Carbon::now(),
        isNew: true,
        maxItemsHit: false,
        liveStreamsEnabled: true,
        vodStreamsEnabled: false,
        seriesCategories: $seriesCategories,
        preprocess: false,
        enabledCategories: collect(),
    );

    expect($jobs)->toHaveCount(5);
    expect($jobs[0])->toBeInstanceOf(ProcessM3uImportChunk::class);
    expect($jobs[1])->toBeInstanceOf(ProcessM3uImportComplete::class);
    expect($jobs[2])->toBeInstanceOf(ProcessM3uImportSeriesChunk::class);
    expect($jobs[3])->toBeInstanceOf(ProcessM3uImportSeriesChunk::class);
    expect($jobs[4])->toBeInstanceOf(ProcessM3uImportSeriesComplete::class);
});

it('skips series categories not included by policy when preprocessing', function () {
    $playlist = makeXtreamPlaylist();
    $batchNo = (string) Str::uuid();

    $policy = new InclusionPolicy(
        useRegex: false,
        selectedGroups: [],
        includedGroupPrefixes: [],
        selectedVodGroups: [],
        includedVodGroupPrefixes: [],
        selectedCategories: ['Action'],
        includedCategoryPrefixes: [],
    );

    $seriesCategories = new Collection([
        ['category_id' => 1, 'category_name' => 'Action'],
        ['category_id' => 2, 'category_name' => 'Drama'],
    ]);

    $jobs = (new XtreamChainBuilder($policy))->build(
        playlist: $playlist,
        batchNo: $batchNo,
        userId: $playlist->user_id,
        start: Carbon::now(),
        isNew: true,
        maxItemsHit: false,
        liveStreamsEnabled: false,
        vodStreamsEnabled: false,
        seriesCategories: $seriesCategories,
        preprocess: true,
        enabledCategories: collect(),
    );

    // Complete + 1 series chunk (Action only) + series complete = 3
    expect($jobs)->toHaveCount(3);
    expect($jobs[0])->toBeInstanceOf(ProcessM3uImportComplete::class);
    expect($jobs[1])->toBeInstanceOf(ProcessM3uImportSeriesChunk::class);
    expect($jobs[2])->toBeInstanceOf(ProcessM3uImportSeriesComplete::class);
});

it('clears the new flag on existing groups and channels', function () {
    $playlist = makeXtreamPlaylist();
    $playlist->groups()->create([
        'name' => 'g',
        'name_internal' => 'g',
        'user_id' => $playlist->user_id,
        'new' => true,
    ]);

    makeXtreamBuilder()->build(
        playlist: $playlist,
        batchNo: (string) Str::uuid(),
        userId: $playlist->user_id,
        start: Carbon::now(),
        isNew: true,
        maxItemsHit: false,
        liveStreamsEnabled: false,
        vodStreamsEnabled: false,
        seriesCategories: null,
        preprocess: false,
        enabledCategories: collect(),
    );

    expect($playlist->groups()->where('new', true)->count())->toBe(0);
});
