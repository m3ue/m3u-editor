<?php

namespace App\Console\Commands;

use App\Models\Plugin;
use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use RuntimeException;

class SyncBundledPlugins extends Command
{
    protected $signature = 'plugins:sync-bundled';

    protected $description = 'Discover and auto-trust all plugins shipped in the bundled plugins directory.';

    public function handle(PluginManager $pluginManager): int
    {
        // Capture enabled state before discover() resets it for version-bumped plugins.
        $previouslyEnabled = Plugin::query()
            ->where('source_type', 'bundled')
            ->where('enabled', true)
            ->pluck('plugin_id')
            ->flip()
            ->all();

        $plugins = collect($pluginManager->discover())
            ->filter(fn ($plugin) => $plugin->source_type === 'bundled');

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

                if (isset($previouslyEnabled[$plugin->plugin_id])) {
                    $plugin->update(['enabled' => true]);
                }

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
