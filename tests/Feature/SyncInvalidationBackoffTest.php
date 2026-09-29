<?php

/**
 * Progressive backoff for invalidated playlist syncs.
 *
 * An invalidated sync used to be retried on the flat failed-retry cooldown forever,
 * ignoring the playlist's own schedule. It now walks a retry ladder (see
 * SyncRetryBackoff), falls back to the regular schedule once the ladder is exhausted,
 * and starts the ladder over if that scheduled sync is invalidated again.
 */

use App\Enums\Status;
use App\Jobs\ProcessM3uImport;
use App\Jobs\ProcessM3uImportComplete;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Services\SyncPipelineService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'dev.disable_sync_logs' => true,
        'dev.invalidate_import' => true,
        'dev.invalidate_import_threshold' => 2,
        'dev.invalidate_import_retry_backoff' => 'balanced',
        'dev.failed_retry_cooldown_minutes' => 15,
    ]);

    $this->partialMock(SyncPipelineService::class, function ($mock) {
        $mock->shouldReceive('startRun')->andReturnNull();
        $mock->shouldReceive('expandPipelineAfterImport')->andReturnNull();
        $mock->shouldReceive('completePhase')->andReturnNull();
    });

    NotificationFacade::fake();
    Carbon::setTestNow(Carbon::parse('2026-09-29 10:00:00'));

    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create([
        'status' => Status::Processing,
        'sync_interval' => '0 0 * * *',
        'auto_sync' => true,
        'is_network_playlist' => false,
        'sync_retry_count' => 0,
        'sync_retry_after' => null,
    ]));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Seed 5 channels from a previous batch and 1 from the new batch, so the new batch
 * would drop the channel count well past the threshold of 2.
 */
function seedInvalidatingBatch(Playlist $playlist, User $user, string $newBatch): void
{
    $group = Group::factory()->for($playlist)->for($user)->create([
        'type' => 'live',
        'custom' => false,
        'import_batch_no' => $newBatch,
    ]);
    Channel::factory()->count(5)->for($playlist)->for($user)->for($group)->create([
        'is_vod' => false,
        'is_custom' => false,
        'new' => false,
        'import_batch_no' => 'old-batch',
    ]);
    Channel::factory()->for($playlist)->for($user)->for($group)->create([
        'is_vod' => false,
        'is_custom' => false,
        'new' => false,
        'import_batch_no' => $newBatch,
    ]);
}

function runImportComplete(Playlist $playlist, User $user, string $batchNo): void
{
    (new ProcessM3uImportComplete(
        userId: $user->id,
        playlistId: $playlist->id,
        batchNo: $batchNo,
        start: Carbon::now()->subMinutes(1),
        isNew: false,
        runningLiveImport: true,
        runningVodImport: false,
    ))->handle(app(GeneralSettings::class));
}

