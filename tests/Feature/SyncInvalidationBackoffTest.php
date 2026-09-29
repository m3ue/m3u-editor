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
use Illuminate\Support\Facades\Bus;
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
function seedBackoffInvalidatingBatch(Playlist $playlist, User $user, string $newBatch): void
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

function runBackoffImportComplete(Playlist $playlist, User $user, string $batchNo): void
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
            seedBackoffInvalidatingBatch($this->playlist, $this->user, $batch);
            runBackoffImportComplete($this->playlist, $this->user, $batch);

            $playlist = $this->playlist->fresh();
            expect($playlist->status)->toBe(Status::Failed)
                ->and($playlist->sync_retry_count)->toBe($count)
                ->and($playlist->sync_retry_after->toDateTimeString())->toBe($after);
        }

        expect($playlist->errors)->toContain('Retry 1 of 3');
    });

    it('only notifies when a cycle starts or the ladder is exhausted', function () {
        foreach (range(0, 3) as $attempt) {
            seedBackoffInvalidatingBatch($this->playlist, $this->user, "batch-{$attempt}");
            runBackoffImportComplete($this->playlist, $this->user, "batch-{$attempt}");
        }

        NotificationFacade::assertSentToTimes($this->user, DatabaseNotification::class, 2);
        expect($this->playlist->fresh()->errors)->toContain('All 3 automatic retries were invalidated');
    });

    it('never waits longer than the playlist schedule', function () {
        $this->playlist->update(['sync_interval' => '0 * * * *']);
        $this->playlist->update(['sync_retry_count' => 2]); // next step would be 2h

        seedBackoffInvalidatingBatch($this->playlist, $this->user, 'batch-hourly');
        runBackoffImportComplete($this->playlist, $this->user, 'batch-hourly');

        expect($this->playlist->fresh()->sync_retry_after->toDateTimeString())->toBe('2026-09-29 11:00:00');
    });

    it('waits for the next scheduled sync when auto-retry is disabled', function () {
        config(['dev.invalidate_import_retry_backoff' => 'none']);

        seedBackoffInvalidatingBatch($this->playlist, $this->user, 'batch-none');
        runBackoffImportComplete($this->playlist, $this->user, 'batch-none');

        $playlist = $this->playlist->fresh();
        expect($playlist->sync_retry_after->toDateTimeString())->toBe('2026-09-30 00:00:00')
            ->and($playlist->errors)->toContain('Waiting for the next scheduled sync');
        NotificationFacade::assertSentToTimes($this->user, DatabaseNotification::class, 1);
    });

    it('schedules nothing when auto sync is disabled', function () {
        $this->playlist->update(['auto_sync' => false, 'sync_retry_count' => 2]);

        seedBackoffInvalidatingBatch($this->playlist, $this->user, 'batch-manual');
        runBackoffImportComplete($this->playlist, $this->user, 'batch-manual');

        $playlist = $this->playlist->fresh();
        expect($playlist->sync_retry_count)->toBe(0)
            ->and($playlist->sync_retry_after)->toBeNull()
            ->and($playlist->errors)->toContain('Automatic sync is disabled, sync manually to retry.');
        NotificationFacade::assertSentToTimes($this->user, DatabaseNotification::class, 1);
    });

    it('resets the ladder after a successful sync', function () {
        config(['dev.invalidate_import' => false]);
        $this->playlist->update([
            'sync_retry_count' => 2,
            'sync_retry_after' => now()->subMinute(),
        ]);

        seedBackoffInvalidatingBatch($this->playlist, $this->user, 'batch-ok');
        runBackoffImportComplete($this->playlist, $this->user, 'batch-ok');

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

describe('ProcessM3uImport start', function () {
    beforeEach(function () {
        $this->tempJobsDb = sys_get_temp_dir().'/jobs_test_'.uniqid().'.sqlite';
        touch($this->tempJobsDb);
        config(['database.connections.jobs.database' => $this->tempJobsDb]);
        DB::purge('jobs');
        (require database_path('migrations/2025_02_13_215803_create_jobs_table.php'))->up();

        $this->tempM3uPath = sys_get_temp_dir().'/playlist_import_'.uniqid('', true).'.m3u';
        file_put_contents($this->tempM3uPath, implode("\n", [
            '#EXTM3U',
            '#EXTINF:-1 tvg-id="demo-1" tvg-name="Demo One" group-title="News",Demo One',
            'http://example.test/stream/1',
        ]));
    });

    afterEach(function () {
        DB::purge('jobs');
        config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);
        @unlink($this->tempJobsDb);
        @unlink($this->tempM3uPath);
    });

    it('clears a pending invalidation retry but keeps the attempt count', function () {
        $this->playlist->update([
            'status' => Status::Failed,
            'url' => $this->tempM3uPath,
            'xtream' => false,
            'import_prefs' => [],
            'sync_retry_count' => 2,
            'sync_retry_after' => now()->addMinutes(30),
        ]);

        Bus::fake();
        (new ProcessM3uImport($this->playlist->fresh(), force: true, isNew: false))->handle();

        $playlist = $this->playlist->fresh();
        expect($playlist->sync_retry_after)->toBeNull()
            ->and($playlist->sync_retry_count)->toBe(2);
    });
});

describe('Playlist::nextSyncForInterval', function () {
    it('treats the legacy 24hr interval as midnight', function () {
        expect(Playlist::nextSyncForInterval('24hr')->toDateTimeString())->toBe('2026-09-30 00:00:00');
    });

    it('returns null for a missing or invalid interval', function () {
        expect(Playlist::nextSyncForInterval(null))->toBeNull()
            ->and(Playlist::nextSyncForInterval('not a cron'))->toBeNull();
    });
});
