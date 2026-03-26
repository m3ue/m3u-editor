<?php

namespace App\Console\Commands;

use App\Models\Plugin;
use App\Plugins\PluginManager;
use Illuminate\Console\Command;

class VerifyPluginIntegrity extends Command
{
    protected $signature = 'plugins:verify-integrity {pluginId?}';

    protected $description = 'Refresh integrity state for one plugin or all discovered plugins.';

    public function handle(PluginManager $pluginManager): int
    {
        $pluginId = $this->argument('pluginId');
        $pluginManager->discover();

        $plugins = Plugin::query()
            ->when($pluginId, fn ($query) => $query->where('plugin_id', $pluginId))
            ->get();

        if ($plugins->isEmpty()) {
            $this->error('No matching plugins found.');

            return self::FAILURE;
        }

        foreach ($plugins as $plugin) {
            $plugin = $pluginManager->verifyIntegrity($plugin);
            $this->line("{$plugin->plugin_id}: integrity={$plugin->integrity_status}, trust={$plugin->trust_state}");
        }

        return self::SUCCESS;
    }
}
