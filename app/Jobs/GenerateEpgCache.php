<?php

namespace App\Jobs;

use Throwable;
use App\Enums\Status;
use App\Models\Epg;
use App\Services\EpgCacheService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateEpgCache implements ShouldQueue
{
    use Queueable;

    // Giving a timeout of 120 minutes to the Job to generate the cache
    // This should be sufficient for most EPGs, but can be adjusted if needed
    public $timeout = 60 * 120;

    // Allow up to 2 attempts (1 retry)
    public $tries = 2;

    // Delay between attempts if it fails
    public $backoff = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $uuid,
        public bool $notify = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EpgCacheService $cacheService): void
    {
        $epg = Epg::where('uuid', $this->uuid)->first();
        if (!$epg) {
            Log::error("EPG with UUID {$this->uuid} not found for cache generation.");
            return;
        }

        // Set memory and time limits for large EPG files
        ini_set('memory_limit', '2G');
        set_time_limit(0); // No time limit
        $start = microtime(true);
        $epg->update([
            'status' => Status::Processing,
            'processing_started_at' => now(),
            'processing_phase' => 'cache',
        ]);
        $result = $cacheService->cacheEpgData($epg);
        $duration = microtime(true) - $start;
        if ($result) {
            $epg->update([
                'status' => Status::Completed,
                'is_cached' => true,
                'cache_progress' => 100,
                'processing_started_at' => null,
                'processing_phase' => null,
            ]);
            if ($this->notify) {
                $msg = "Cache generated successfully in " . round($duration, 2) . " seconds";
                Notification::make()
                    ->success()
                    ->title("EPG cache created for \"{$epg->name}\"")
                    ->body($msg)
                    ->broadcast($epg->user)
                    ->sendToDatabase($epg->user);
            }
        } else {
            $epg->update([
                'status' => Status::Failed,
                'is_cached' => false,
                'cache_progress' => 100,
                'processing_started_at' => null,
                'processing_phase' => null,
            ]);
            $error = "Failed to generate cache. You can try to run the cache generation again manually from the EPG management page.";
            Notification::make()
                ->danger()
                ->title("Error creating cache for \"{$epg->name}\"")
                ->body($error)
                ->broadcast($epg->user)
                ->sendToDatabase($epg->user);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        $epg = Epg::where('uuid', $this->uuid)->first();
        if ($epg) {
            // We'll just log a warning since this is likely an false-positive ("Job has been tried too many times") as the job typically finishes successfully on retry
            Log::warning("EPG cache generation failed for {$epg->name}: {$exception->getMessage()}");
        }
    }
}
