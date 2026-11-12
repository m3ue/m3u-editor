<?php

namespace App\Plugins\Contracts;

use App\Plugins\Support\PluginApiRequestContext;

/**
 * Required by the [api_endpoints] capability.
 *
 * The host only calls this for an endpoint id declared in the manifest's
 * [api_endpoints] list, after authenticating the caller's Xtream login and
 * validating the payload against that endpoint's declared fields.
 */
interface ApiEndpointPluginInterface
{
    /**
     * @param  array<string, mixed>  $payload  Only the endpoint's declared fields, already validated by the host.
     * @return array<mixed> JSON-encodable response data.
     */
    public function handleApiRequest(string $endpoint, array $payload, PluginApiRequestContext $context): array;
}