describe('ProcessM3uImportComplete retry ladder', function () {
    it('walks the ladder, falls back to the schedule, then starts over', function () {
        $expected = [
            [1, '2026-09-29 10:05:00'],
            [2, '2026-09-29 10:30:00'],
            [3, '2026-09-29 12:00:00'],
            [4, '2026-09-30 00:00:00'], // exhausted: next scheduled sync
            [1, '2026-09-29 10:05:00'], // scheduled sync invalidated again: ladder restarts
        ];

        foreach ($expected as $attempt => [$count, $after]) {
            $batch = "batch-{$attempt}";
            seedInvalidatingBatch($this->playlist, $this->user, $batch);
            runImportComplete($this->playlist, $this->user, $batch);

            $playlist = $this->playlist->fresh();
            expect($playlist->status)->toBe(Status::Failed)
                ->and($playlist->sync_retry_count)->toBe($count)
                ->and($playlist->sync_retry_after->toDateTimeString())->toBe($after);
        }

        expect($playlist->errors)->toContain('Retry 1 of 3');
    });

    it('only notifies when a cycle starts or the ladder is exhausted', function () {
        foreach (range(0, 3) as $attempt) {
            seedInvalidatingBatch($this->playlist, $this->user, "batch-{$attempt}");
            runImportComplete($this->playlist, $this->user, "batch-{$attempt}");
        }

        NotificationFacade::assertSentToTimes($this->user, DatabaseNotification::class, 2);
        expect($this->playlist->fresh()->errors)->toContain('All 3 automatic retries were invalidated');
    });

    it('never waits longer than the playlist schedule', function () {
        $this->playlist->update(['sync_interval' => '0 * * * *']);
        $this->playlist->update(['sync_retry_count' => 2]); // next step would be 2h

        seedInvalidatingBatch($this->playlist, $this->user, 'batch-hourly');
        runImportComplete($this->playlist, $this->user, 'batch-hourly');

        expect($this->playlist->fresh()->sync_retry_after->toDateTimeString())->toBe('2026-09-29 11:00:00');
    });

    it('waits for the next scheduled sync when auto-retry is disabled', function () {
        config(['dev.invalidate_import_retry_backoff' => 'none']);

        seedInvalidatingBatch($this->playlist, $this->user, 'batch-none');
        runImportComplete($this->playlist, $this->user, 'batch-none');

        $playlist = $this->playlist->fresh();
        expect($playlist->sync_retry_after->toDateTimeString())->toBe('2026-09-30 00:00:00')
            ->and($playlist->errors)->toContain('Waiting for the next scheduled sync');
        NotificationFacade::assertSentToTimes($this->user, DatabaseNotification::class, 1);
    });

    it('resets the ladder after a successful sync', function () {
        config(['dev.invalidate_import' => false]);
        $this->playlist->update([
            'sync_retry_count' => 2,
            'sync_retry_after' => now()->subMinute(),
        ]);

        seedInvalidatingBatch($this->playlist, $this->user, 'batch-ok');
        runImportComplete($this->playlist, $this->user, 'batch-ok');

        $playlist = $this->playlist->fresh();
        expect($playlist->sync_retry_count)->toBe(0)
            ->and($playlist->sync_retry_after)->toBeNull();
    });
});

describe('RefreshPlaylist scheduler gate', function () {
    beforeEach(function () {
        Queue::fake();
    });

    it('holds an invalidated playlist until its retry time', function () {
        $this->playlist->update([
            'status' => Status::Failed,
            'sync_retry_count' => 1,
            'sync_retry_after' => now()->addMinutes(5),
        ]);
        DB::table('playlists')->where('id', $this->playlist->id)->update(['updated_at' => now()->subHour()]);

        $this->artisan('app:refresh-playlist')->assertSuccessful();

        Queue::assertNotPushed(ProcessM3uImport::class);
    });

    it('retries an invalidated playlist once its retry time passes and clears the gate', function () {
        $this->playlist->update([
            'status' => Status::Failed,
            'sync_retry_count' => 1,
            'sync_retry_after' => now()->subMinute(),
        ]);

        $this->artisan('app:refresh-playlist')->assertSuccessful();

        Queue::assertPushed(ProcessM3uImport::class, fn (ProcessM3uImport $job) => $job->force === true);

        $playlist = $this->playlist->fresh();
        expect($playlist->sync_retry_after)->toBeNull()
            ->and($playlist->sync_retry_count)->toBe(1);
    });

    it('keeps the flat cooldown for failures that were not invalidations', function () {
        $this->playlist->update(['status' => Status::Failed]);
        DB::table('playlists')->where('id', $this->playlist->id)->update(['updated_at' => now()->subMinutes(5)]);

        $this->artisan('app:refresh-playlist')->assertSuccessful();
        Queue::assertNotPushed(ProcessM3uImport::class);

        DB::table('playlists')->where('id', $this->playlist->id)->update(['updated_at' => now()->subMinutes(20)]);

        $this->artisan('app:refresh-playlist')->assertSuccessful();
        Queue::assertPushed(ProcessM3uImport::class);
    });
});
