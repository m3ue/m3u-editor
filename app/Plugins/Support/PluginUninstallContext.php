<?php

namespace App\Plugins\Support;

use App\Models\Plugin;
use App\Models\User;

class PluginUninstallContext
{
    public function __construct(
        public readonly Plugin $plugin,
        public readonly string $cleanupMode,
        public readonly array $dataOwnership,
        public readonly ?User $user = null,
    ) {}

    public function shouldPurge(): bool
    {
        return $this->cleanupMode === 'purge';
    }
}
