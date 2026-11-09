<?php

namespace App\Console\Commands;

use App\Models\Network;
use App\Services\NetworkBroadcastService;
use App\Services\NetworkScheduleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NetworkBroadcastEnsure extends Command
{
    protected $signature = 'network:broadcast:ensure {network : Network UUID or id}';

    protected $description = 'Enable a network for broadcasting, generate schedule and start the broadcast';

    public function handle(NetworkBroadcastService $broadcastService, NetworkScheduleService $scheduleService): int
    {
        $arg = $this->argument('network');

        // Treat numeric ids vs UUIDs safely to avoid type casting errors on Postgres
        if (preg_match('/^[0-9]+$/', (string) $arg)) {
            $network = Network::find((int) $arg);
        } else {
            $network = Network::where('uuid', $arg)->first();
        }

        // Fallback: try uuid lookup if arg not numeric
        if (! $network && ! preg_match('/^[0-9]+$/', (string) $arg)) {
            $network = Network::where('uuid', $arg)->first();
        }

        if (! $network) {
            $this->error('Network not found: '.$arg);

            return self::FAILURE;
        }

        $this->info('Ensuring network broadcasting for: '.$network->name);

        if (! $network->broadcast_enabled) {
            $network->update(['broadcast_enabled' => true]);
            $this->info('Enabled broadcasting on network');
        }

        // Generate schedule if missing
        if ($network->programmes()->count() === 0) {
            $this->info('Generating schedule...');
            $generatedCount = $scheduleService->generateSchedule($network);

            if ($generatedCount > 0) {
                $this->info('Schedule generated');
            } else {
                $this->warn('Schedule generation produced no programmes');
            }
        }

        // Attempt to restart broadcast (stop if running, then start)
        $this->info('Starting/restarting broadcast via heal');

        try {
            $started = $broadcastService->restart($network);
            $network->refresh();

            if ($started) {
                $this->info('Broadcast started (or already running)');
            } elseif ($network->isWaitingForConnection()) {
                // On-demand mode deferring the start until a viewer connects is a
                // healthy outcome, not a failure - do not report it as either started
                // or an error.
                $this->info('Broadcast deferred - waiting for viewer connection (on-demand)');
            } else {
                $this->error('Broadcast did not start: '.($network->broadcast_error ?? 'unknown reason'));

                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            Log::error('Failed to start broadcast via ensure command', ['network_id' => $network->id, 'error' => $e->getMessage()]);
            $this->error('Failed to start broadcast: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
