<?php

namespace App\Jobs;

use App\Models\Network;
use App\Services\NetworkScheduleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RegenerateNetworkSchedule implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $networkId,
    ) {}

    public function handle(NetworkScheduleService $scheduleService): void
    {
        $network = Network::find($this->networkId);

        if (! $network) {
            return;
        }

        if (! in_array($network->schedule_type, ['sequential', 'shuffle'])) {
            return;
        }

        if ($network->auto_regenerate_schedule === false) {
            return;
        }

        Log::info('Regenerating network schedule after content change', [
            'network_id' => $network->id,
            'network_name' => $network->name,
            'schedule_type' => $network->schedule_type,
        ]);

        try {
            $scheduleService->generateSchedule($network);
        } catch (Throwable $e) {
            Log::error('Failed to regenerate schedule after content addition', [
                'network_id' => $network->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
