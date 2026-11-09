<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a Schedules Direct request fails with an HTTP 5xx response or
 * never completes (timeout, connection error). These are transient, so the
 * caller backs off exponentially instead of retrying on the fixed schedule.
 */
class SchedulesDirectServerException extends Exception
{
    //
}
