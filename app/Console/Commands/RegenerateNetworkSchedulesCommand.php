<?php

namespace App\Console\Commands;

use App\Models\Network;
use App\Services\NetworkScheduleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RegenerateNetworkSchedulesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'networks:regenerate-schedules
                            {--force : Force regeneration even if not needed}
                            {--network= : Regenerate a specific network by ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Regenerate programme schedules for networks that need it';

    /**
     * Execute the console command.
     */
    public function handle(NetworkScheduleService $scheduleService): int
    {
        $force = $this->option('force');
        $networkId = $this->option('network');

        if ($networkId) {
            $network = Network::find($networkId);
            if (! $network) {
                $this->error("Network with ID {$networkId} not found.");

                return Command::FAILURE;
            }

            $this->regenerateNetwork($scheduleService, $network, $force);

            return Command::SUCCESS;
        }

        // Get all enabled networks
        $networks = Network::where('enabled', true)->get();

        if ($networks->isEmpty()) {
            $this->info('No enabled networks found.');

            return Command::SUCCESS;
        }

        $regenerated = 0;
        $skipped = 0;

        foreach ($networks as $network) {
            if ($force || $network->needsScheduleRegeneration()) {
                $this->regenerateNetwork($scheduleService, $network, $force);
                $regenerated++;
            } else {
                $this->line("  <comment>Skipped:</comment> {$network->name} (schedule still valid)");
                $skipped++;
            }
        }

        $this->newLine();
        $this->info("Completed: {$regenerated} regenerated, {$skipped} skipped.");

        return Command::SUCCESS;
    }

    /**
     * Regenerate schedule for a single network.
     */
    protected function regenerateNetwork(NetworkScheduleService $scheduleService, Network $network, bool $force): void
    {
        $reason = $force ? 'forced' : 'schedule needed';

        $this->line("  <info>Regenerating:</info> {$network->name} ({$reason})");

        try {
            $programmeCount = $scheduleService->generateSchedule($network);

            $lastProgramme = $network->programmes()->latest('end_time')->first();
            $endDate = $lastProgramme?->end_time?->format('M j, Y H:i') ?? 'unknown';

            $this->line("    Generated {$programmeCount} programmes until {$endDate}");

            Log::info('Regenerated network schedule', [
                'network_id' => $network->id,
                'network_name' => $network->name,
                'programmes' => $programmeCount,
            ]);
        } catch (\Exception $e) {
            $this->error("    Failed: {$e->getMessage()}");

            Log::error('Failed to regenerate network schedule', [
                'network_id' => $network->id,
                'network_name' => $network->name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
