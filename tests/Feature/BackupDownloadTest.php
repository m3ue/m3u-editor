<?php

use App\Models\User;
use Illuminate\Support\Facades\Storage;

function encodedBackupPath(string $path): string
{
    return rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
}

it('streams backup downloads through an authenticated admin route', function () {
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
    Storage::fake('local');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('backups.download', [
            'disk' => 'local',
            'path' => encodedBackupPath('m3u-editor-backups/missing.zip'),
        ]))
        ->assertNotFound();
});
