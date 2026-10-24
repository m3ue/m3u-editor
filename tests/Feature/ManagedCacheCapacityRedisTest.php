<?php

use App\Services\ManagedCacheCapacityService;
use App\Services\ManagedCacheCapacitySnapshot;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function (): void {
    if (env('MANAGED_CACHE_REDIS_INTEGRATION') !== '1') {
        $this->markTestSkipped('Set MANAGED_CACHE_REDIS_INTEGRATION=1 to run against the dedicated Redis test service.');
    }

    config()->set('cache.default', 'redis');
    config()->set('cache.prefix', 't_3e712143:capacity:'.Str::uuid().':');
    app()->forgetInstance('cache.store');
});

afterEach(function (): void {
    if (env('MANAGED_CACHE_REDIS_INTEGRATION') !== '1') {
        return;
    }

    /** @var RedisStore $store */
    $store = Cache::store()->getStore();
    $connection = $store->getRedis()->connection(config('cache.stores.redis.connection'));

    foreach ($connection->keys($store->getPrefix().'*') as $key) {
        $connection->del($key);
    }
});

it('uses an owned Redis key with a two-minute lease that renewal extends', function () {
    $service = app(ManagedCacheCapacityService::class);
    $group = 't_3e712143_'.Str::uuid();
    $snapshot = new ManagedCacheCapacitySnapshot($group, limit: 3, live: 1, background: 0);
    $reservation = $service->reserve($snapshot);

    expect($reservation)->not->toBeNull();

    /** @var RedisStore $store */
    $store = Cache::store()->getStore();
    $activeKey = 'managed-cache-capacity:'.hash('sha256', $group).':active';
    $connection = $store->getRedis()->connection(config('cache.stores.redis.connection'));
    $initialTtl = $connection->pttl($store->getPrefix().$activeKey);

    expect($initialTtl)->toBeGreaterThan(110_000)
        ->toBeLessThanOrEqual(120_000);

    usleep(1_000_000);

    $beforeRenewal = $connection->pttl($store->getPrefix().$activeKey);

    expect($service->renew($reservation))->toBeTrue();

    $renewedTtl = $connection->pttl($store->getPrefix().$activeKey);

    expect($renewedTtl)->toBeGreaterThan($beforeRenewal)
        ->and($renewedTtl)->toBeGreaterThan(110_000)
        ->and($renewedTtl)->toBeLessThanOrEqual(120_000);
});

it('does not renew a released Redis reservation token', function () {
    $service = app(ManagedCacheCapacityService::class);
    $snapshot = new ManagedCacheCapacitySnapshot('t_3e712143_'.Str::uuid(), limit: 3, live: 1, background: 0);
    $reservation = $service->reserve($snapshot);

    expect($reservation)->not->toBeNull();

    $service->release($reservation);

    expect($service->renew($reservation))->toBeFalse();
});

it('does not let a stale owner renew or release a replacement Redis reservation', function () {
    $service = app(ManagedCacheCapacityService::class);
    $snapshot = new ManagedCacheCapacitySnapshot('t_3e712143_'.Str::uuid(), limit: 3, live: 1, background: 0);
    $staleReservation = $service->reserve($snapshot);

    expect($staleReservation)->not->toBeNull();

    $service->release($staleReservation);
    $replacementReservation = $service->reserve($snapshot);

    expect($replacementReservation)->not->toBeNull()
        ->and($replacementReservation)->not->toBe($staleReservation)
        ->and($service->renew($staleReservation))->toBeFalse();

    $service->release($staleReservation);

    expect($service->canUseReservation($replacementReservation, $snapshot))->toBeTrue();
});
