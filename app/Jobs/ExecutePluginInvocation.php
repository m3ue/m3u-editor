<?php

namespace App\Jobs;

use App\Models\Plugin;
use App\Plugins\PluginManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecutePluginInvocation implements ShouldQueue
{
    use Queueable;

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
        if (array_key_exists('existing_run_id', $this->options)
            && ! in_array($this->invocationType, ['action', 'hook'], true)
        ) {
            return;
        }

        $plugin = Plugin::find($this->pluginId);
        if (! $plugin
            || ! $plugin->enabled
            || ! $plugin->isInstalled()
            || ! $plugin->available
            || $plugin->validation_status !== 'valid'
            || ! $plugin->isTrusted()
            || ! $plugin->hasVerifiedIntegrity()
        ) {
            if ($plugin && array_key_exists('existing_run_id', $this->options)) {
                $pluginManager->failPendingResumedRun(
                    $plugin,
                    (int) $this->options['existing_run_id'],
                    $this->invocationType,
                    $this->name,
                );
            }

            return;
        }

        if ($this->invocationType === 'hook') {
            $pluginManager->executeHook($plugin, $this->name, $this->payload, $this->options);

            return;
        }

        $pluginManager->executeAction($plugin, $this->name, $this->payload, $this->options);
    }
}
