<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when Schedules Direct responds with TOKEN_EXPIRED (code 4006).
 *
 * The stored token has already been cleared by the time this is thrown, so a
 * caller may perform exactly one controlled re-authentication and retry.
 */
class SchedulesDirectTokenExpiredException extends Exception
{
    //
}
