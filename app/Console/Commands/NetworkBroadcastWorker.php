<?php

namespace App\Console\Commands;

use App\Services\NetworkBroadcastService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NetworkBroadcastWorker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'network:broadcast
                            {network? : Specific network UUID to broadcast (omit for all)}
                            {--once : Run once and exit instead of looping}
                            {--interval=5 : Seconds between worker ticks}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage continuous broadcasting for networks';

    /**
     * Execute the console command.
     */
    public function handle(NetworkBroadcastService $service): int
    {
        $networkUuid = $this->argument('network');
        $runOnce = $this->option('once');
        $interval = (int) $this->option('interval');

        if ($networkUuid) {
            return $this->runSingleNetwork($service, $networkUuid, $runOnce, $interval);
        }

        return $this->runAllNetworks($service, $runOnce, $interval);
    }

    /**
     * Run the worker for a single network.
     */
    protected function runSingleNetwork(
        NetworkBroadcastService $service,
        string $uuid,
        bool $runOnce,
        int $interval
    ): int {
        $network = \App\Models\Network::where('uuid', $uuid)->first();

        if (! $network) {
            $this->error("Network not found: {$uuid}");

            return self::FAILURE;
        }

        $this->info("Starting broadcast worker for: {$network->name}");

        if ($runOnce) {
            $result = $service->tick($network);
            $this->displayTickResult($network->name, $result);

            return self::SUCCESS;
        }

        // Boot recovery: ensure broadcast_requested is set and stale state is cleared
        // so the tick loop can restart the broadcast after a container reboot
        $recovered = $service->performBootRecovery($network);
        if ($recovered > 0) {
            $this->info("Boot recovery: marked {$network->name} for broadcast restart");
        }

        // Continuous loop with resilience (catch exceptions and apply exponential backoff)
        $this->info('Running in continuous mode (Ctrl+C to stop)...');
        $this->info("Tick interval: {$interval} seconds");

        $backoff = 1; // seconds
        while (true) {
            try {
                $result = $service->tick($network);

                if ($result['action'] !== 'monitoring') {
                    $this->displayTickResult($network->name, $result);
                }

                // Reset backoff after successful tick
                $backoff = 1;

                sleep($interval);
            } catch (\Throwable $e) {
                // Log and backoff to prevent crash loops
                Log::error('Network broadcast worker exception (single network)', [
                    'network' => $network->id,
                    'error' => $e->getMessage(),
                ]);

                sleep($backoff);
                $backoff = min($backoff * 2, 60);
            }
        }
    }

    /**
     * Run the worker for all broadcasting networks.
     */
    protected function runAllNetworks(
        NetworkBroadcastService $service,
        bool $runOnce,
        int $interval
    ): int {
        $this->info('Starting broadcast worker for all enabled networks');

        if ($runOnce) {
            $networks = $service->getBroadcastingNetworks();
            $this->info("Found {$networks->count()} enabled network(s)");

            foreach ($networks as $network) {
                $result = $service->tick($network);
                $this->displayTickResult($network->name, $result);
            }

            return self::SUCCESS;
        }

        // Boot recovery: ensure broadcast_requested is set and stale state is cleared
        // so the tick loop can restart broadcasts after a container reboot
        $recovered = $service->performBootRecovery();
        if ($recovered > 0) {
            $this->info("Boot recovery: marked {$recovered} network(s) for broadcast restart");
        }

        // Continuous loop with resilience (catch exceptions and apply exponential backoff)
        $this->info('Running in continuous mode (Ctrl+C to stop)...');
        $this->info("Tick interval: {$interval} seconds");

        $backoff = 1; // seconds
        while (true) {
            try {
                $networks = $service->getBroadcastingNetworks();

                foreach ($networks as $network) {
                    $result = $service->tick($network);

                    if ($result['action'] !== 'monitoring') {
                        $this->displayTickResult($network->name, $result);
                    }
                }

                // Reset backoff after successful traversal
                $backoff = 1;

                sleep($interval);
            } catch (\Throwable $e) {
                Log::error('Network broadcast worker exception (all networks)', [
                    'error' => $e->getMessage(),
                ]);

                // Exponential backoff to avoid crash-fast behavior
                sleep($backoff);
                $backoff = min($backoff * 2, 60);
            }
        }
    }

    /**
     * Display the result of a tick operation.
     */
    protected function displayTickResult(string $networkName, array $result): void
    {
        $action = $result['action'];
        $success = $result['success'] ?? true;
        $programme = $result['programme'] ?? 'Unknown';
        $remaining = $result['remaining_seconds'] ?? 0;

        $statusIcon = $success ? '✓' : '✗';
        $statusColor = $success ? 'green' : 'red';

        $message = match ($action) {
            'started' => "Started broadcasting: {$programme}",
            'stopped' => 'Stopped broadcasting',
            'stopped_no_content' => 'Stopped (no content scheduled)',
            'monitoring' => "Running ({$remaining}s remaining)",
            'none' => 'No action needed',
            default => "Action: {$action}",
        };

        $this->line("<fg={$statusColor}>{$statusIcon}</> [{$networkName}] {$message}");

        // Log significant actions
        if (in_array($action, ['started', 'stopped', 'stopped_no_content'])) {
            Log::info("Network broadcast: {$action}", [
                'network' => $networkName,
                'result' => $result,
            ]);
        }
    }
}
