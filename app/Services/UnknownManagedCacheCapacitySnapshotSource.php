<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;

final class UnknownManagedCacheCapacitySnapshotSource implements ManagedCacheCapacitySnapshotSource
{
    public function snapshotFor(Channel|Episode $item): ?ManagedCacheCapacitySnapshot
    {
        return null;
    }
}
