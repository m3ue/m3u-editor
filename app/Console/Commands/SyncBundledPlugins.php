<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use RuntimeException;

class SyncBundledPlugins extends Command
{
    protected $signature = 'plugins:sync-bundled';

    protected $description = 'Discover and auto-trust all plugins shipped in the bundled plugins directory.';

    public function handle(PluginManager $pluginManager): int
    {
        $plugins = collect($pluginManager->discover())
            ->filter(fn ($p) => $p->source_type === 'bundled');

        if ($plugins->isEmpty()) {
            $this->info('No bundled plugins found.');

            return self::SUCCESS;
        }

        $trusted = 0;
        $failed = 0;

        foreach ($plugins as $plugin) {
            if ($plugin->validation_status !== 'valid') {
                $this->warn("Skipping [{$plugin->plugin_id}]: validation failed.");
                $failed++;

                continue;
            }

            try {
                $pluginManager->trust($plugin, reason: 'Auto-trusted: shipped in bundled plugins directory.');
                $this->info("Trusted [{$plugin->plugin_id}].");
                $trusted++;
            } catch (RuntimeException $e) {
                $this->warn("Could not trust [{$plugin->plugin_id}]: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->line("Done. Trusted: {$trusted}, failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
