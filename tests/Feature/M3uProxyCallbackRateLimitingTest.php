<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'proxy.m3u_proxy_token' => 'callback-test-token',
        'proxy.allow_unauthenticated_callbacks' => false,
        'proxy.isolated_callback_rate_limits' => true,
        'proxy.callback_rate_limits' => array_fill_keys(['resolver', 'webhook', 'broadcast', 'dvr'], 2),
    ]);
    // Real Redis throttling, but a unique documentation-range IP per test:
    // no flushes or modifications to existing Redis data/connections.
    $suffix = implode(':', str_split(substr(str_replace('-', '', (string) Str::uuid()), 0, 16), 4));
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::'.$suffix]);
    $this->withHeader('X-API-Token', 'callback-test-token');
});

it('isolates an authenticated webhook burst from every other callback on the same IP and token', function () {
    $this->postJson('/api/m3u-proxy/webhooks')->assertOk()->assertHeader('X-RateLimit-Limit', '2');
    $this->postJson('/api/m3u-proxy/webhooks')->assertOk();
    $this->postJson('/api/m3u-proxy/webhooks')->assertStatus(429)->assertHeader('Retry-After');

    // Empty payloads reach real controllers without touching streams/providers.
    foreach ([
        '/api/m3u-proxy/failover-resolver' => 400,
        '/api/m3u-proxy/broadcast/callback' => 400,
        '/api/dvr/callback' => 422,
    ] as $path => $status) {
        $this->postJson($path)->assertStatus($status)->assertHeader('X-RateLimit-Remaining', '1');
        $this->postJson($path)->assertStatus($status)->assertHeader('X-RateLimit-Remaining', '0');
        $this->postJson($path)->assertStatus(429)->assertHeader('Retry-After');
    }
});

it('retains the legacy shared 120 per minute bucket by default', function () {
    $defaults = require base_path('config/proxy.php');
    expect($defaults['isolated_callback_rate_limits'])->toBeFalse();
    config(['proxy.isolated_callback_rate_limits' => false]);

    for ($i = 0; $i < 120; $i++) {
        $this->postJson('/api/m3u-proxy/webhooks')->assertOk()->assertHeader('X-RateLimit-Limit', '120');
    }

    app('router')->post('/api/callback-legacy-probe', fn () => response()->json(['ok' => true]))
        ->middleware('throttle:120,1');
    $this->postJson('/api/m3u-proxy/webhooks')->assertStatus(429);
    $this->postJson('/api/callback-legacy-probe')->assertStatus(429);
    $this->postJson('/api/m3u-proxy/failover-resolver')->assertStatus(429);
    $this->postJson('/api/m3u-proxy/broadcast/callback')->assertStatus(429);
    $this->postJson('/api/dvr/callback')->assertStatus(429);
});

it('still rejects missing wrong and unconfigured tokens on every isolated route', function (string $path) {
    // Keep ample allowance: authentication, not throttling, must reject these.
    config(['proxy.callback_rate_limits' => array_fill_keys(['resolver', 'webhook', 'broadcast', 'dvr'], 120)]);
    $this->flushHeaders();
    $this->postJson($path)->assertUnauthorized();
    $this->postJson($path, [], ['X-API-Token' => 'wrong'])->assertUnauthorized();
    config(['proxy.m3u_proxy_token' => null]);
    $this->postJson($path, [], ['X-API-Token' => 'callback-test-token'])->assertUnauthorized();
})->with([
    '/api/m3u-proxy/failover-resolver',
    '/api/m3u-proxy/webhooks',
    '/api/m3u-proxy/broadcast/callback',
    '/api/dvr/callback',
]);

it('accepts the existing query token without using it in limiter keys', function () {
    $this->flushHeaders();
    $this->postJson('/api/m3u-proxy/webhooks?api_token=callback-test-token')->assertOk();
    $request = Request::create('/api/m3u-proxy/webhooks?api_token=callback-test-token');
    $request->setRouteResolver(fn () => app('router')->getRoutes()->getByName('m3u-proxy.webhook'));

    foreach (['resolver', 'webhook', 'broadcast', 'dvr'] as $callback) {
        $limit = RateLimiter::limiter('m3u-proxy-callback-'.$callback)($request);
        expect($limit->key)->not->toContain('callback-test-token', 'api_token');
    }
});

