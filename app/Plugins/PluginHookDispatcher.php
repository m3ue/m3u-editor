<?php

namespace App\Plugins;

use App\Jobs\ExecutePluginInvocation;
use App\Models\Plugin;
use App\Sync\Phases\PluginDispatchPhase;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

class PluginHookDispatcher
{
    public function __construct(
        private readonly PluginManager $pluginManager,
    ) {}

    /**
     * Dispatch one {@see ExecutePluginInvocation} job per enabled plugin
     * subscribed to the given hook. Returns the plugins that were dispatched
     * for so callers can react (e.g. for logging).
     */
    public function dispatch(string $hook, array $payload = [], array $options = []): Collection
    {
        $plugins = $this->pluginManager->enabledPluginsForHook($hook);

        foreach ($this->buildJobs($hook, $payload, $options, $plugins) as $job) {
            dispatch($job);
        }

        return $plugins;
    }

    /**
     * Build (but do not dispatch) the per-plugin invocation jobs for a hook.
     * Used by batchable callers (e.g. {@see PluginDispatchPhase})
     * that need to hand the jobs to {@see Bus::batch()}
     * rather than firing them individually.
     *
     * @param  Collection<int, Plugin>|null  $plugins  optional pre-resolved plugin collection
     * @return array<int, ShouldQueue>
     */
    public function buildJobs(string $hook, array $payload = [], array $options = [], ?Collection $plugins = null): array
    {
        $plugins ??= $this->pluginManager->enabledPluginsForHook($hook);

        return $plugins
            ->map(fn ($plugin) => new ExecutePluginInvocation(
                pluginId: $plugin->id,
                invocationType: 'hook',
                name: $hook,
                payload: $payload,
                options: [
                    'trigger' => $options['trigger'] ?? 'hook',
                    'dry_run' => (bool) ($options['dry_run'] ?? true),
                    'user_id' => $options['user_id'] ?? null,
                ],
            ))
            ->all();
    }
}
