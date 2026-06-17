<?php

namespace App\Services\Arr;

use App\Models\ArrIntegration;
use App\Services\Arr\Contracts\ArrIntegrationInterface;
use InvalidArgumentException;

class ArrService
{
    /**
     * Resolve an ArrIntegration into a concrete service instance.
     */
    public static function make(ArrIntegration $integration): ArrIntegrationInterface
    {
        return match ($integration->type) {
            'sonarr' => new SonarrService($integration),
            'radarr' => new RadarrService($integration),
            default => throw new InvalidArgumentException("Unsupported arr type: {$integration->type}"),
        };
    }
}
