<?php

/**
 * Opt in only with the port of a disposable Redis on loopback (e.g. an owned
 * --network none container shared with the test container). Never infer it
 * from application Redis settings. No URL, credentials or DB are inherited.
 */
function callbackRedisEnvironment(string|false $port, string $prefix): array
{
    if ($port === false || ! preg_match('/^[1-9][0-9]{0,4}$/D', $port) || (int) $port > 65535) {
        throw new RuntimeException('Set CALLBACK_TEST_REDIS_PORT explicitly to a disposable loopback Redis port; application Redis settings are never used.');
    }
    if (! preg_match('/^callback-cache-test-[a-f0-9-]+:$/D', $prefix)) {
        throw new RuntimeException('Invalid disposable callback Redis prefix.');
    }

    return [
        'CALLBACK_TEST_REDIS_PORT' => $port,
        'REDIS_CLIENT' => 'predis',
        // Explicit null strings also prevent .env from restoring these values.
        'REDIS_URL' => '(null)',
        'REDIS_HOST' => '127.0.0.1',
        'REDIS_SERVER_PORT' => $port,
        'REDIS_PORT' => $port,
        'REDIS_USERNAME' => '(null)',
        'REDIS_PASSWORD' => '(null)',
        'REDIS_DB' => '0',
        'REDIS_CACHE_DB' => '1',
        'REDIS_IMPORT_DB' => '3',
        'REDIS_FILE_SYNC_DB' => '4',
        'REDIS_SESSION_DB' => '5',
        'REDIS_CLUSTER' => 'redis',
        'REDIS_PREFIX' => $prefix,
    ];
}

function assertCallbackRedisConfiguration(array $redis, array $environment): void
{
    $expected = [
        'default' => '0', 'cache' => '1', 'import' => '3',
        'file_sync' => '4', 'session' => '5',
    ];
    if (($redis['client'] ?? null) !== 'predis' || ($redis['options'] ?? []) !== [
        'cluster' => 'redis', 'prefix' => $environment['REDIS_PREFIX'],
    ]) {
        throw new RuntimeException('Refusing non-disposable cached Redis options.');
    }
    foreach ($expected as $name => $database) {
        if (($redis[$name] ?? []) !== [
            'url' => null, 'host' => '127.0.0.1', 'username' => null,
            'password' => null, 'port' => $environment['REDIS_SERVER_PORT'],
            'database' => $database,
        ]) {
            throw new RuntimeException('Refusing non-disposable cached Redis connection.');
        }
    }
}
