<?php

namespace App\Console\Commands;

use App\Models\Network;
use App\Services\NetworkBroadcastService;
use Illuminate\Console\Command;

class CleanupNetworkSegments extends Command
{
    protected $signature = 'network:cleanup-segments';

    protected $description = 'Remove old HLS segments from all networks';

    public function handle(NetworkBroadcastService $service): int
    {
        $networks = Network::whereNotNull('broadcast_started_at')->get();

        $totalDeleted = 0;

        foreach ($networks as $network) {
            $deleted = $service->cleanupSegments($network);
            $totalDeleted += $deleted;

            if ($deleted > 0) {
                $this->line("Network {$network->name}: deleted {$deleted} old segments");
            }
        }

        $this->info("Cleaned up {$totalDeleted} total segments");

        return self::SUCCESS;
    }
}
