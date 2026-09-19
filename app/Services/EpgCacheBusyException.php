<?php

namespace App\Services;

use PDOException;
use RuntimeException;

/**
 * The canonical EPG cache store could not be written because another connection
 * held SQLite's write lock past the configured busy timeout.
 *
 * Retryable: callers report a transient error to the plugin instead of blaming
 * the payload as an invalid patch.
 */
class EpgCacheBusyException extends RuntimeException
{
    /**
     * SQLite result codes that mean "locked by another writer, try again":
     * SQLITE_BUSY, SQLITE_LOCKED and their shared-cache/recovery variants.
     *
     * @var list<int>
     */
    private const BUSY_CODES = [5, 6, 262, 517];

    /** Translate a PDO failure into a busy error, or null when it is something else. */
    public static function forSqlite(PDOException $exception): ?self
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        if (! in_array($driverCode, self::BUSY_CODES, true)) {
            return null;
        }

        return new self('The EPG cache is locked by another writer.', previous: $exception);
    }
}
