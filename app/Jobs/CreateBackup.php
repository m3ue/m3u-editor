<?php

namespace App\Jobs;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use App\Models\User;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreateBackup implements ShouldQueue
{
    use Queueable;

    // Only try to process the job twice
    public $tries = 2;

    // Giving a timeout of 10 minutes to the Job to process the mapping
    public $timeout = 60 * 10;

    // Maximum number of times to defer the job when SQLite is busy with a sync
    private const MAX_SYNC_DEFERRALS = 5;

    /**
     * Create a new job instance.
     */
    public function __construct(public bool $includeFiles = false, public int $syncDeferrals = 0)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // On SQLite, a concurrent sync write can silently corrupt the dump (exit 0,
        // empty file), causing ZipArchive to fail when finalizing. Defer and retry.
        if (DB::connection()->getDriverName() === 'sqlite') {
            $hasActiveSyncs = SyncRun::where('status', SyncRunStatus::Running->value)->exists();
            if ($hasActiveSyncs && $this->syncDeferrals < self::MAX_SYNC_DEFERRALS) {
                Log::info('[backup] Deferring backup: SQLite sync in progress.', ['deferral' => $this->syncDeferrals + 1]);
                dispatch(new self($this->includeFiles, $this->syncDeferrals + 1))->delay(now()->addMinutes(2));

                return;
            }
        }

        try {
            // Create a new backup
            Artisan::call('backup:run', [
                '--only-db' => ! $this->includeFiles,
            ]);

            // Notify the admin that the backup was restored
            $user = User::where('is_admin', true)->first();
            if ($user) {
                $message = 'Backup created successfully';
                Notification::make()
                    ->success()
                    ->title('Backup created')
                    ->body($message)
                    ->broadcast($user);
                Notification::make()
                    ->success()
                    ->title('Backup created')
                    ->body($message)
                    ->sendToDatabase($user);
            }
        } catch (Exception $e) {
            // Log the error
            logger()->error('Failed to create backup', ['error' => $e->getMessage()]);

            // Notify the admin that the backup was restored
            $user = User::where('is_admin', true)->first();
            if ($user) {
                $message = "Backup create failed: {$e->getMessage()}";
                Notification::make()
                    ->danger()
                    ->title('Backup create failed')
                    ->body('Backup create failed, please check the error logs for details')
                    ->broadcast($user);
                Notification::make()
                    ->danger()
                    ->title('Backup create failed')
                    ->body(Str::limit($message, 500))
                    ->sendToDatabase($user);
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Backup creation failed: {$exception->getMessage()}");
    }
}
