<?php

use App\Jobs\CreateBackup;
use App\Jobs\ProcessM3uImportChunk;
use App\Jobs\ProcessM3uImportComplete;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\User;
use App\Sync\Importers\M3uChainBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

beforeEach(function () {
    Bus::fake();
});

function makeM3uPlaylist(array $attrs = []): Playlist
{
    $user = User::factory()->create();

    return Playlist::factory()->for($user)->create($attrs);
}

it('builds a chain with backup + chunks + complete on subsequent syncs', function () {
    $playlist = makeM3uPlaylist(['backup_before_sync' => true]);
    $batchNo = (string) Str::uuid();

    Job::create([
        'title' => 'chunk',
        'batch_no' => $batchNo,
        'payload' => ['x' => 1],
        'variables' => ['playlistId' => $playlist->id],
    ]);

    $jobs = (new M3uChainBuilder)->build(
        playlist: $playlist,
        batchNo: $batchNo,
        userId: $playlist->user_id,
        start: Carbon::now(),
        isNew: false,
        maxItemsHit: false,
    );

    expect($jobs)->toHaveCount(3);
    expect($jobs[0])->toBeInstanceOf(CreateBackup::class);
    expect($jobs[1])->toBeInstanceOf(ProcessM3uImportChunk::class);
    expect($jobs[2])->toBeInstanceOf(ProcessM3uImportComplete::class);
});

it('omits the backup job for new playlists', function () {
    $playlist = makeM3uPlaylist(['backup_before_sync' => true]);

    $jobs = (new M3uChainBuilder)->build(
        playlist: $playlist,
        batchNo: (string) Str::uuid(),
        userId: $playlist->user_id,
        start: Carbon::now(),
        isNew: true,
        maxItemsHit: false,
    );

    expect($jobs)->toHaveCount(1);
    expect($jobs[0])->toBeInstanceOf(ProcessM3uImportComplete::class);
});

it('omits the backup job when backup_before_sync is disabled', function () {
    $playlist = makeM3uPlaylist(['backup_before_sync' => false]);

    $jobs = (new M3uChainBuilder)->build(
        playlist: $playlist,
        batchNo: (string) Str::uuid(),
        userId: $playlist->user_id,
        start: Carbon::now(),
        isNew: false,
        maxItemsHit: false,
    );

    expect($jobs)->toHaveCount(1);
    expect($jobs[0])->toBeInstanceOf(ProcessM3uImportComplete::class);
});
