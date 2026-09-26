<?php

namespace App\Enums;

/**
 * Outcome of asking CachedContentDispatchService to cache one item.
 */
enum CacheDispatchResult: string
{
    case Queued = 'queued';
    case AlreadyCached = 'already_cached';
    case AlreadyQueued = 'already_queued';
    case Unavailable = 'unavailable';
    case Disabled = 'disabled';
}
