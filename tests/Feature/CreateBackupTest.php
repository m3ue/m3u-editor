<?php

use App\Jobs\CreateBackup;
use App\Listeners\BackupFailed;
use App\Models\User;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Spatie\Backup\Events\BackupHasFailed;

beforeEach(function () {
    // The job notifies the first admin, which may be one the migrations created.
    User::factory()->admin()->create();
    $this->admin = User::where('is_admin', true)->first();
    NotificationFacade::fake();
});

it('does not announce a failed backup:run as created', function () {
    // backup:run signals failure through its exit code (and the BackupHasFailed event)
    // rather than by throwing.
    Artisan::shouldReceive('call')->once()->with('backup:run', ['--only-db' => true])->andReturn(1);
    Artisan::shouldReceive('output')->andReturn("Backup failed because: pg_dump: error: aborting because of server version mismatch.\n");

    dispatch_sync(new CreateBackup);

    NotificationFacade::assertNotSentTo($this->admin, DatabaseNotification::class, fn (DatabaseNotification $notification): bool => $notification->data['title'] === 'Backup created');
});

it('announces a successful backup:run as created', function () {
    Artisan::shouldReceive('call')->once()->with('backup:run', ['--only-db' => false])->andReturn(0);

    dispatch_sync(new CreateBackup(includeFiles: true));

    NotificationFacade::assertSentTo($this->admin, DatabaseNotification::class, fn (DatabaseNotification $notification): bool => $notification->data['title'] === 'Backup created');
});

it('tells the admin why a backup failed', function () {
    (new BackupFailed)->handle(new BackupHasFailed(new Exception('pg_dump: error: aborting because of server version mismatch')));

    NotificationFacade::assertSentTo($this->admin, DatabaseNotification::class, fn (DatabaseNotification $notification): bool => $notification->data['title'] === 'Backup failed'
        && str_contains($notification->data['body'], 'server version mismatch'));
});
