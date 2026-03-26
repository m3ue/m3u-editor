<?php

namespace App\Plugins\Support;

use App\Models\Plugin;
use App\Models\PluginRun;
use App\Models\User;

class PluginExecutionContext
{
    public function __construct(
        public readonly Plugin $plugin,
        public readonly PluginRun $run,
        public readonly string $trigger,
        public readonly bool $dryRun,
        public readonly ?string $hook,
        public readonly ?User $user,
        public readonly array $settings,
    ) {}

    public function log(string $message, string $level = 'info', array $context = []): void
    {
        $this->run->logs()->create([
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ]);

        $this->touchHeartbeat($message);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log($message, 'info', $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log($message, 'warning', $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log($message, 'error', $context);
    }

    public function heartbeat(?string $message = null, ?int $progress = null, array $state = []): void
    {
        $attributes = [
            'last_heartbeat_at' => now(),
        ];

        if ($message !== null) {
            $attributes['progress_message'] = $message;
        }

        if ($progress !== null) {
            $attributes['progress'] = max(0, min(100, $progress));
        }

        if ($state !== []) {
            $attributes['run_state'] = array_replace_recursive($this->run->run_state ?? [], $state);
        }

        $this->run->forceFill($attributes)->save();
        $this->run->refresh();
    }

    public function checkpoint(int $progress, string $message, array $state = [], bool $log = false, array $context = []): void
    {
        $this->heartbeat($message, $progress, $state);

        if ($log) {
            $this->info($message, $context);
        }
    }

    public function state(?string $key = null, mixed $default = null): mixed
    {
        $state = $this->run->fresh()->run_state ?? [];

        if ($key === null) {
            return $state;
        }

        return data_get($state, $key, $default);
    }

    public function cancellationRequested(): bool
    {
        return (bool) $this->run->fresh()->cancel_requested;
    }

    private function touchHeartbeat(?string $message = null): void
    {
        $attributes = [
            'last_heartbeat_at' => now(),
        ];

        if ($message !== null) {
            $attributes['progress_message'] = $message;
        }

        $this->run->forceFill($attributes)->save();
        $this->run->refresh();
    }
}
