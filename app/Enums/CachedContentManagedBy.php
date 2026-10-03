<?php

namespace App\Enums;

/**
 * Which automated feature owns a cached content file.
 *
 * NULL (no case) means the file is manual (Cache Now) or pinned
 * (`never_expire` retention), and automated retention never deletes it.
 * Retention only ever releases files whose value matches its own feature:
 * dynamic-group retention releases `DynamicGroup` files, and the future
 * arr-stack feature adds its own case.
 */
enum CachedContentManagedBy: string
{
    case DynamicGroup = 'dynamic_group';
}
