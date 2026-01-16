<?php

namespace App\Console\Commands;

use App\Jobs\SyncMediaServer;
use App\Models\MediaServerIntegration;
use Cron\CronExpression;
use Illuminate\Console\Command;

class RefreshMediaServerIntegrations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:refresh-media-server-integrations {integration?} {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh media server integrations based on their sync schedule';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $integrationId = $this->argument('integration');

        if ($integrationId) {
            $this->info("Refreshing integration with ID: {$integrationId}");
            $integration = MediaServerIntegration::findOrFail($integrationId);

            if (! $integration->enabled) {
                $this->warn('Integration is disabled, skipping');

                return;
            }

            dispatch(new SyncMediaServer($integration->id));
            $this->info('Dispatched integration for refresh');

            return;
        }

        $this->info('Checking media server integrations for scheduled sync');

        // Get all enabled integrations with auto_sync enabled
        $integrations = MediaServerIntegration::query()
            ->where('enabled', true)
            ->where('auto_sync', true)
            ->whereNotNull('playlist_id')
            ->get();

        if ($integrations->isEmpty()) {
            $this->info('No integrations available for refresh');

            return;
        }

        $count = 0;
        foreach ($integrations as $integration) {
            $cronExpression = new CronExpression($integration->sync_interval);

            // Check if sync is due (with a 1-minute buffer)
            $lastRun = $integration->last_synced_at ?? now()->subYears(1);
            $nextDue = $cronExpression->getNextRunDate($lastRun->toDateTimeImmutable());

            if (now() >= $nextDue) {
                $count++;
                dispatch(new SyncMediaServer($integration->id));
                $this->line("Dispatched: {$integration->name}");
            }
        }

        $this->info("Dispatched {$count} integrations for refresh");
    }
}
