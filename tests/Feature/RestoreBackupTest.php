<?php

use App\Jobs\RestoreBackup;
use App\Listeners\DropPostgresFunctionsAfterBackupReset;
use App\Models\User;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Wnx\LaravelBackupRestore\Events\DatabaseReset;
use Wnx\LaravelBackupRestore\PendingRestore;

beforeEach(function () {
    // The job notifies the first admin, which may be one the migrations created.
    User::factory()->admin()->create();
    $this->admin = User::where('is_admin', true)->first();
    NotificationFacade::fake();
});

it('reports a failed backup:restore instead of announcing it as restored', function () {
    // backup:restore (laravel-backup-restore v2) signals failure through its
    // exit code rather than by throwing.
    $calledCommands = [];
    Artisan::shouldReceive('call')->andReturnUsing(function (string $command) use (&$calledCommands): int {
        $calledCommands[] = $command;

        return $command === 'backup:restore' ? 1 : 0;
    });
    Artisan::shouldReceive('output')->andReturn("Import failed: psql exited with code 3.\n");

    dispatch_sync(new RestoreBackup('m3u-editor-backups/2026-11-18-08-00-00.zip'));

    expect($calledCommands)->toContain('backup:restore')
        ->not->toContain('migrate')
        ->toContain('up');

    NotificationFacade::assertNotSentTo($this->admin, DatabaseNotification::class, fn (DatabaseNotification $notification): bool => $notification->data['title'] === 'Backup restored successfully');
    NotificationFacade::assertSentTo($this->admin, DatabaseNotification::class, fn (DatabaseNotification $notification): bool => $notification->data['title'] === 'Backup restore failed'
        && str_contains($notification->data['body'], 'psql exited with code 3'));
});

it('registers the listener that drops app SQL functions after a backup reset', function () {
    expect(Event::hasListeners(DatabaseReset::class))->toBeTrue();
});

it('leaves non-pgsql connections alone after a backup reset', function () {
    // Use an explicit SQLite connection: CI runs the suite against Postgres, where
    // the default connection is pgsql and the listener would (correctly) query it.
    $connection = DB::connection('sqlite_testing');
    $connection->enableQueryLog();

    (new DropPostgresFunctionsAfterBackupReset)->handle(new DatabaseReset(
        PendingRestore::make(disk: 'local', backup: 'backup.zip', connection: 'sqlite_testing'),
    ));

    expect($connection->getQueryLog())->toBeEmpty();
});
