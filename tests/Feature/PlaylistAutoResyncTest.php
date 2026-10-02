<?php

/**
 * Auto resync for failed playlist syncs.
 *
 * A failed sync is retried every "Failed sync retry cooldown" minutes (Settings > Sync Options,
 * overridable with FAILED_RETRY_COOLDOWN_MINUTES) up to the playlist's max retry attempts,
 * then waits for the next scheduled sync, which starts a fresh set of attempts. Invalidated
 * syncs skip the retry loop entirely and wait for the next scheduled sync.
 */

use App\Enums\Status;
use App\Filament\Clusters\Settings\Pages\ManageSyncSettings;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Filament\Resources\Playlists\Pages\ListPlaylists;
use App\Jobs\ProcessEpgImport;
use App\Jobs\ProcessM3uImport;
use App\Jobs\ProcessM3uImportComplete;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use App\Services\AlertService;
use App\Services\SyncPipelineService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    config([
        'dev.disable_sync_logs' => true,
        'dev.failed_retry_cooldown_minutes' => null,
    ]);

    Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00'));

    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create([
        'status' => Status::Failed,
        'sync_interval' => '0 0 * * *',
        'synced' => now()->subMinutes(20),
        'auto_sync' => true,
        'is_network_playlist' => false,
        'auto_resync_on_failure' => true,
        'auto_resync_retries' => 3,
        'resync_attempt' => 0,
    ]));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Backdate updated_at, which the failed-retry cooldown is measured from.
 */
function backdateAutoResyncRecord(Model $record, int $minutes): void
{
    DB::table($record->getTable())->where('id', $record->id)->update(['updated_at' => now()->subMinutes($minutes)]);
}

/**
 * Seed 5 channels from a previous batch and 1 from the new batch, so the new batch
 * would drop the channel count well past an invalidation threshold of 2.
 */
function seedAutoResyncInvalidatingBatch(Playlist $playlist, User $user, string $newBatch): void
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

function runAutoResyncImportComplete(Playlist $playlist, User $user, string $batchNo): void
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

describe('Failed sync retry cooldown', function () {
    it('defaults to 15 minutes', function () {
        expect(app(GeneralSettings::class)->failedRetryCooldownMinutes())->toBe(15);
    });

    it('uses the Sync Options setting', function () {
        $settings = app(GeneralSettings::class);
        $settings->failed_retry_cooldown_minutes = 30;
        $settings->save();

        expect(app(GeneralSettings::class)->failedRetryCooldownMinutes())->toBe(30);
    });

    it('lets the environment variable override the setting', function () {
        $settings = app(GeneralSettings::class);
        $settings->failed_retry_cooldown_minutes = 30;
        $settings->save();
        config(['dev.failed_retry_cooldown_minutes' => '5']);

        expect(app(GeneralSettings::class)->failedRetryCooldownMinutes())->toBe(5);
    });

    it('saves from the Sync Options page', function () {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageSyncSettings::class)
            ->fillForm(['failed_retry_cooldown_minutes' => 45])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(app(GeneralSettings::class)->failed_retry_cooldown_minutes)->toBe(45);
    });

    it('also gates failed EPG retries', function () {
        Queue::fake();
        $settings = app(GeneralSettings::class);
        $settings->failed_retry_cooldown_minutes = 30;
        $settings->save();

        $epg = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create([
            'status' => Status::Failed,
            'auto_sync' => true,
            'sync_interval' => '0 0 * * *',
        ]));
        backdateAutoResyncRecord($epg, 20);

        $this->artisan('app:refresh-epg')->assertSuccessful();
        Queue::assertNotPushed(ProcessEpgImport::class);

        backdateAutoResyncRecord($epg, 40);

        $this->artisan('app:refresh-epg')->assertSuccessful();
        Queue::assertPushed(ProcessEpgImport::class);
    });
});

describe('RefreshPlaylist auto resync', function () {
    beforeEach(function () {
        Queue::fake();
    });

    it('holds a failed playlist until the cooldown passes', function () {
        backdateAutoResyncRecord($this->playlist, 5);

        $this->artisan('app:refresh-playlist')->assertSuccessful();

        Queue::assertNotPushed(ProcessM3uImport::class);
        expect($this->playlist->fresh()->resync_attempt)->toBe(0);
    });

    it('retries a failed playlist once the cooldown passes and counts the attempt', function () {
        backdateAutoResyncRecord($this->playlist, 20);

        $this->artisan('app:refresh-playlist')->assertSuccessful();

        Queue::assertPushed(ProcessM3uImport::class, fn (ProcessM3uImport $job) => $job->force === true);
        expect($this->playlist->fresh()->resync_attempt)->toBe(1);
    });

    it('waits for the next scheduled sync once retries are used up, then starts over', function () {
        $this->playlist->update(['resync_attempt' => 3]);
        backdateAutoResyncRecord($this->playlist, 20);

        $this->artisan('app:refresh-playlist')->assertSuccessful();
        Queue::assertNotPushed(ProcessM3uImport::class);

        Carbon::setTestNow(Carbon::parse('2026-10-01 00:00:00'));

        $this->artisan('app:refresh-playlist')->assertSuccessful();

        Queue::assertPushed(ProcessM3uImport::class, fn (ProcessM3uImport $job) => $job->force === true);
        expect($this->playlist->fresh()->resync_attempt)->toBe(0);
    });

    it('waits for the next scheduled sync when auto resync is disabled', function () {
        $this->playlist->update(['auto_resync_on_failure' => false]);
        backdateAutoResyncRecord($this->playlist, 20);

        $this->artisan('app:refresh-playlist')->assertSuccessful();

        Queue::assertNotPushed(ProcessM3uImport::class);
    });

    it('follows the regular schedule for playlists that did not fail', function () {
        $this->playlist->update(['status' => Status::Completed]);

        $this->artisan('app:refresh-playlist')->assertSuccessful();
        Queue::assertNotPushed(ProcessM3uImport::class);

        Carbon::setTestNow(Carbon::parse('2026-10-01 00:00:00'));

        $this->artisan('app:refresh-playlist')->assertSuccessful();
        Queue::assertPushed(ProcessM3uImport::class, fn (ProcessM3uImport $job) => $job->force === false);
    });
});

