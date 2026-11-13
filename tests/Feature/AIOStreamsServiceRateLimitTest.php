<?php

use App\Models\MediaServerIntegration;
use App\Models\User;
use App\Services\AIOStreamsService;
use App\Settings\GeneralSettings;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

it('throttles outbound AIOStreams requests once the configured per-integration limit is hit', function () {
    // config/cache.php pins the default store to Redis, so the real limiter is
    // shared by every parallel test process (and by earlier runs), and every
    // process's first integration has id 1. Other workers' hits on the same
    // key, or one of their windows expiring mid-test, made this flaky. An
    // in-memory limiter keeps the count to this test alone.
    RateLimiter::swap(new CacheRateLimiter(Cache::store('array')));

    $user = User::factory()->create();
    $integration = MediaServerIntegration::create([
        'user_id' => $user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/abc/manifest.json',
    ]);

    Http::fake([
        'aiostreams.test/*' => Http::response(['meta' => ['name' => 'Test']], 200),
    ]);

    // In memory, like the other AIOStreams tests: saving needs every settings
    // row in the database, which a fresh test database doesn't have.
    $settings = new GeneralSettings;
    $settings->aiostreams_rate_limit = 2;
    $settings->tmdb_api_key = null;
    app()->instance(GeneralSettings::class, $settings);

    $key = 'aiostreams-rate-limit-'.$integration->id;

    $service = AIOStreamsService::make($integration);

    // First two calls should not block.
    $service->fetchMeta('movie', 'tt1');
    $service->fetchMeta('movie', 'tt2');

    expect(RateLimiter::tooManyAttempts($key, 2))->toBeTrue();
});
