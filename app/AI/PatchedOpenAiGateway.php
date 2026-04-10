<?php

namespace App\AI;

use App\AI\Concerns\DeduplicatesTools;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\ObjectSchema;

/**
 * Fixes two bugs in the parent OpenAI gateway:
 *
 * 1. mapTool(): strict=true is hardcoded, but optional properties (those not in
 *    `required`) are rejected by OpenAI in strict mode. Dropping strict removes
 *    this constraint.
 *
 * 2. mapTool(): When strict=true and no schema, `parameters` was omitted entirely
 *    — OpenAI requires it to be present for all function tools.
 *
 * 3. mapTools(): Duplicate function names cause provider errors.
 *    Deduplicated via the DeduplicatesTools trait.
 */
class PatchedOpenAiGateway extends OpenAiGateway
{
    use DeduplicatesTools;

    protected function mapTool(Tool $tool): array
    {
        $schema = $tool->schema(new JsonSchemaTypeFactory);

        $definition = [
            'type' => 'function',
            'name' => class_basename($tool),
            'description' => (string) $tool->description(),
        ];

        if (filled($schema)) {
            $objectSchema = new ObjectSchema($schema);
            $schemaArray = $objectSchema->toSchema();

            $definition['parameters'] = [
                'type' => 'object',
                'properties' => $schemaArray['properties'] ?? [],
                'required' => $schemaArray['required'] ?? [],
            ];
        } else {
            $definition['parameters'] = [
                'type' => 'object',
                'properties' => (object) [],
                'required' => [],
            ];
        }

        return $definition;
    }
}
