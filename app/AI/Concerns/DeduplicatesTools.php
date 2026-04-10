<?php

namespace App\AI\Concerns;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\Provider;

/**
 * Deduplicates tool lists by function name before they are mapped to provider
 * schemas. Providers like Gemini return a 400 error when the same function name
 * appears more than once — which can happen when ToolRegistry's built-in tools
 * are also present in the user-configured copilot_global_tools setting.
 */
trait DeduplicatesTools
{
    protected function mapTools(array $tools, Provider $provider): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($tools as $tool) {
            $name = $tool instanceof Tool ? class_basename($tool) : null;

            if ($name !== null) {
                if (isset($seen[$name])) {
                    continue;
                }

                $seen[$name] = true;
            }

            $deduplicated[] = $tool;
        }

        return parent::mapTools($deduplicated, $provider);
    }
}
