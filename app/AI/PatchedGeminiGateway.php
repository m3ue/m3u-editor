<?php

namespace App\AI;

use App\AI\Concerns\DeduplicatesTools;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Arr;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Gemini\GeminiGateway;
use Laravel\Ai\ObjectSchema;

/**
 * Fixes two bugs in the parent Gemini gateway:
 *
 * 1. mapTool(): The Gemini API requires the `required` field to be omitted entirely
 *    when there are no required parameters. Sending `"required": []` causes a
 *    400 INVALID_ARGUMENT error (affects RecallTool, EpgMappingStateTool, etc.).
 *
 * 2. mapTools(): Gemini rejects duplicate function names with a 400 error.
 *    Deduplicated via the DeduplicatesTools trait.
 */
class PatchedGeminiGateway extends GeminiGateway
{
    use DeduplicatesTools;

    protected function mapTool(Tool $tool): array
    {
        $schema = $tool->schema(new JsonSchemaTypeFactory);

        $definition = [
            'name' => class_basename($tool),
            'description' => (string) $tool->description(),
        ];

        if (filled($schema)) {
            $schemaArray = (new ObjectSchema($schema))->toSchema();

            $parameters = Arr::except([
                'type' => 'object',
                'properties' => $schemaArray['properties'] ?? [],
            ], ['additionalProperties']);

            // Gemini rejects "required": [] — omit the key entirely when empty.
            $required = $schemaArray['required'] ?? [];
            if (! empty($required)) {
                $parameters['required'] = $required;
            }

            $definition['parameters'] = $parameters;
        }

        return $definition;
    }
}
