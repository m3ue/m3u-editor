<?php

namespace App\Jobs;

use App\Models\MediaServerIntegration;
use App\Services\MediaServerService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RefreshMediaServerLibraryJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public MediaServerIntegration $integration,
        public bool $notify = true,
    ) {
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Support Jellyfin, Emby, and Plex
        if (! in_array($this->integration->type, ['jellyfin', 'emby', 'plex'])) {
            Log::warning('RefreshMediaServerLibraryJob: Unsupported media server type', [
                'integration_id' => $this->integration->id,
                'type' => $this->integration->type,
            ]);

            return;
        }

        $service = MediaServerService::make($this->integration);
        $result = $service->refreshLibrary();

        if ($result['success']) {
            Log::info('RefreshMediaServerLibraryJob: Library refresh triggered', [
                'integration_id' => $this->integration->id,
                'server_name' => $this->integration->name,
            ]);

            if ($this->notify) {
                Notification::make()
                    ->success()
                    ->title('Media Server Library Refresh')
                    ->body("Library scan triggered on \"{$this->integration->name}\".")
                    ->broadcast($this->integration->user)
                    ->sendToDatabase($this->integration->user);
            }
        } else {
            Log::error('RefreshMediaServerLibraryJob: Failed to trigger library refresh', [
                'integration_id' => $this->integration->id,
                'message' => $result['message'],
            ]);

            if ($this->notify) {
                Notification::make()
                    ->danger()
                    ->title('Media Server Library Refresh Failed')
                    ->body("Failed to trigger library scan on \"{$this->integration->name}\": {$result['message']}")
                    ->broadcast($this->integration->user)
                    ->sendToDatabase($this->integration->user);
            }
        }
    }
}
