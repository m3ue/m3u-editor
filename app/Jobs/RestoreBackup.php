<?php

namespace App\Jobs;

use App\Models\Job;
use App\Models\User;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
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
    public function handle(): void
    {
        try {
            // Flush the jobs table
            Job::truncate();

            // Put app into maintenance mode
            Artisan::call('down', [
                '--refresh' => 15, // refresh in 15s
            ]);

            // Restore the selected backup
            Artisan::call('backup:restore', [
                '--backup' => $this->backupPath,
                '--reset' => true, // reset DB before restore?
                '--no-interaction' => true,
            ]);

            // If restoring from an older version of the app, make sure we run migrations
            Artisan::call('migrate', ['--force' => true]);

            // Clear invalid session data
            Session::flush();

            // Pause to allow the restore to complete
            sleep(3);

            // Notify the admin that the backup was restored
            $user = User::whereIn('email', config('dev.admin_emails'))->first();
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
        } catch (Exception $e) {
            // Log the error
            logger()->error('Failed to restore backup', ['error' => $e->getMessage()]);

            // Notify the admin that the backup was restored
            $user = User::whereIn('email', config('dev.admin_emails'))->first();
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
            // Bring app back up
            Artisan::call('up');
        }
    }
}
