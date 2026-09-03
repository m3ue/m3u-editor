<?php

use App\Models\Playlist;
use App\Models\User;
use App\Services\XtreamService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Cache::flush();
    $this->playlist = Playlist::factory()->for(User::factory())->create([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://primary.example.com:8080',
            'username' => 'testuser',
            'password' => 'testpass',
        ],
        'xtream_fallback_urls' => null,
    ]);
});

it('stops retrying as soon as the provider answers 429', function () {
    Http::fake(['*' => Http::response('Too Many Requests', 429)]);

    $service = XtreamService::make(playlist: $this->playlist, retryLimit: 3);

    expect(fn () => $service->userInfo())->toThrow(Exception::class, 'rate limit');
    Http::assertSentCount(1);
});

it('refuses further calls without touching the network while rate limited', function () {
    Http::fake(['*' => Http::response('Too Many Requests', 429)]);

    $service = XtreamService::make(playlist: $this->playlist, retryLimit: 3);
    expect(fn () => $service->userInfo())->toThrow(Exception::class);

    // Second call, same host: the circuit is open, nothing goes out.
    expect(fn () => $service->getSeriesInfo('42'))->toThrow(Exception::class, 'rate limit');
    Http::assertSentCount(1);

    // A fresh instance shares the state (it lives in the cache, not the object).
    $other = XtreamService::make(playlist: $this->playlist, retryLimit: 3);
    expect(fn () => $other->userInfo())->toThrow(Exception::class, 'rate limit');
    Http::assertSentCount(1);
});

it('does not try fallback URLs on 429 — the limit is per account, not per host', function () {
    Http::fake([
        'primary.example.com:8080/*' => Http::response('Too Many Requests', 429),
        'fallback1.example.com:8080/*' => Http::response(['user_info' => ['status' => 'Active']], 200),
    ]);
    $this->playlist->update(['xtream_fallback_urls' => ['http://fallback1.example.com:8080']]);

    $service = XtreamService::make(playlist: $this->playlist, retryLimit: 1);

    expect(fn () => $service->userInfo())->toThrow(Exception::class, 'rate limit');
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fallback1'));
});

it('lets calls through again once the cooldown has elapsed', function () {
    Http::fake([
        '*' => Http::sequence()
            ->push('Too Many Requests', 429)
            ->push(['user_info' => ['status' => 'Active']], 200),
    ]);
    config(['xtream.rate_limit_cooldown' => 60]);

    $service = XtreamService::make(playlist: $this->playlist, retryLimit: 1);
    expect(fn () => $service->userInfo())->toThrow(Exception::class);

    $this->travel(61)->seconds();

    expect($service->userInfo()['user_info']['status'])->toBe('Active');
    Http::assertSentCount(2);
});

it('keeps retrying on other errors', function () {
    Http::fake(['*' => Http::response('Error', 500)]);

    $service = XtreamService::make(playlist: $this->playlist, retryLimit: 2);

    expect(fn () => $service->userInfo())->toThrow(Exception::class);
    Http::assertSentCount(2);
});
