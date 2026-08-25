<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;

class RecoverStalePluginRuns extends Command
{
    protected $signature = 'plugins:recover-stale-runs
        {--minutes= : Minutes without heartbeat before a run is eligible to be marked stale}
        {--minimum-runtime= : Minimum run time in minutes before a run is eligible to be marked stale}';

    protected $description = 'Mark running plugin invocations as stale when they have stopped sending heartbeats.';

    public function handle(PluginManager $pluginManager): int
    {
        $heartbeatMinutes = $this->option('minutes');
        $minimumRuntimeMinutes = $this->option('minimum-runtime');

        if ($heartbeatMinutes !== null && filter_var($heartbeatMinutes, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error('Heartbeat expiry minutes must be at least 1.');

            return self::FAILURE;
        }

        if ($minimumRuntimeMinutes !== null && filter_var($minimumRuntimeMinutes, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error('Minimum runtime minutes must be at least 1.');

            return self::FAILURE;
        }

        $recovered = $pluginManager->recoverStaleRuns(
            $heartbeatMinutes === null ? null : (int) $heartbeatMinutes,
            $minimumRuntimeMinutes === null ? null : (int) $minimumRuntimeMinutes,
        );

        $this->info("Recovered {$recovered} stale plugin run(s).");

        return self::SUCCESS;
    }
}
