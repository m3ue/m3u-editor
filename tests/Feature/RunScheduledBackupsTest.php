<?php

use App\Jobs\CreateBackup;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
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
    Cache::flush();
    Bus::fake([CreateBackup::class]);
});

it('deletes backups older than the configured retention period', function () {
    $oldBackup = putFakeBackup(10);
    $recentBackup = putFakeBackup(1);

    $settings = new GeneralSettings;
    $settings->auto_backup_database = true;
    $settings->auto_backup_database_schedule = '* * * * *';
    $settings->auto_backup_database_max_backups = 0;
    $settings->auto_backup_database_delete_after_days = 5;
    app()->instance(GeneralSettings::class, $settings);

    $this->artisan('app:run-scheduled-backups')->assertExitCode(0);

    Storage::disk('local')->assertMissing($oldBackup);
    Storage::disk('local')->assertExists($recentBackup);
    Bus::assertDispatched(CreateBackup::class);
});

it('keeps all backups when delete after days is disabled', function () {
    $oldBackup = putFakeBackup(100);

    $settings = new GeneralSettings;
    $settings->auto_backup_database = true;
    $settings->auto_backup_database_schedule = '* * * * *';
    $settings->auto_backup_database_max_backups = 0;
    $settings->auto_backup_database_delete_after_days = 0;
    app()->instance(GeneralSettings::class, $settings);

    $this->artisan('app:run-scheduled-backups')->assertExitCode(0);

    Storage::disk('local')->assertExists($oldBackup);
});

it('combines max backups and delete after days pruning without duplicate deletions', function () {
    $oldest = putFakeBackup(20);
    $old = putFakeBackup(10);
    $recent = putFakeBackup(1);

    $settings = new GeneralSettings;
    $settings->auto_backup_database = true;
    $settings->auto_backup_database_schedule = '* * * * *';
    $settings->auto_backup_database_max_backups = 2;
    $settings->auto_backup_database_delete_after_days = 5;
    app()->instance(GeneralSettings::class, $settings);

    $this->artisan('app:run-scheduled-backups')->assertExitCode(0);

    Storage::disk('local')->assertMissing($oldest);
    Storage::disk('local')->assertMissing($old);
    Storage::disk('local')->assertExists($recent);
});
