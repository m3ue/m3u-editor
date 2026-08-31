<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use Exception;
use Throwable;

/**
 * Thrown when Schedules Direct rejects a /token request with TOO_MANY_LOGINS
 * (code 4009), or when a local cooldown from a previous 4009 is still active.
 *
 * Carries the time at which another authentication attempt is allowed so
 * callers can surface a single actionable message without exposing credentials.
 */
class SchedulesDirectRateLimitException extends Exception
{
    public function __construct(
        public readonly CarbonInterface $retryAt,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : "SchedulesDirect login limit reached. Next attempt allowed at {$retryAt->toIso8601String()}.",
            0,
            $previous,
        );
    }
}
