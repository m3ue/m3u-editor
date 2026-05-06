<?php

namespace App\Enums;

/**
 * Lifecycle status of a single sync attempt tracked by the SyncRun ledger.
 */
enum SyncRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Statuses that represent an active, in-flight run.
     *
     * @return array<int, self>
     */
    public static function active(): array
    {
        return [self::Pending, self::Running];
    }

    /**
     * Statuses that represent a finished run regardless of outcome.
     *
     * @return array<int, self>
     */
    public static function terminal(): array
    {
        return [self::Completed, self::Failed, self::Cancelled];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), strict: true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), strict: true);
    }
}
