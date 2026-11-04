<?php

use Illuminate\Routing\Route;

/**
 * The throttle key is only the user id or IP, so without a key prefix every
 * throttle:N,M route shares one counter and a burst on one consumes the others.
 */
beforeEach(function () {
    // Real Redis throttling, but a unique IP per test so counters never carry over.
    $this->withServerVariables(['REMOTE_ADDR' => fake()->ipv6()]);
});

it('throttles the EPG and playlist sync endpoints separately', function () {
    foreach (range(1, 5) as $attempt) {
        $this->getJson(route('api.epg.sync', ['uuid' => 'missing']))->assertNotFound();
    }

    $this->getJson(route('api.epg.sync', ['uuid' => 'missing']))->assertTooManyRequests();

    $this->getJson(route('api.playlist.sync', ['uuid' => 'missing']))
        ->assertNotFound()
        ->assertHeader('X-RateLimit-Remaining', '4');
});

it('does not let other throttled routes consume the EPG sync allowance', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('device.token'));
    }

    $this->getJson(route('api.epg.sync', ['uuid' => 'missing']))
        ->assertNotFound()
        ->assertHeader('X-RateLimit-Remaining', '4');
});

it('allows the full TV log upload limit without tripping other TV routes', function () {
    $logsUrl = route('tv.logs.upload', ['username' => 'nobody', 'password' => 'wrong']);

    foreach (range(1, 6) as $attempt) {
        expect($this->postJson($logsUrl)->getStatusCode())->not->toBe(429);
    }

    $this->postJson($logsUrl)->assertTooManyRequests();

    $notificationsUrl = route('tv.notifications', ['username' => 'nobody', 'password' => 'wrong']);
    expect($this->getJson($notificationsUrl)->getStatusCode())->not->toBe(429);
});

it('gives every rate limited route a throttle key prefix', function () {
    $routesWithoutPrefix = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => collect($route->middleware())->contains(
            fn (mixed $middleware): bool => is_string($middleware) && preg_match('/^throttle:\d+,\d+$/', $middleware) === 1,
        ))
        ->map(fn (Route $route): string => $route->uri())
        ->values()
        ->all();

    expect($routesWithoutPrefix)->toBe([]);
});
