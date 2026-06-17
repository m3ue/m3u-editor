<?php

namespace App\Services\Arr;

use App\Models\ArrIntegration;
use App\Services\Arr\Contracts\ArrIntegrationInterface;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Base class for Sonarr/Radarr services — handles the shared X-Api-Key client
 * and common request/response plumbing. Subclasses implement the per-platform
 * endpoints and payload shapes.
 */
abstract class BaseArrService implements ArrIntegrationInterface
{
    public function __construct(protected ArrIntegration $integration) {}

    public function getIntegration(): ArrIntegration
    {
        return $this->integration;
    }

    /**
     * Configured HTTP client (X-Api-Key auth, /api/v3 base path).
     */
    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->integration->base_url.'/api/v3')
            ->timeout(30)
            ->retry(2, 1000)
            ->acceptJson()
            ->withHeaders([
                'X-Api-Key' => $this->integration->api_key,
            ]);
    }

    /**
     * Wrap a request in a try/catch and return a uniform {ok, data, error} shape.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return array{ok: bool, data?: T, error?: string}
     */
    protected function safeCall(callable $callback, string $op): array
    {
        try {
            $data = $callback();

            return ['ok' => true, 'data' => $data];
        } catch (Exception $e) {
            Log::warning("ArrService: {$op} failed", [
                'integration_id' => $this->integration->id,
                'type' => $this->integration->type,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
