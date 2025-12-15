<?php

namespace Tests\Unit;

use App\Settings\GeneralSettings;
use App\Traits\ProviderRequestDelay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProviderRequestDelayTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper class that uses the trait for testing.
     */
    private function getTestClass(): object
    {
        return new class {
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

    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test
        Cache::flush();
    }

    /** @test */
    public function it_does_not_apply_delay_when_disabled(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->enable_provider_request_delay = false;
        $settings->provider_request_delay_ms = 500;
        $settings->save();

        $testClass = $this->getTestClass();

        $startTime = microtime(true);
        $testClass->callApplyDelay();
        $elapsedMs = (microtime(true) - $startTime) * 1000;

        // Should complete almost instantly (less than 50ms)
        $this->assertLessThan(50, $elapsedMs);
    }

    /** @test */
    public function it_applies_delay_when_enabled(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->enable_provider_request_delay = true;
        $settings->provider_request_delay_ms = 200;
        $settings->save();

        $testClass = $this->getTestClass();

        $startTime = microtime(true);
        $testClass->callApplyDelay();
        $elapsedMs = (microtime(true) - $startTime) * 1000;

        // Should take at least 200ms (with some tolerance)
        $this->assertGreaterThan(180, $elapsedMs);
        $this->assertLessThan(400, $elapsedMs);
    }

    /** @test */
    public function it_returns_null_slot_when_concurrency_disabled(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->enable_provider_request_delay = false;
        $settings->save();

        $testClass = $this->getTestClass();
        $slot = $testClass->callAcquireSlot();

        $this->assertNull($slot);
    }

    /** @test */
    public function it_acquires_and_releases_slots_correctly(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->enable_provider_request_delay = true;
        $settings->provider_max_concurrent_requests = 2;
        $settings->provider_request_delay_ms = 0;
        $settings->save();

        $testClass = $this->getTestClass();

        // Acquire first slot
        $slot1 = $testClass->callAcquireSlot();
        $this->assertNotNull($slot1);
        $this->assertEquals(1, Cache::get('provider_concurrent_requests:count'));

        // Acquire second slot
        $slot2 = $testClass->callAcquireSlot();
        $this->assertNotNull($slot2);
        $this->assertEquals(2, Cache::get('provider_concurrent_requests:count'));

        // Release first slot
        $testClass->callReleaseSlot($slot1);
        $this->assertEquals(1, Cache::get('provider_concurrent_requests:count'));

        // Release second slot
        $testClass->callReleaseSlot($slot2);
        $this->assertEquals(0, Cache::get('provider_concurrent_requests:count'));
    }

    /** @test */
    public function it_does_not_decrement_below_zero(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->enable_provider_request_delay = true;
        $settings->provider_max_concurrent_requests = 2;
        $settings->save();

        $testClass = $this->getTestClass();

        // Set count to 0 explicitly
        Cache::put('provider_concurrent_requests:count', 0, 300);

        // Release without acquiring (should not go negative)
        $testClass->callReleaseSlot('fake-key');

        $count = Cache::get('provider_concurrent_requests:count');
        $this->assertEquals(0, $count);
    }

    /** @test */
    public function it_executes_callback_with_throttling(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->enable_provider_request_delay = true;
        $settings->provider_max_concurrent_requests = 2;
        $settings->provider_request_delay_ms = 100;
        $settings->save();

        $testClass = $this->getTestClass();

        $result = $testClass->callWithThrottling(function () {
            return 'success';
        });

        $this->assertEquals('success', $result);
        // After callback, slot should be released
        $this->assertEquals(0, Cache::get('provider_concurrent_requests:count', 0));
    }

    /** @test */
    public function it_releases_slot_even_on_exception(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->enable_provider_request_delay = true;
        $settings->provider_max_concurrent_requests = 2;
        $settings->provider_request_delay_ms = 0;
        $settings->save();

        $testClass = $this->getTestClass();

        try {
            $testClass->callWithThrottling(function () {
                throw new \Exception('Test exception');
            });
        } catch (\Exception $e) {
            // Expected
        }

        // Slot should still be released
        $this->assertEquals(0, Cache::get('provider_concurrent_requests:count', 0));
    }

    /** @test */
    public function it_validates_milliseconds_as_integer(): void
    {
        $settings = app(GeneralSettings::class);

        // Setting should only accept integers
        $settings->provider_request_delay_ms = 500;
        $this->assertIsInt($settings->provider_request_delay_ms);

        // Verify the type in the property
        $reflection = new \ReflectionClass($settings);
        $property = $reflection->getProperty('provider_request_delay_ms');
        $type = $property->getType();

        $this->assertTrue($type->allowsNull());
        $this->assertEquals('int', $type->getName());
    }
}
