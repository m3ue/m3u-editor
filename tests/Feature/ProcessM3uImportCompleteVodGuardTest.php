<?php

/**
 * Regression tests for the empty-API-response oscillation bug.
 *
 * Root cause: when the Xtream VOD (or live) streams endpoint returns an empty
 * array for a given sync, ProcessM3uImportComplete had no way to distinguish
 * "provider temporarily returned nothing" from "provider genuinely removed all
 * content". It would delete all existing VOD groups/channels whose
 * import_batch_no didn't match the new batch, then the next sync would re-add
 * them — causing the add/remove oscillation visible in sync logs.
 *
 * Fix: before running the cleanup queries, check whether any channels of the
 * relevant type landed in the new batch. If runningVodImport=true but zero VOD
 * channels carry the new batch, leave existing VOD content untouched.
 */

use App\Jobs\ProcessM3uImportComplete;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Services\SyncPipelineService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;

beforeEach(function () {
    // Disable sync log entries so the test doesn't need to satisfy
    // playlist_sync_status_logs NOT NULL constraints on channel title.
    config(['dev.disable_sync_logs' => true]);

    // Let buildPipeline run (it only writes a SyncRun row) but stub startRun
    // so no actual jobs are dispatched during the cleanup phase.
    $this->partialMock(SyncPipelineService::class, function ($mock) {
        $mock->shouldReceive('startRun')->andReturnNull();
        $mock->shouldReceive('expandPipelineAfterImport')->andReturnNull();
        $mock->shouldReceive('completePhase')->andReturnNull();
    });
});

describe('VOD empty-response guard', function () {
    it('preserves existing VOD channels when VOD import ran but returned zero items', function () {
        $user = User::factory()->create();
        $playlist = Playlist::withoutEvents(
            fn () => Playlist::factory()->for($user)->create(['xtream' => true])
        );

        $newBatch = 'new-batch-uuid';
        $oldBatch = 'old-batch-uuid';

        // Pre-existing VOD group and channels from the previous sync.
        $vodGroup = Group::factory()->for($playlist)->for($user)->create([
            'type' => 'vod',
            'custom' => false,
            'import_batch_no' => $oldBatch,
        ]);
        Channel::factory()->count(5)->for($playlist)->for($user)->for($vodGroup)->create([
            'is_vod' => true,
            'is_custom' => false,
            'import_batch_no' => $oldBatch,
        ]);

        // Live channels that DID land in the new batch (live import succeeded).
        $liveGroup = Group::factory()->for($playlist)->for($user)->create([
            'type' => 'live',
            'custom' => false,
            'import_batch_no' => $newBatch,
        ]);
        Channel::factory()->count(3)->for($playlist)->for($user)->for($liveGroup)->create([
            'is_vod' => false,
            'is_custom' => false,
            'import_batch_no' => $newBatch,
        ]);

        (new ProcessM3uImportComplete(
            userId: $user->id,
            playlistId: $playlist->id,
            batchNo: $newBatch,
            start: Carbon::now()->subMinutes(5),
            isNew: false,
            runningLiveImport: true,
            runningVodImport: true,
        ))->handle(app(GeneralSettings::class));

        // VOD channels must NOT have been deleted.
        expect($playlist->channels()->where('is_vod', true)->count())->toBe(5);
        // VOD group must NOT have been soft-deleted.
        expect(Group::where('id', $vodGroup->id)->whereNull('deleted_at')->exists())->toBeTrue();
        // Live channels are unaffected.
        expect($playlist->channels()->where('is_vod', false)->count())->toBe(3);
    });

    it('still removes VOD content when VOD channels genuinely have a different batch', function () {
        $user = User::factory()->create();
        $playlist = Playlist::withoutEvents(
            fn () => Playlist::factory()->for($user)->create(['xtream' => true])
        );

        $newBatch = 'new-batch-uuid';
        $oldBatch = 'old-batch-uuid';

        // A VOD group/channel that has the new batch (the provider kept this one).
        $vodGroupKept = Group::factory()->for($playlist)->for($user)->create([
            'type' => 'vod',
            'custom' => false,
            'import_batch_no' => $newBatch,
        ]);
        Channel::factory()->for($playlist)->for($user)->for($vodGroupKept)->create([
            'is_vod' => true,
            'is_custom' => false,
            'import_batch_no' => $newBatch,
        ]);

        // A stale VOD group/channel — provider removed it (old batch).
        $vodGroupStale = Group::factory()->for($playlist)->for($user)->create([
            'type' => 'vod',
            'custom' => false,
            'import_batch_no' => $oldBatch,
        ]);
        Channel::factory()->for($playlist)->for($user)->for($vodGroupStale)->create([
            'is_vod' => true,
            'is_custom' => false,
            'import_batch_no' => $oldBatch,
        ]);

        (new ProcessM3uImportComplete(
            userId: $user->id,
            playlistId: $playlist->id,
            batchNo: $newBatch,
            start: Carbon::now()->subMinutes(5),
            isNew: false,
            runningLiveImport: false,
            runningVodImport: true,
        ))->handle(app(GeneralSettings::class));

        // Kept channel survives.
        expect($playlist->channels()->where('is_vod', true)->where('import_batch_no', $newBatch)->count())->toBe(1);
        // Stale channel is removed.
        expect($playlist->channels()->where('is_vod', true)->where('import_batch_no', $oldBatch)->count())->toBe(0);
        // Stale group is soft-deleted.
        expect(Group::withTrashed()->where('id', $vodGroupStale->id)->whereNotNull('deleted_at')->exists())->toBeTrue();
    });
});

describe('Live empty-response guard', function () {
    it('preserves existing live channels when live import ran but returned zero items', function () {
        $user = User::factory()->create();
        $playlist = Playlist::withoutEvents(
            fn () => Playlist::factory()->for($user)->create(['xtream' => true])
        );

        $newBatch = 'new-batch-uuid';
        $oldBatch = 'old-batch-uuid';

        // Pre-existing live group and channels from the previous sync.
        $liveGroup = Group::factory()->for($playlist)->for($user)->create([
            'type' => 'live',
            'custom' => false,
            'import_batch_no' => $oldBatch,
        ]);
        Channel::factory()->count(4)->for($playlist)->for($user)->for($liveGroup)->create([
            'is_vod' => false,
            'is_custom' => false,
            'import_batch_no' => $oldBatch,
        ]);

        // VOD channels that DID land in the new batch (VOD import succeeded).
        $vodGroup = Group::factory()->for($playlist)->for($user)->create([
            'type' => 'vod',
            'custom' => false,
            'import_batch_no' => $newBatch,
        ]);
        Channel::factory()->count(2)->for($playlist)->for($user)->for($vodGroup)->create([
            'is_vod' => true,
            'is_custom' => false,
            'import_batch_no' => $newBatch,
        ]);

        (new ProcessM3uImportComplete(
            userId: $user->id,
            playlistId: $playlist->id,
            batchNo: $newBatch,
            start: Carbon::now()->subMinutes(5),
            isNew: false,
            runningLiveImport: true,
            runningVodImport: true,
        ))->handle(app(GeneralSettings::class));

        // Live channels must NOT have been deleted.
        expect($playlist->channels()->where('is_vod', false)->count())->toBe(4);
        expect(Group::where('id', $liveGroup->id)->whereNull('deleted_at')->exists())->toBeTrue();
        // VOD channels are unaffected.
        expect($playlist->channels()->where('is_vod', true)->count())->toBe(2);
    });
});
