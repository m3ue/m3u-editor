<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;

interface ManagedCacheCapacitySnapshotSource
{
    /**
     * Returns null when no authoritative account-wide capacity evidence exists.
     */
    public function snapshotFor(Channel|Episode $item): ?ManagedCacheCapacitySnapshot;
}
