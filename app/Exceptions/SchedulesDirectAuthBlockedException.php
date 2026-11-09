<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when Schedules Direct rejects a /token request with an error that
 * retrying cannot fix (ACCOUNT_EXPIRED 4001, INVALID_HASH 4002, INVALID_USER
 * 4003, JSON_ACCOUNT_ACCESS_DISABLED 4005, APPLICATION_DISABLED 4007,
 * ACCOUNT_INACTIVE 4008) or with any other Schedules Direct error code that is
 * not a temporary limit, or when such a rejection is already recorded on the
 * EPG.
 *
 * No further /token request is made for the blocked EPGs until the user
 * changes the credentials or explicitly retries the login.
 */
class SchedulesDirectAuthBlockedException extends Exception
{
    //
}
