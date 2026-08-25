<?php

namespace App\Jobs;

use App\Models\Plugin;
use App\Plugins\PluginManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecutePluginInvocation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60 * 60 * 6;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $pluginId,
        public string $invocationType,
        public string $name,
        public array $payload = [],
        public array $options = [],
    ) {
        $this->onConnection('plugin');
        $this->onQueue('plugin-invocations');
    }

    public function handle(PluginManager $pluginManager): void
    {
        $plugin = Plugin::find($this->pluginId);
        if (! $plugin
            || ! $plugin->enabled
            || ! $plugin->isInstalled()
            || ! $plugin->available
            || $plugin->validation_status !== 'valid'
            || ! $plugin->isTrusted()
            || ! $plugin->hasVerifiedIntegrity()
        ) {
            return;
        }

        if ($this->invocationType === 'hook') {
            $pluginManager->executeHook($plugin, $this->name, $this->payload, $this->options);

            return;
        }

        $pluginManager->executeAction($plugin, $this->name, $this->payload, $this->options);
    }
}
