<?php

namespace App\Plugins;

use App\Jobs\ExecutePluginInvocation;
use Illuminate\Support\Collection;

class PluginHookDispatcher
{
    public function __construct(
        private readonly PluginManager $pluginManager,
    ) {}

    public function dispatch(string $hook, array $payload = [], array $options = []): Collection
    {
        if (! config('plugins.enabled', true)) {
            return collect();
        }

        $plugins = $this->pluginManager->enabledPluginsForHook($hook);

        $plugins->each(function ($plugin) use ($hook, $payload, $options) {
            dispatch(new ExecutePluginInvocation(
                pluginId: $plugin->id,
                invocationType: 'hook',
                name: $hook,
                payload: $payload,
                options: [
                    'trigger' => $options['trigger'] ?? 'hook',
                    'dry_run' => (bool) ($options['dry_run'] ?? true),
                    'user_id' => $options['user_id'] ?? null,
                ],
            ));
        });

        return $plugins;
    }
}
