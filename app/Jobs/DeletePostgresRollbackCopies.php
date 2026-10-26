<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\EmbeddedPostgresStatus;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;

class DeletePostgresRollbackCopies implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    /**
     * Prevent a second click while a large rollback copy is still being deleted.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('delete-postgres-rollback-copies'))->dontRelease()];
    }

    /**
     * Execute the job.
     */
    public function handle(EmbeddedPostgresStatus $status): void
    {
        try {
            $status->deleteRetainedCopies();

            $user = User::where('is_admin', true)->first();
            if ($user) {
                $message = 'PostgreSQL rollback copy deleted successfully';
                Notification::make()
                    ->success()
                    ->title('PostgreSQL rollback copy deleted')
                    ->body($message)
                    ->broadcast($user);
                Notification::make()
                    ->success()
                    ->title('PostgreSQL rollback copy deleted')
                    ->body($message)
                    ->sendToDatabase($user);
            }
        } catch (Exception $e) {
            logger()->error('Failed to delete PostgreSQL rollback copy', ['error' => $e->getMessage()]);

            $user = User::where('is_admin', true)->first();
            if ($user) {
                $message = "Deleting the PostgreSQL rollback copy failed: {$e->getMessage()}";
                Notification::make()
                    ->danger()
                    ->title('Deleting PostgreSQL rollback copy failed')
                    ->body('Please check the error logs for details')
                    ->broadcast($user);
                Notification::make()
                    ->danger()
                    ->title('Deleting PostgreSQL rollback copy failed')
                    ->body(Str::limit($message, 500))
                    ->sendToDatabase($user);
            }
        }
    }
}
