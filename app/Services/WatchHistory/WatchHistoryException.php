<?php

namespace App\Services\WatchHistory;

use RuntimeException;

/**
 * A Trakt/SIMKL call that failed. `retryAfter` is set when the request can
 * simply be sent again later (a rate limit or SIMKL's write lock), and
 * `signedOut` when the account rejected its tokens and needs reconnecting.
 */
class WatchHistoryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfter = null,
        public readonly bool $signedOut = false,
    ) {
        parent::__construct($message);
    }
}
