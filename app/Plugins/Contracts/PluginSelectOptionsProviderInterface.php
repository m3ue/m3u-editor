<?php

namespace App\Plugins\Contracts;

use App\Plugins\Support\PluginSelectOptionsContext;

interface PluginSelectOptionsProviderInterface
{
    /**
     * @return array<string, string>
     */
    public function selectOptions(string $provider, PluginSelectOptionsContext $context): array;
}