it('falls back to a bounded 120 per minute for invalid limits', function ($invalid) {
    config(['proxy.callback_rate_limits.webhook' => $invalid]);
    $this->postJson('/api/m3u-proxy/webhooks')->assertOk()->assertHeader('X-RateLimit-Limit', '120');
})->with([0, -1, 'invalid', '', null, '1.5', 60001, '9999999999999999999999999', false, true, 1.5, [[]]]);

it('accepts positive integer bounds', function ($limit) {
    config(['proxy.callback_rate_limits.webhook' => $limit]);
    $this->postJson('/api/m3u-proxy/webhooks')->assertOk()->assertHeader('X-RateLimit-Limit', (string) $limit);
})->with([1, '3', 60000]);

it('honors cached configuration and routes in fresh application processes', function (string $enabled, string $limit, int $expectedLimit) {
    require_once base_path('tests/Fixtures/callback-redis-environment.php');
    if (getenv('CALLBACK_TEST_REDIS_PORT') === false) {
        $this->markTestSkipped('Set CALLBACK_TEST_REDIS_PORT to an explicitly disposable loopback Redis; never uses application Redis settings.');
    }
    $redisEnvironment = callbackRedisEnvironment(getenv('CALLBACK_TEST_REDIS_PORT'), 'callback-cache-test-'.Str::uuid().':');
    $directory = storage_path('framework/testing/callback-cache-'.Str::uuid());
    mkdir($directory, 0755, true);
    $environment = [
        'APP_ENV' => 'testing',
        'APP_CONFIG_CACHE' => $directory.'/config.php',
        'APP_ROUTES_CACHE' => $directory.'/routes.php',
        'LOG_CHANNEL' => 'null',
        'M3U_PROXY_TOKEN' => 'cached-callback-test-token',
        'M3U_PROXY_ALLOW_UNAUTHENTICATED_CALLBACKS' => 'false',
        'M3U_PROXY_ISOLATED_CALLBACK_RATE_LIMITS' => $enabled,
        'M3U_PROXY_CALLBACK_WEBHOOK_PER_MINUTE' => $limit,
        'M3U_PROXY_CALLBACK_RESOLVER_PER_MINUTE' => '2',
    ] + $redisEnvironment;
    $run = function (array $command, array $overrides = []) use ($environment) {
        $process = new Process($command, base_path(), array_merge($environment, $overrides));
        $process->setTimeout(60);
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());

        return $process->getOutput();
    };

    try {
        // Build routes with the opposite switch, then rebuild only config.
        // Cached routes must not freeze the opt-in decision.
        $run([PHP_BINARY, 'artisan', 'config:cache'], [
            'M3U_PROXY_ISOLATED_CALLBACK_RATE_LIMITS' => $enabled === 'true' ? 'false' : 'true',
        ]);
        $run([PHP_BINARY, 'artisan', 'route:cache']);
        $run([PHP_BINARY, 'artisan', 'config:cache']);
        // Changing env after caching must NOT change admission/configuration.
        $output = $run([PHP_BINARY, 'tests/Fixtures/probe-callback-cache.php'], [
            'M3U_PROXY_ISOLATED_CALLBACK_RATE_LIMITS' => $enabled === 'true' ? 'false' : 'true',
            'M3U_PROXY_CALLBACK_WEBHOOK_PER_MINUTE' => '999',
        ]);
        expect(json_validate($output))->toBeTrue($output);
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        expect($result['config_cached'])->toBeTrue()
            ->and($result['routes_cached'])->toBeTrue()
            ->and($result['enabled'])->toBe($enabled === 'true')
            ->and($result['webhook_limit'])->toBe((string) $expectedLimit)
            ->and($result['statuses'])->toBe([200, 429, $enabled === 'true' ? 400 : 429]);
    } finally {
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with([
    ['true', '2', 2],
    ['false', '2', 120],
    ['true', '0', 120],
    ['invalid', '2', 120],
]);
