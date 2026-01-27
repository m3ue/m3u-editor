<?php

namespace App\Jobs;

use App\Models\Epg;
use App\Services\SchedulesDirectService;
use Carbon\Carbon;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class ProcessEpgSDImport implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    // Delete the job if the model is missing
    public $deleteWhenMissingModels = true;

    // Giving a timeout of 10 minutes to the Job to process the file
    public $timeout = 60 * 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Epg $epg,
        public ?bool $force = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SchedulesDirectService $service): bool
    {
        // Check if file recently modified
        $epg = $this->epg;
        $start = now();
        try {
            // Notify user we're starting the sync...
            Notification::make()
                ->info()
                ->title('Starting SchedulesDirect Data Sync')
                ->body("SchedulesDirect Data Sync started for EPG \"{$epg->name}\".")
                ->broadcast($epg->user)
                ->sendToDatabase($epg->user);

            if (! $this->force) {
                // If not forcing, check last modified time
                $lastModified = Storage::disk('local')->exists($epg->file_path)
                    ? Storage::disk('local')->lastModified($epg->file_path)
                    : null;

                if ($lastModified) {
                    $lastModifiedTime = Carbon::createFromTimestamp($lastModified);
                    $lastModifiedTime->addMinutes(10); // Add 10 minutes to last modified time
                    if (! $lastModifiedTime->isPast()) { // If modified within the last 10 minutes, skip
                        return true;
                    }
                }
                $service->syncEpgData($epg);
            } else {
                // Force processing regardless of last modified time
                $service->syncEpgData($epg);
            }

            // Calculate the time taken to complete the import
            $completedIn = $start->diffInSeconds(now());
            $completedInRounded = round($completedIn, 2);

            // Notify user of success
            Notification::make()
                ->success()
                ->title('SchedulesDirect Data Synced')
                ->body("SchedulesDirect Data Synced successfully for EPG \"{$epg->name}\". Completed in {$completedInRounded} seconds. Now parsing data and generating EPG cache...")
                ->broadcast($epg->user)
                ->sendToDatabase($epg->user);

            return true;
        } catch (Exception $e) {
            // Log the exception
            logger()->error("Error processing SchedulesDirect Data for EPG \"{$this->epg->name}\"");

            // Send notification
            $error = 'Error: '.$e->getMessage();
            Notification::make()
                ->danger()
                ->title("Error processing SchedulesDirect Data for EPG \"{$this->epg->name}\"")
                ->body('Please view your notifications for details.')
                ->broadcast($this->epg->user);
            Notification::make()
                ->danger()
                ->title("Error processing SchedulesDirect Data for EPG \"{$this->epg->name}\"")
                ->body($error)
                ->sendToDatabase($this->epg->user);
        }

        return false;
    }
}
