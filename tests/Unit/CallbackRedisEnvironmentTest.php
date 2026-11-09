<?php

use Symfony\Component\Process\Process;

require_once __DIR__.'/../Fixtures/callback-redis-environment.php';

it('rejects missing or invalid disposable Redis opt in without consulting application settings', function ($port) {
    expect(fn () => callbackRedisEnvironment($port, 'callback-cache-test-abcd:'))
        ->toThrow(RuntimeException::class, 'Set CALLBACK_TEST_REDIS_PORT explicitly');
})->with([false, '', '0', '65536', 'redis://production.invalid:6379', '6379?prefix=production']);

it('sanitizes a malicious inherited Redis URL and every conflicting connection field without connecting', function () {
    $environment = callbackRedisEnvironment('16379', 'callback-cache-test-abcd:');
    // Synthetic production markers only. This child never boots Laravel or
    // executes a Redis command; Predis parameter/option resolution is lazy.
    $inherited = array_fill_keys(array_keys($environment), 'production-marker');
    $inherited['REDIS_URL'] = 'tcp://user:secret@production.invalid:6379/9?prefix=production-bucket:&database=9';
    $code = <<<'PHP'
require 'vendor/autoload.php';
require 'tests/Fixtures/callback-redis-environment.php';
$environment = callbackRedisEnvironment('16379', 'callback-cache-test-abcd:');
foreach ($environment as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
new Illuminate\Foundation\Application(getcwd());
$redis = (require 'config/database.php')['redis'];
assertCallbackRedisConfiguration($redis, $environment);
$manager = new Illuminate\Redis\RedisManager(new Illuminate\Container\Container, 'predis', $redis);
$client = $manager->connection()->client();
echo json_encode([
    'parameters' => $client->getConnection()->getParameters()->toArray(),
    'prefix' => (string) $client->getOptions()->prefix,
    'connected' => $client->isConnected(),
], JSON_THROW_ON_ERROR);
PHP;
    $process = new Process([PHP_BINARY, '-r', $code], base_path(), $inherited);
    $process->mustRun();
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['parameters']['host'])->toBe('127.0.0.1')
        ->and((int) $result['parameters']['port'])->toBe(16379)
        ->and((int) $result['parameters']['database'])->toBe(0)
        ->and($result['parameters']['username'] ?? null)->toBeNull()
        ->and($result['parameters']['password'] ?? null)->toBeNull()
        ->and($result['prefix'])->toBe('callback-cache-test-abcd:')
        ->and($result['connected'])->toBeFalse();
});

it('refuses cached Redis URLs and prefixes before callback requests', function () {
    $environment = callbackRedisEnvironment('16379', 'callback-cache-test-abcd:');
    $redis = [
        'client' => 'predis',
        'options' => ['cluster' => 'redis', 'prefix' => 'production-bucket:'],
        'default' => ['url' => 'tcp://production.invalid:6379?prefix=production-bucket:'],
    ];
    expect(fn () => assertCallbackRedisConfiguration($redis, $environment))->toThrow(RuntimeException::class);
    $redis['options']['prefix'] = $environment['REDIS_PREFIX'];
    expect(fn () => assertCallbackRedisConfiguration($redis, $environment))->toThrow(RuntimeException::class);
});

it('fails a directly invoked fresh process probe closed without explicit disposable Redis', function () {
    // Force errors onto stderr regardless of the host php.ini's own display_errors
    // value (e.g. local CLI defaults often use "stdout", not "stderr").
    $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', 'tests/Fixtures/probe-callback-cache.php'], base_path(), [
        'CALLBACK_TEST_REDIS_PORT' => false,
        'REDIS_URL' => 'tcp://production.invalid:6379?prefix=production-bucket:',
    ]);
    $process->run();
    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('Set CALLBACK_TEST_REDIS_PORT explicitly');
});
