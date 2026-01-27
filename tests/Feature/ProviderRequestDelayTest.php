<?php

use App\Settings\GeneralSettings;
use App\Traits\ProviderRequestDelay;
use Illuminate\Support\Facades\Cache;

/**
 * Helper function to create a test class that uses the trait.
 */
function createTestClass(): object
{
    return new class
    {
        use ProviderRequestDelay;

        public function callApplyDelay(): void
        {
            $this->applyProviderRequestDelay();
        }

        public function callAcquireSlot(): ?string
        {
            return $this->acquireProviderRequestSlot();
        }

        public function callReleaseSlot(?string $key): void
        {
            $this->releaseProviderRequestSlot($key);
        }

        public function callWithThrottling(callable $callback): mixed
        {
            return $this->withProviderThrottling($callback);
        }
    };
}

beforeEach(function () {
    Cache::flush();
});

it('does not apply delay when disabled', function () {
    // Mock settings instead of saving to avoid missing properties error
    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->enable_provider_request_delay = false;
    $mockSettings->provider_request_delay_ms = 500;
    app()->instance(GeneralSettings::class, $mockSettings);

    $testClass = createTestClass();

    $startTime = microtime(true);
    $testClass->callApplyDelay();
    $elapsedMs = (microtime(true) - $startTime) * 1000;

    // Should complete almost instantly (less than 50ms)
    expect($elapsedMs)->toBeLessThan(50);
});

it('applies delay when enabled', function () {
    // Mock settings instead of saving
    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->enable_provider_request_delay = true;
    $mockSettings->provider_request_delay_ms = 200;
    app()->instance(GeneralSettings::class, $mockSettings);

    $testClass = createTestClass();

    $startTime = microtime(true);
    $testClass->callApplyDelay();
    $elapsedMs = (microtime(true) - $startTime) * 1000;

    // Should take at least 200ms (with some tolerance)
    expect($elapsedMs)->toBeGreaterThan(180);
    expect($elapsedMs)->toBeLessThan(400);
});

it('returns null slot when concurrency is disabled', function () {
    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->enable_provider_request_delay = false;
    app()->instance(GeneralSettings::class, $mockSettings);

    $testClass = createTestClass();
    $slot = $testClass->callAcquireSlot();

    expect($slot)->toBeNull();
});

it('acquires and releases slots correctly', function () {
    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->enable_provider_request_delay = true;
    $mockSettings->provider_max_concurrent_requests = 2;
    $mockSettings->provider_request_delay_ms = 0;
    app()->instance(GeneralSettings::class, $mockSettings);

    $testClass = createTestClass();

    // Acquire first slot
    $slot1 = $testClass->callAcquireSlot();
    expect($slot1)->not->toBeNull();
    expect((int) Cache::get('provider_concurrent_requests:count'))->toBe(1);

    // Acquire second slot
    $slot2 = $testClass->callAcquireSlot();
    expect($slot2)->not->toBeNull();
    expect((int) Cache::get('provider_concurrent_requests:count'))->toBe(2);

    // Release first slot
    $testClass->callReleaseSlot($slot1);
    expect((int) Cache::get('provider_concurrent_requests:count'))->toBe(1);

    // Release second slot
    $testClass->callReleaseSlot($slot2);
    expect((int) Cache::get('provider_concurrent_requests:count'))->toBe(0);
});

it('does not decrement below zero', function () {
    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->enable_provider_request_delay = true;
    $mockSettings->provider_max_concurrent_requests = 2;
    app()->instance(GeneralSettings::class, $mockSettings);

    $testClass = createTestClass();

    // Set count to 0 explicitly
    Cache::put('provider_concurrent_requests:count', 0, 300);

    // Release without acquiring (should not go negative)
    $testClass->callReleaseSlot('fake-key');

    $count = (int) Cache::get('provider_concurrent_requests:count');
    expect($count)->toBe(0);
});

it('executes callback with throttling', function () {
    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->enable_provider_request_delay = true;
    $mockSettings->provider_max_concurrent_requests = 2;
    $mockSettings->provider_request_delay_ms = 100;
    app()->instance(GeneralSettings::class, $mockSettings);

    $testClass = createTestClass();

    $result = $testClass->callWithThrottling(function () {
        return 'success';
    });

    expect($result)->toBe('success');
    // After callback, slot should be released
    expect((int) Cache::get('provider_concurrent_requests:count', 0))->toBe(0);
});

it('releases slot even on exception', function () {
    $mockSettings = Mockery::mock(GeneralSettings::class);
    $mockSettings->enable_provider_request_delay = true;
    $mockSettings->provider_max_concurrent_requests = 2;
    $mockSettings->provider_request_delay_ms = 0;
    app()->instance(GeneralSettings::class, $mockSettings);

    $testClass = createTestClass();

    try {
        $testClass->callWithThrottling(function () {
            throw new \Exception('Test exception');
        });
    } catch (\Exception $e) {
        // Expected
    }

    // Slot should still be released
    expect((int) Cache::get('provider_concurrent_requests:count', 0))->toBe(0);
});

it('validates milliseconds as integer', function () {
    $settings = app(GeneralSettings::class);

    // Setting should only accept integers
    $settings->provider_request_delay_ms = 500;
    expect($settings->provider_request_delay_ms)->toBeInt();

    // Verify the type in the property
    $reflection = new \ReflectionClass($settings);
    $property = $reflection->getProperty('provider_request_delay_ms');
    $type = $property->getType();

    expect($type->allowsNull())->toBeTrue();
    expect($type->getName())->toBe('int');
});
