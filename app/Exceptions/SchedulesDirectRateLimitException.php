<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use Exception;
use Throwable;

/**
 * Thrown when Schedules Direct rejects a /token request with ACCOUNT_LOCKOUT
 * (code 4004), TOO_MANY_LOGINS (code 4009) or TOO_MANY_UNIQUE_IPS (code 4010), when a request fails with a
 * server error and the account enters its exponential backoff, or when any of
 * those cooldowns is still active.
 *
 * Carries the time at which another attempt is allowed so callers can surface
 * a single actionable message without exposing credentials.
 */
class SchedulesDirectRateLimitException extends Exception
{
    public const REASON_TOO_MANY_LOGINS = 'too_many_logins';

    public const REASON_TOO_MANY_UNIQUE_IPS = 'too_many_unique_ips';

    /** Too many failed logins (4004); the provider locks the account for 15 minutes. */
    public const REASON_ACCOUNT_LOCKOUT = 'account_lockout';

    public const REASON_SERVER_ERROR = 'server_error';

    /** /token failed with an unrecognised error; the message carries the provider's text. */
    public const REASON_LOGIN_FAILED = 'login_failed';

    /** A stored backoff from an earlier failure is still active. */
    public const REASON_BACKOFF = 'backoff';

    public function __construct(
        public readonly CarbonInterface $retryAt,
        string $message = '',
        ?Throwable $previous = null,
        public readonly string $reason = self::REASON_TOO_MANY_LOGINS,
    ) {
        parent::__construct(
            $message !== '' ? $message : "SchedulesDirect login limit reached. Next attempt allowed at {$retryAt->toIso8601String()}.",
            0,
            $previous,
        );
    }

    /**
     * Short, user-facing explanation of why syncing is paused and until when.
     */
    public function userMessage(): string
    {
        $until = $this->retryAt->copy()->utc()->toDayDateTimeString().' UTC';

        return match ($this->reason) {
            self::REASON_ACCOUNT_LOCKOUT => "SchedulesDirect temporarily locked this account after too many failed logins. The next automatic retry is after {$until}.",
            self::REASON_TOO_MANY_UNIQUE_IPS => "SchedulesDirect rejected the login because too many different IP addresses used this account today. Automatic retries are paused until {$until}.",
            self::REASON_SERVER_ERROR => "SchedulesDirect is not responding (server error or timeout). The next automatic retry is after {$until}.",
            self::REASON_LOGIN_FAILED => "{$this->getMessage()} The next automatic retry is after {$until}.",
            self::REASON_BACKOFF => "SchedulesDirect requests are paused after a failed attempt. The next automatic retry is after {$until}.",
            default => "SchedulesDirect login limit reached. Automatic retries are paused until {$until}.",
        };
    }

    /**
     * Notification title for the paused sync.
     */
    public function userTitle(string $epgName): string
    {
        return match ($this->reason) {
            self::REASON_ACCOUNT_LOCKOUT => "SchedulesDirect account locked for \"{$epgName}\"",
            self::REASON_TOO_MANY_UNIQUE_IPS => "SchedulesDirect IP limit reached for \"{$epgName}\"",
            self::REASON_SERVER_ERROR => "SchedulesDirect unavailable for \"{$epgName}\"",
            self::REASON_LOGIN_FAILED => "SchedulesDirect login failed for \"{$epgName}\"",
            self::REASON_BACKOFF => "SchedulesDirect sync paused for \"{$epgName}\"",
            default => "SchedulesDirect login limit reached for \"{$epgName}\"",
        };
    }
}
