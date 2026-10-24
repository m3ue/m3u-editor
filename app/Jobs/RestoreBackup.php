<?php

namespace App\Jobs;

use App\Models\Job;
use App\Models\User;
use App\Services\PostgresDumpBinaryResolver;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Session;

class RestoreBackup implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $backupPath)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(PostgresDumpBinaryResolver $dumpBinaryResolver): void
    {
        $connectionName = config('backup.backup.source.databases')[0] ?? config('database.default');
        $dumpBinaryResolver->configure($connectionName);
        $isSqlite = config("database.connections.{$connectionName}.driver") === 'sqlite';
        $livePath = $isSqlite ? config("database.connections.{$connectionName}.database") : null;
        $safetyCopy = null;

        try {
            // Flush the jobs table
            Job::truncate();

            // Put app into maintenance mode
            Artisan::call('down', [
                '--refresh' => 15, // refresh in 15s
            ]);

            if ($isSqlite) {
                // Pause Horizon so queue workers stop writing to the live database
                // for the restore window - `backup:restore --reset` imports the
                // dump straight into the live SQLite file, and a write landing
                // mid-import can lock or corrupt it.
                Artisan::call('horizon:pause');

                // Cheap insurance: keep a copy of the live database so a bad
                // restore has something to roll back to.
                if (File::exists($livePath)) {
                    $safetyCopy = $livePath.'.pre-restore-'.now()->timestamp;
                    File::copy($livePath, $safetyCopy);
                }
            }

            // Restore the selected backup
            Artisan::call('backup:restore', [
                '--backup' => $this->backupPath,
                '--reset' => true, // reset DB before restore?
                '--no-interaction' => true,
            ]);

            if ($isSqlite) {
                DB::purge($connectionName);
                $result = DB::connection($connectionName)->selectOne('PRAGMA integrity_check');

                if (($result->integrity_check ?? null) !== 'ok') {
                    if ($safetyCopy) {
                        DB::purge($connectionName);
                        File::copy($safetyCopy, $livePath);
                    }

                    throw new Exception('Restore produced a malformed database (failed integrity_check)'.($safetyCopy ? ', rolled back to the pre-restore database' : ''));
                }
            }

            // If restoring from an older version of the app, make sure we run migrations
            Artisan::call('migrate', ['--force' => true]);

            // Clear invalid session data
            Session::flush();

            // Pause to allow the restore to complete
            sleep(3);

            // Notify the admin that the backup was restored
            $user = User::where('is_admin', true)->first();
            if ($user) {
                $message = "Backup restored successfully - restored: \"$this->backupPath\"";
                Notification::make()
                    ->success()
                    ->title('Backup restored successfully')
                    ->body($message)
                    ->broadcast($user);
                Notification::make()
                    ->success()
                    ->title('Backup restored successfully')
                    ->body($message)
                    ->sendToDatabase($user);
            }

            if ($safetyCopy) {
                $this->pruneOldSafetyCopies($livePath);
            }
        } catch (Exception $e) {
            // Log the error
            logger()->error('Failed to restore backup', ['error' => $e->getMessage()]);

            // Notify the admin that the backup was restored
            $user = User::where('is_admin', true)->first();
            if ($user) {
                $message = "Backup restore (\"$this->backupPath\") failed: {$e->getMessage()}";
                Notification::make()
                    ->danger()
                    ->title('Backup restore failed')
                    ->body($message)
                    ->broadcast($user);
                Notification::make()
                    ->danger()
                    ->title('Backup restore failed')
                    ->body($message)
                    ->sendToDatabase($user);
            }
        } finally {
            if ($isSqlite) {
                Artisan::call('horizon:continue');
            }

            // Bring app back up
            Artisan::call('up');
        }
    }

    /**
     * Keep only the most recent pre-restore safety copies so they don't
     * accumulate unbounded next to the live database.
     */
    protected function pruneOldSafetyCopies(string $livePath, int $keep = 3): void
    {
        collect(File::glob($livePath.'.pre-restore-*'))
            ->sortByDesc(fn (string $path) => $path)
            ->slice($keep)
            ->each(fn (string $path) => File::delete($path));
    }
}
