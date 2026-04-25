<?php

namespace App\Jobs;

use App\Models\MediaServerIntegration;
use App\Services\MediaServerService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RefreshMediaServerLibraryJob implements ShouldBeUnique, ShouldQueue
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
     * Debounce window in seconds.
     *
     * Prevents library refresh spam when many sync jobs (e.g. a bulk STRM
     * sync of 2000+ series) each try to dispatch a refresh for the same
     * media server. While a job for a given integration is queued or
     * running, additional dispatches within this window are silently
     * dropped by the queue.
     */
    public int $uniqueFor = 300;

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
     * Get the unique ID for the job.
     *
     * Scoped per integration so refreshes for different media servers do
     * not block each other, but repeated refreshes for the same server
     * collapse into a single execution.
     */
    public function uniqueId(): string
    {
        return 'refresh-media-server-'.$this->integration->id;
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
