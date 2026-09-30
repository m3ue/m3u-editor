<?php

namespace App\AI;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Gemini\GeminiGateway;

/**
 * The Gemini API requires the `required` field to be omitted entirely when a
 * tool has no required parameters. The parent gateway always sends
 * `"required": []`, which Gemini rejects with a 400 INVALID_ARGUMENT error
 * (affects RecallTool, EpgMappingStateTool, etc.).
 */
class PatchedGeminiGateway extends GeminiGateway
{
    protected function mapTool(Tool $tool): array
    {
        $definition = parent::mapTool($tool);

        if (isset($definition['parameters']) && empty($definition['parameters']['required'])) {
            unset($definition['parameters']['required']);
        }

        return $definition;
    }
}
