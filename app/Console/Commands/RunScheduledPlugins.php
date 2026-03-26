<?php

namespace App\Console\Commands;

use App\Jobs\ExecutePluginInvocation;
use App\Models\Plugin;
use App\Plugins\PluginManager;
use Illuminate\Console\Command;

class RunScheduledPlugins extends Command
{
    protected $signature = 'plugins:run-scheduled';

    protected $description = 'Evaluate enabled scheduled plugins and queue any due invocations';

    public function handle(PluginManager $pluginManager): int
    {
        $queued = 0;
        $now = now();

        $plugins = Plugin::query()
            ->where('enabled', true)
            ->where('available', true)
            ->where('validation_status', 'valid')
            ->whereJsonContains('capabilities', 'scheduled')
            ->get();

        foreach ($plugins as $plugin) {
            foreach ($pluginManager->scheduledInvocations($plugin, $now) as $invocation) {
                dispatch(new ExecutePluginInvocation(
                    pluginId: $plugin->id,
                    invocationType: $invocation['type'] ?? 'action',
                    name: $invocation['name'] ?? $invocation['action'] ?? '',
                    payload: $invocation['payload'] ?? [],
                    options: [
                        'trigger' => 'schedule',
                        'dry_run' => (bool) ($invocation['dry_run'] ?? false),
                    ],
                ));

                $queued++;
            }
        }

        $this->info("Queued {$queued} scheduled plugin invocation(s).");

        return self::SUCCESS;
    }
}