describe('ProcessM3uImportComplete', function () {
    beforeEach(function () {
        $this->partialMock(SyncPipelineService::class, function ($mock) {
            $mock->shouldReceive('startRun')->andReturnNull();
            $mock->shouldReceive('expandPipelineAfterImport')->andReturnNull();
            $mock->shouldReceive('completePhase')->andReturnNull();
        });

        NotificationFacade::fake();
        $this->playlist->update(['status' => Status::Processing]);
    });

    it('skips the retry loop when a sync is invalidated', function () {
        config([
            'dev.invalidate_import' => true,
            'dev.invalidate_import_threshold' => 2,
        ]);

        seedAutoResyncInvalidatingBatch($this->playlist, $this->user, 'batch-invalid');
        runAutoResyncImportComplete($this->playlist, $this->user, 'batch-invalid');

        $playlist = $this->playlist->fresh();
        expect($playlist->status)->toBe(Status::Failed)
            ->and($playlist->resync_attempt)->toBe(3);
        NotificationFacade::assertSentToTimes($this->user, DatabaseNotification::class, 1);

        Queue::fake();
        backdateAutoResyncRecord($playlist, 20);

        $this->artisan('app:refresh-playlist')->assertSuccessful();
        Queue::assertNotPushed(ProcessM3uImport::class);
    });

    it('sends an alert when a sync is invalidated and invalidation alerts are enabled', function () {
        config([
            'dev.invalidate_import' => true,
            'dev.invalidate_import_threshold' => 2,
        ]);
        $settings = app(GeneralSettings::class);
        $settings->alerts_on_sync_invalidated = true;
        $settings->save();

        $alertMessage = null;
        $this->mock(AlertService::class, function ($mock) use (&$alertMessage) {
            $mock->shouldReceive('isEnabled')->andReturnTrue();
            $mock->shouldReceive('send')->once()->andReturnUsing(function (string $message) use (&$alertMessage) {
                $alertMessage = $message;
            });
        });

        seedAutoResyncInvalidatingBatch($this->playlist, $this->user, 'batch-invalid');
        runAutoResyncImportComplete($this->playlist, $this->user, 'batch-invalid');

        expect($alertMessage)
            ->toStartWith("[SYNC INVALIDATED] Playlist \"{$this->playlist->name}\"")
            ->toContain('The channel count would have been 1')
            ->not->toContain('Playlist Sync Invalidated:')
            ->and($this->playlist->fresh()->errors)->toStartWith('Playlist Sync Invalidated: The channel count');
    });

    it('does not send an alert when invalidation alerts are disabled', function () {
        config([
            'dev.invalidate_import' => true,
            'dev.invalidate_import_threshold' => 2,
        ]);

        $this->mock(AlertService::class, function ($mock) {
            $mock->shouldReceive('isEnabled')->andReturnTrue();
            $mock->shouldNotReceive('send');
        });

        seedAutoResyncInvalidatingBatch($this->playlist, $this->user, 'batch-invalid');
        runAutoResyncImportComplete($this->playlist, $this->user, 'batch-invalid');

        expect($this->playlist->fresh()->status)->toBe(Status::Failed);
    });

    it('resets the attempt counter after a successful sync', function () {
        config(['dev.invalidate_import' => false]);
        $this->playlist->update(['resync_attempt' => 2]);

        seedAutoResyncInvalidatingBatch($this->playlist, $this->user, 'batch-ok');
        runAutoResyncImportComplete($this->playlist, $this->user, 'batch-ok');

        $playlist = $this->playlist->fresh();
        expect($playlist->status)->toBe(Status::Completed)
            ->and($playlist->resync_attempt)->toBe(0);
    });
});

describe('Manual sync', function () {
    beforeEach(function () {
        Queue::fake();
        $this->playlist->update(['resync_attempt' => 3]);
        $this->actingAs($this->user);
    });

    it('resets the attempt counter from the playlist page action', function () {
        Livewire::test(EditPlaylist::class, ['record' => $this->playlist->id])
            ->callAction('process');

        expect($this->playlist->fresh()->resync_attempt)->toBe(0);
        Queue::assertPushed(ProcessM3uImport::class);
    });

    it('resets the attempt counter from the bulk action', function () {
        Livewire::test(ListPlaylists::class)
            ->selectTableRecords([$this->playlist])
            ->callAction(TestAction::make('process')->table()->bulk());

        expect($this->playlist->fresh()->resync_attempt)->toBe(0);
        Queue::assertPushed(ProcessM3uImport::class);
    });
});

describe('Playlist::nextSyncForInterval', function () {
    it('treats the legacy 24hr interval as midnight', function () {
        expect(Playlist::nextSyncForInterval('24hr')->toDateTimeString())->toBe('2026-10-01 00:00:00');
    });

    it('returns null for a missing or invalid interval', function () {
        expect(Playlist::nextSyncForInterval(null))->toBeNull()
            ->and(Playlist::nextSyncForInterval('not a cron'))->toBeNull();
    });
});
