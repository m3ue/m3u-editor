<?php

/**
 * Regression tests for ProcessM3uImportSeriesChunk status-overwrite bug.
 *
 * When the first chunk (index=0) starts processing it used to write
 * status=Processing unconditionally, which overwrote the Completed status set
 * by ProcessM3uImportComplete. This caused SyncListener to see Processing at
 * the moment SyncCompleted fires and fall back to buildPostProcessOnly(),
 * producing an incomplete 1-phase post-sync plan instead of the full 10-phase
 * plan.
 */

use App\Enums\Status;
use App\Jobs\ProcessM3uImportSeriesChunk;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly([
        'status' => Status::Completed,
        'xtream_config' => null, // no xtream config → job returns early after the init block
    ]);
});

it('does not overwrite playlist status to Processing when index is 0', function () {
    $job = new ProcessM3uImportSeriesChunk(
        payload: [
            'playlistId' => $this->playlist->id,
            'categoryId' => '99',
            'categoryName' => 'Test Category',
        ],
        batchCount: 5,
        batchNo: 'test-batch',
        index: 0,
    );

    $job->handle();

    expect($this->playlist->fresh()->status)
        ->toBe(Status::Completed, 'ProcessM3uImportSeriesChunk must not overwrite status=Completed with Processing');
});

it('leaves playlist status unchanged for non-zero chunk indexes', function () {
    $job = new ProcessM3uImportSeriesChunk(
        payload: [
            'playlistId' => $this->playlist->id,
            'categoryId' => '99',
            'categoryName' => 'Test Category',
        ],
        batchCount: 5,
        batchNo: 'test-batch',
        index: 1, // not the first chunk — init block is skipped entirely
    );

    $job->handle();

    expect($this->playlist->fresh()->status)->toBe(Status::Completed);
});
