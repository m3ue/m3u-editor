<?php

namespace App\Enums;

/**
 * Lifecycle status of an individual phase within a SyncRun.
 *
 * Skipped indicates the phase was evaluated but not applicable (e.g.
 * find/replace skipped because no rules are configured), distinct from
 * Pending (not yet evaluated).
 */
enum SyncPhaseStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Skipped], strict: true);
    }
}
