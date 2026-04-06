<?php

/**
 * Test bootstrap — ensures local test runs work even when Docker
 * artefacts (cached config, broken symlinks) are present.
 *
 * Docker itself is unaffected because tests are never executed inside the container.
 */

// 1. Remove cached config so phpunit.xml env vars take effect.
//    The cached config hardcodes the Docker DB connection (pgsql)
//    which overrides the sqlite_testing connection set in phpunit.xml.
$configCache = __DIR__.'/../bootstrap/cache/config.php';
if (file_exists($configCache)) {
    unlink($configCache);
}

// 1b. Remove cached routes so newly added routes are visible.
foreach (glob(__DIR__.'/../bootstrap/cache/routes-v*.php') as $routeCache) {
    unlink($routeCache);
}

// 2. Fix broken database symlinks that point to Docker container paths.
//    In Docker, these are valid symlinks to /var/www/config/database/*.sqlite.
//    Locally, the targets don't exist and SQLite will fail.
foreach (['jobs.sqlite', 'database.sqlite'] as $dbFile) {
    $path = __DIR__.'/../database/'.$dbFile;
    if (is_link($path) && ! file_exists($path)) {
        unlink($path);
        touch($path);
    }
}

require __DIR__.'/../vendor/autoload.php';
