<?php

namespace App\Console\Commands;

use App\Models\MediaServerIntegration;
use App\Services\PlexManagementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncPlexDvr extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-plex-dvr {integration? : Sync a specific integration by ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Plex DVR channel maps with current HDHR lineup for all configured integrations';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $integrationId = $this->argument('integration');

        $integrations = MediaServerIntegration::query()
            ->eligibleForPlexDvr($integrationId ? (int) $integrationId : null)
            ->get();

        if ($integrations->isEmpty()) {
            $this->info('No Plex integrations with active DVR found.');

            return;
        }

        foreach ($integrations as $integration) {
            $this->info("Syncing DVR for: {$integration->name} (ID: {$integration->id})");

            try {
                $service = PlexManagementService::make($integration);
                $result = $service->syncDvrChannels();

                if ($result['success']) {
                    $status = ($result['changed'] ?? false) ? 'UPDATED' : 'OK';
                    $this->info("  [{$status}] {$result['message']}");
                } else {
                    $this->warn("  [FAILED] {$result['message']}");
                }
            } catch (\Exception $e) {
                Log::error('SyncPlexDvr: Exception syncing integration', [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  [ERROR] {$e->getMessage()}");
            }
        }
    }
}
