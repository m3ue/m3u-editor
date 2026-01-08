<?php

namespace App\Jobs;

use App\Models\User;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreateBackup implements ShouldQueue
{
    use Queueable;

    // Only try to process the job twice
    public $tries = 2;

    // Giving a timeout of 10 minutes to the Job to process the mapping
    public $timeout = 60 * 10;

    /**
     * Create a new job instance.
     */
    public function __construct(public bool $includeFiles = false)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Create a new backup
            Artisan::call('backup:run', [
                '--only-db' => ! $this->includeFiles,
            ]);

            // Notify the admin that the backup was restored
            $user = User::whereIn('email', config('dev.admin_emails'))->first();
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
            $user = User::whereIn('email', config('dev.admin_emails'))->first();
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
