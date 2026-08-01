<?php

use App\Models\MediaServerIntegration;
use App\Models\User;
use App\Services\AIOStreamsService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

it('throttles outbound AIOStreams requests once the configured per-integration limit is hit', function () {
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

    $settings = app(GeneralSettings::class);
    $settings->aiostreams_rate_limit = 2;
    $settings->save();

    $key = 'aiostreams-rate-limit-'.$integration->id;
    RateLimiter::clear($key);

    $service = AIOStreamsService::make($integration);

    // First two calls should not block.
    $service->fetchMeta('movie', 'tt1');
    $service->fetchMeta('movie', 'tt2');

    expect(RateLimiter::tooManyAttempts($key, 2))->toBeTrue();
});
