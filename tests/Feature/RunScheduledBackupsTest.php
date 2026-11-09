<?php

use App\Jobs\CreateBackup;
use App\Jobs\PruneOldBackups;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function putFakeBackup(string $daysAgo): string
{
    $filename = now()->subDays($daysAgo)->format('Y-m-d-H-i-s').'.zip';
    $path = "m3u-editor-backups/{$filename}";
    Storage::disk('local')->put($path, 'backup-payload');

    return $path;
}

beforeEach(function () {
    config(['backup.backup.destination.disks' => ['local']]);
    Storage::fake('local');
});

it('chains a replacement backup before pruning so the existing archive is never deleted first', function () {
    Bus::fake();

    $oldBackup = putFakeBackup(1);

    $settings = new GeneralSettings;
    $settings->auto_backup_database = true;
    $settings->auto_backup_database_schedule = '* * * * *';
    $settings->auto_backup_database_max_backups = 1;
    $settings->auto_backup_database_delete_after_days = 0;
    app()->instance(GeneralSettings::class, $settings);

    $this->artisan('app:run-scheduled-backups')->assertExitCode(0);

    // The replacement is queued, and nothing has pruned the existing archive yet.
    Storage::disk('local')->assertExists($oldBackup);

    Bus::assertChained([
        CreateBackup::class,
        PruneOldBackups::class,
    ]);
});

it('deletes backups older than the configured retention period', function () {
    $oldBackup = putFakeBackup(10);
    $recentBackup = putFakeBackup(1);

    (new PruneOldBackups(max: 0, deleteAfterDays: 5))->handle();

    Storage::disk('local')->assertMissing($oldBackup);
    Storage::disk('local')->assertExists($recentBackup);
});

it('keeps all backups when delete after days is disabled', function () {
    $oldBackup = putFakeBackup(100);

    (new PruneOldBackups(max: 0, deleteAfterDays: 0))->handle();

    Storage::disk('local')->assertExists($oldBackup);
});

it('combines max backups and delete after days pruning without duplicate deletions', function () {
    $oldest = putFakeBackup(20);
    $old = putFakeBackup(10);
    $recent = putFakeBackup(1);

    (new PruneOldBackups(max: 2, deleteAfterDays: 5))->handle();

    Storage::disk('local')->assertMissing($oldest);
    Storage::disk('local')->assertMissing($old);
    Storage::disk('local')->assertExists($recent);
});

it('preserves the sole existing archive with max backups of 1 until a replacement exists', function () {
    $existing = putFakeBackup(1);

    // Simulate the replacement having already been created by the time pruning runs.
    $replacement = putFakeBackup(0);

    (new PruneOldBackups(max: 1, deleteAfterDays: 0))->handle();

    Storage::disk('local')->assertMissing($existing);
    Storage::disk('local')->assertExists($replacement);
});

it('prunes nothing when the scheduled backup it follows was not created', function () {
    $expired = putFakeBackup(10);
    $older = putFakeBackup(20);

    (new PruneOldBackups(max: 1, deleteAfterDays: 5, requireBackupSince: now()))->handle();

    Storage::disk('local')->assertExists($expired);
    Storage::disk('local')->assertExists($older);
});

it('prunes once the scheduled backup it follows exists', function () {
    $since = now()->subSecond();
    $expired = putFakeBackup(10);
    $replacement = putFakeBackup(0);

    (new PruneOldBackups(max: 0, deleteAfterDays: 5, requireBackupSince: $since))->handle();

    Storage::disk('local')->assertMissing($expired);
    Storage::disk('local')->assertExists($replacement);
});
