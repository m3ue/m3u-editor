<?php

/**
 * Regression coverage for threshold-invalidated imports leaving their
 * correlated SyncRun stuck "running" with no finished_at, even though the
 * playlist and sync log already record the cancellation.
 *
 * Root cause: ProcessM3uImportComplete::cancelImport() terminates the
 * playlist and writes a canceled log entry, but never calls back into
 * SyncPipelineService to give the run itself a terminal status/finished_at.
 */

use App\Enums\SyncRunPhase;
use App\Enums\SyncRunStatus;
use App\Jobs\ProcessM3uImportComplete;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\GeneralSettings;
use Carbon\Carbon;

beforeEach(function () {
    config(['dev.disable_sync_logs' => true]);
    config(['dev.invalidate_import' => true]);
    config(['dev.invalidate_import_threshold' => 1]);
    config(['dev.invalidate_import_group_threshold' => 50]);
    config(['dev.invalidate_import_series_threshold' => 100]);
});

it('terminates the correlated SyncRun when threshold invalidation cancels the import', function () {
    $user = User::factory()->create();
    $playlist = Playlist::withoutEvents(
        fn () => Playlist::factory()->for($user)->create(['xtream' => true])
    );

    $oldBatch = 'old-batch-uuid';
    $newBatch = 'new-batch-uuid';

    // Three existing live channels from the previous sync.
    $group = Group::factory()->for($playlist)->for($user)->create([
        'type' => 'live',
        'custom' => false,
        'import_batch_no' => $oldBatch,
    ]);
    Channel::factory()->count(3)->for($playlist)->for($user)->for($group)->create([
        'is_vod' => false,
        'is_custom' => false,
        'import_batch_no' => $oldBatch,
    ]);

    $run = SyncRun::factory()->for($playlist)->create([
        'status' => SyncRunStatus::Running->value,
        'current_phase' => SyncRunPhase::Import->value,
        'phases' => [SyncRunPhase::Import->value],
        'phase_statuses' => [SyncRunPhase::Import->value => 'running'],
        'finished_at' => null,
    ]);

    // New sync only brings in a single channel (new batch), so removedChannelCount=2
    // exceeds the configured threshold of 1 and the import should be invalidated.
    $newGroup = Group::factory()->for($playlist)->for($user)->create([
        'type' => 'live',
        'custom' => false,
        'import_batch_no' => $newBatch,
        'new' => true,
    ]);
    Channel::factory()->for($playlist)->for($user)->for($newGroup)->create([
        'is_vod' => false,
        'is_custom' => false,
        'import_batch_no' => $newBatch,
        'new' => true,
    ]);

    (new ProcessM3uImportComplete(
        userId: $user->id,
        playlistId: $playlist->id,
        batchNo: $newBatch,
        start: Carbon::now()->subSeconds(5),
        isNew: false,
        runningLiveImport: true,
        runningVodImport: false,
        syncRunId: $run->id,
    ))->handle(app(GeneralSettings::class));

    $run->refresh();

    expect($run->status)->toBe(SyncRunStatus::Failed->value)
        ->and($run->finished_at)->not->toBeNull();
});
