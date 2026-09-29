<?php

namespace App\Support\ApiDocs;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;

/**
 * Removes component schemas nothing references. Transformers that replace an
 * operation's inferred responses (see DocumentXtreamApiResponses) can leave the
 * schemas Scramble registered for the old responses behind in the Schemas list.
 * Runs until stable, since removing a schema can orphan the ones it referenced.
 */
class PruneUnreferencedSchemas implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        do {
            $serialized = json_encode($document->toArray(), JSON_UNESCAPED_SLASHES);
            $removedAny = false;

            foreach (array_keys($document->components->schemas) as $fullName) {
                $reference = '"#/components/schemas/'.$document->components->uniqueSchemaName($fullName).'"';

                if (! str_contains($serialized, $reference)) {
                    $document->components->removeSchema($fullName);
                    $removedAny = true;
                }
            }
        } while ($removedAny);
    }
}
