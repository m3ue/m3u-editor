<?php

namespace App\Support\ApiDocs;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Str;

/**
 * Documents the 401 and 403 responses DispatcharrDvrAuthMiddleware returns for the DVR routes.
 * It uses Dispatcharr's `{"detail": "..."}` shape instead of Laravel's
 * `{"message": "..."}`, and Scramble can't see responses returned from middleware.
 */
class DocumentDvrAuthenticationErrors implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $usesDvrAuth = collect($routeInfo->route->gatherMiddleware())
            ->contains(fn (mixed $middleware) => is_string($middleware) && Str::startsWith($middleware, 'dispatcharr.dvr'));

        if (! $usesDvrAuth) {
            return;
        }

        $operation->addResponse($this->detailResponse(401, 'Missing, invalid or expired API token.', 'Invalid token.'));
        $operation->addResponse($this->detailResponse(403, 'The API token lacks the ability this endpoint requires, or its owner has no DVR access.', 'You do not have permission to perform this action.'));
    }

    private function detailResponse(int $status, string $description, string $example): Response
    {
        $body = (new ObjectType)
            ->addProperty('detail', (new StringType)->setDescription('Why the request was rejected.')->example($example))
            ->setRequired(['detail']);

        return Response::make($status)
            ->setDescription($description)
            ->setContent('application/json', Schema::fromType($body));
    }
}
