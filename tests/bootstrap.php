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

// 2. Fix broken storage/logs symlink that points to Docker container path.
//    In Docker, this is a valid symlink to /var/www/config/logs.
//    Locally, the target doesn't exist and Monolog will fail.
$logsPath = __DIR__.'/../storage/logs';
if (is_link($logsPath) && ! file_exists($logsPath)) {
    unlink($logsPath);
    mkdir($logsPath, 0755, true);
}

// 2b. Fix broken .env symlink that points to Docker container path.
//     Without a valid .env, Laravel can't load env vars and phpunit.xml
//     env overrides may not work. The .env.testing file is used instead.
$envPath = __DIR__.'/../.env';
if (is_link($envPath) && ! file_exists($envPath)) {
    unlink($envPath);
    copy(__DIR__.'/../.env.testing', $envPath);
}

// 3. Fix broken database symlinks that point to Docker container paths.
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
