<?php

namespace App\Console\Commands;

use App\Models\Plugin;
use App\Plugins\PluginUpdateChecker;
use Illuminate\Console\Command;

class CheckPluginUpdates extends Command
{
    protected $signature = 'plugins:check-updates
        {--plugin= : Check a specific plugin ID only}';

    protected $description = 'Check GitHub repositories for new plugin releases.';

    public function handle(PluginUpdateChecker $checker): int
    {
        if (! config('plugins.update_check.enabled', true)) {
            $this->info('Plugin update checking is disabled.');

            return self::SUCCESS;
        }

        $pluginId = $this->option('plugin');

        if ($pluginId) {
            $plugin = Plugin::query()->where('plugin_id', $pluginId)->first();
            if (! $plugin) {
                $this->error("Plugin [{$pluginId}] not found.");

                return self::FAILURE;
            }

            if (! $plugin->repository) {
                $this->warn("Plugin [{$pluginId}] has no repository configured.");

                return self::SUCCESS;
            }

            $result = $checker->check($plugin);
            $this->displayResults([$result]);

            return self::SUCCESS;
        }

        $results = $checker->checkAll();

        if (empty($results)) {
            $this->info('No plugins with configured repositories found.');

            return self::SUCCESS;
        }

        $this->displayResults(array_values($results));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{plugin_id: string, current: ?string, latest: ?string, update_available: bool, error: ?string}>  $results
     */
    private function displayResults(array $results): void
    {
        $rows = [];
        $updatesAvailable = 0;

        foreach ($results as $result) {
            $status = match (true) {
                $result['error'] !== null => '<fg=red>Error</>',
                $result['update_available'] => '<fg=green>Update available</>',
                default => 'Up to date',
            };

            if ($result['update_available']) {
                $updatesAvailable++;
            }

            $rows[] = [
                $result['plugin_id'],
                $result['current'] ?? '-',
                $result['latest'] ?? '-',
                strip_tags($status),
                $result['error'] ?? '',
            ];
        }

        $this->table(
            ['Plugin', 'Current', 'Latest', 'Status', 'Error'],
            $rows,
        );

        if ($updatesAvailable > 0) {
            $this->info("{$updatesAvailable} plugin(s) have updates available.");
        } else {
            $this->info('All plugins are up to date.');
        }
    }
}
