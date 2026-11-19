<?php

namespace App\Console\Commands;

use App\Services\OptionalQueueSupervisors;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:start-optional-queue-supervisors')]
#[Description('Start the Horizon supervisors of optional features (DVR, SchedulesDirect, AIOStreams, plugins, cached content) left out at startup, once the feature is used.')]
class StartOptionalQueueSupervisors extends Command
{
    public function handle(OptionalQueueSupervisors $supervisors): int
    {
        foreach ($supervisors->startNeededSupervisors() as $name) {
            $this->info("Starting Horizon supervisor {$name}.");
        }

        return self::SUCCESS;
    }
}
