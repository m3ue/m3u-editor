<?php

namespace App\Console\Commands;

use App\Models\Plugin;
use Illuminate\Console\Command;

class SyncOfficialPlugins extends Command
{
    protected $signature = 'plugins:sync-official';

    protected $description = 'Seed stub Plugin records for official m3ue-org plugins so they appear in the UI as installable.';

    public function handle(): int
    {
        $officialPlugins = config('plugins.official_plugins', []);

        if ($officialPlugins === []) {
            $this->info('No official plugins configured.');

            return self::SUCCESS;
        }

        $seeded = 0;

        foreach ($officialPlugins as $pluginId => $meta) {
            $repository = (string) ($meta['repository'] ?? '');
            $name = (string) ($meta['name'] ?? $pluginId);
            $description = isset($meta['description']) ? (string) $meta['description'] : null;

            [$org] = explode('/', $repository, 2);

            if (! in_array($org, config('plugins.trusted_orgs', []), true)) {
                $this->warn("Skipping [{$pluginId}]: repository [{$repository}] is not from a trusted org.");

                continue;
            }

            $existing = Plugin::query()->where('plugin_id', $pluginId)->first();

            // Skip only when the plugin's files are actually present on disk —
            // the `available` flag may be stale (e.g. after migrating away from bundled).
            if ($existing && $existing->path && is_dir((string) $existing->path)) {
                $this->line("Skipping [{$pluginId}]: already installed at [{$existing->path}].");

                continue;
            }

            if ($existing && $existing->source_type === 'bundled') {
                $this->warn("Migrating [{$pluginId}] from bundled → official (files no longer on disk).");
            }

            Plugin::query()->updateOrCreate(
                ['plugin_id' => $pluginId],
                [
                    'name' => $name,
                    'description' => $description,
                    'source_type' => 'official',
                    'repository' => $repository,
                    'path' => null,
                    'available' => false,
                    'enabled' => false,
                    'installation_status' => 'uninstalled',
                    'trust_state' => 'trusted',
                    'trust_reason' => "Auto-trusted: official plugin maintained by the {$org} organisation.",
                    'validation_status' => 'pending',
                    'integrity_status' => 'unknown',
                    'last_discovered_at' => now(),
                ],
            );

            $this->info("Seeded stub for [{$pluginId}] ({$repository}).");
            $seeded++;
        }

        $this->line("🔌 Done. Seeded: {$seeded}.");

        return self::SUCCESS;
    }
}
