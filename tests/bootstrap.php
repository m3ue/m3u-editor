<?php

/**
 * Test bootstrap, ensures local and Docker test runs work even when Docker
 * artefacts (cached config, broken symlinks, missing env files) are present.
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

// 2b. Fix broken or missing .env files that point to Docker container paths.
//     Without a valid .env, Laravel emits file_get_contents warnings before
//     phpunit.xml env overrides are applied.
$envPath = __DIR__.'/../.env';
$envTestingPath = __DIR__.'/../.env.testing';
$envExamplePath = __DIR__.'/../.env.example';

if (is_link($envPath) && ! file_exists($envPath)) {
    unlink($envPath);
}

if (! file_exists($envPath)) {
    if (file_exists($envTestingPath)) {
        copy($envTestingPath, $envPath);
    } elseif (file_exists($envExamplePath)) {
        copy($envExamplePath, $envPath);
    } else {
        file_put_contents($envPath, "APP_ENV=testing\nAPP_KEY=base64:uGN0Jq8vBgmV7NMZ2S0Y8XNg3OMRSbe4H1sA0rDkiN0=\n");
    }
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
