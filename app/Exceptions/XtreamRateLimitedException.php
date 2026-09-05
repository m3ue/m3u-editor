<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use Exception;
use Throwable;

/**
 * Thrown when an Xtream provider rejects a request with HTTP 429, or when a
 * local cooldown from a previous 429 on the same account is still active.
 *
 * The limit is per account (username/password), not per host: fallback URLs
 * share the same credentials as the primary, so a 429 fences all of them.
 */
class XtreamRateLimitedException extends Exception
{
    public function __construct(
        public readonly CarbonInterface $retryAt,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : "Xtream account rate limited. Next attempt allowed at {$retryAt->toIso8601String()}.",
            0,
            $previous,
        );
    }
}
