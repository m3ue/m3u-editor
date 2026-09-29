<?php

namespace App\Support\ApiDocs;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Collection;

/**
 * Merges responses that share a status code into one response whose body is any of them.
 *
 * Scramble appends shared responses (e.g. the `ValidationException` 422 from
 * `$request->validate()`) after the inferred ones, and the document is keyed by status
 * code, so a hand-written `response()->json([...], 422)` in the same action would
 * otherwise be silently overwritten.
 */
class MergeSameStatusResponses implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $operation->responses = collect($operation->responses)
            ->groupBy(fn (Response|Reference $response) => (string) $this->resolve($response)->code)
            ->map(fn (Collection $responses) => $responses->count() === 1 ? $responses->first() : $this->merge($responses))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Response|Reference>  $responses
     */
    private function merge(Collection $responses): Response
    {
        $resolved = $responses->map(fn (Response|Reference $response) => $this->resolve($response));

        $bodies = $resolved
            ->map(fn (Response $response) => $response->content['application/json'] ?? null)
            ->filter()
            ->flatMap(fn (Schema|Reference $body) => $this->anyOfItems($body instanceof Schema ? $body->type : $body))
            ->values()
            ->all();

        $merged = Response::make($resolved->first()->code)
            ->setDescription($resolved->pluck('description')->filter()->unique()->join("\n\n"));

        return $bodies === [] ? $merged : $merged->setContent('application/json', Schema::fromType((new AnyOf)->setItems($bodies)));
    }

    /**
     * @return array<int, Type>
     */
    private function anyOfItems(Type $type): array
    {
        return $type instanceof AnyOf ? $type->items : [$type];
    }

    private function resolve(Response|Reference $response): Response
    {
        return $response instanceof Reference ? $response->resolve() : $response;
    }
}
