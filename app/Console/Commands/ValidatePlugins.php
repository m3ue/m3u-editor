<?php

namespace App\Console\Commands;

use App\Models\Plugin;
use App\Plugins\PluginManager;
use Illuminate\Console\Command;

class ValidatePlugins extends Command
{
    protected $signature = 'plugins:validate {pluginId?}';

    protected $description = 'Validate one plugin or all discovered plugins';

    public function handle(PluginManager $pluginManager): int
    {
        $pluginId = $this->argument('pluginId');
        $pluginManager->discover();
        $plugins = Plugin::query()
            ->when($pluginId, fn ($query) => $query->where('plugin_id', $pluginId))
            ->get();

        if ($plugins->isEmpty()) {
            if ($pluginId) {
                $this->error('No matching plugins found.');

                return self::FAILURE;
            }

            $this->info('No plugins to validate.');

            return self::SUCCESS;
        }

        foreach ($plugins as $plugin) {
            $plugin = $pluginManager->validate($plugin);
            $this->line("{$plugin->plugin_id}: {$plugin->validation_status}");
            foreach ($plugin->validation_errors ?? [] as $error) {
                $this->warn("  - {$error}");
            }
        }

        return self::SUCCESS;
    }
}
