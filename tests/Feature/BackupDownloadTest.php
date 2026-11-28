<?php

use App\Filament\Pages\Backups;
use App\Jobs\CreateBackup;
use App\Livewire\BackupDestinationListRecords;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function encodedBackupPath(string $path): string
{
    return rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
}

it('streams backup downloads through an authenticated admin route', function () {
    config(['backup.backup.destination.disks' => ['local']]);
    Storage::fake('local');

    $backupPath = 'm3u-editor-backups/large-backup.zip';
    $backupContents = str_repeat('backup-payload-', 128);
    Storage::disk('local')->put($backupPath, $backupContents);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('backups.download', [
            'disk' => 'local',
            'path' => encodedBackupPath($backupPath),
        ]))
        ->assertOk()
        ->assertHeader('content-type', 'application/zip')
        ->assertHeader('content-length', (string) strlen($backupContents))
        ->assertHeader('content-disposition', 'attachment; filename=large-backup.zip')
        ->assertStreamedContent($backupContents);
});

it('prevents non-admin users from downloading backups', function () {
    Storage::fake('local');

    $backupPath = 'm3u-editor-backups/large-backup.zip';
    Storage::disk('local')->put($backupPath, 'backup-payload');

    $this->actingAs(User::factory()->create())
        ->get(route('backups.download', [
            'disk' => 'local',
            'path' => encodedBackupPath($backupPath),
        ]))
        ->assertForbidden();
});

it('returns not found for missing backup files', function () {
    config(['backup.backup.destination.disks' => ['local']]);
    Storage::fake('local');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('backups.download', [
            'disk' => 'local',
            'path' => encodedBackupPath('m3u-editor-backups/missing.zip'),
        ]))
        ->assertNotFound();
});

it('rejects path traversal sequences', function () {
    config(['backup.backup.destination.disks' => ['local']]);
    Storage::fake('local');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('backups.download', [
            'disk' => 'local',
            'path' => encodedBackupPath('../../etc/passwd'),
        ]))
        ->assertNotFound();
});

it('renders the Backups page and its backup list for an admin', function () {
    config(['backup.backup.destination.disks' => ['local']]);
    Storage::fake('local');
    Storage::disk('local')->put(config('backup.backup.name').'/2026-11-18-08-00-00.zip', 'backup-payload');

    $this->actingAs(User::factory()->admin()->create())
        ->get(Backups::getUrl())
        ->assertOk();

    Livewire::test(BackupDestinationListRecords::class)
        ->assertOk()
        ->assertSee('2026-11-18-08-00-00.zip')
        ->assertSeeHtml('wire:poll.30s');
});

it('shows the Create, Upload and Restore backup actions on the Backups page', function () {
    // The plugin's base page defines its own getHeaderActions(), so ours must override
    // that method; the deprecated getActions() hook is no longer reached.
    config(['backup.backup.destination.disks' => ['local']]);
    Storage::fake('local');
    Bus::fake();

    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(Backups::class)
        ->assertActionExists('Restore Backup')
        ->assertActionExists('Upload Backup')
        ->assertActionVisible('Create Backup')
        ->callAction('Create Backup', ['include_files' => true])
        ->assertNotified();

    Bus::assertDispatched(CreateBackup::class);
});
