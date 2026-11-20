<?php

namespace App\Enums;

/**
 * Which automated feature owns a cached content file. NULL means the file
 * was cached manually (Cache Now) and automated retention never deletes it.
 */
enum CachedContentManagedBy: string
{
    case DynamicGroup = 'dynamic_group';

    case ManagedPublishing = 'managed_publishing';
}
