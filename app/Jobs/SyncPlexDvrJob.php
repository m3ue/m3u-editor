<?php

namespace App\Jobs;

use App\Models\MediaServerIntegration;
use App\Services\PlexManagementService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncPlexDvrJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Queueable;

    /**
     * @param  int|null  $integrationId  Sync a specific integration. Null = sync all.
     * @param  string  $trigger  What triggered this sync (for logging).
     */
    public function __construct(
        public ?int $integrationId = null,
        public string $trigger = 'unknown',
    ) {}

    /**
     * Unique ID prevents duplicate jobs for the same integration.
     */
    public function uniqueId(): string
    {
        return 'sync-plex-dvr-'.($this->integrationId ?? 'all');
    }

    /**
     * Allow the job to be unique for 60 seconds (debounce window).
     * If multiple events fire within this window, only one job runs.
     */
    public function uniqueFor(): int
    {
        return 60;
    }

    public function handle(): void
    {
        $query = MediaServerIntegration::query()
            ->where('enabled', true)
            ->where('plex_management_enabled', true)
            ->whereNotNull('plex_dvr_id')
            ->whereNotNull('plex_dvr_tuners');

        if ($this->integrationId) {
            $query->where('id', $this->integrationId);
        }

        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            return;
        }

        foreach ($integrations as $integration) {
            try {
                $service = PlexManagementService::make($integration);
                $result = $service->syncDvrChannels();

                if ($result['success']) {
                    $status = ($result['changed'] ?? false) ? 'UPDATED' : 'OK';
                    Log::info("SyncPlexDvrJob: [{$status}] {$result['message']}", [
                        'integration_id' => $integration->id,
                        'trigger' => $this->trigger,
                    ]);
                } else {
                    Log::warning("SyncPlexDvrJob: [FAILED] {$result['message']}", [
                        'integration_id' => $integration->id,
                        'trigger' => $this->trigger,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('SyncPlexDvrJob: Exception syncing integration', [
                    'integration_id' => $integration->id,
                    'trigger' => $this->trigger,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
