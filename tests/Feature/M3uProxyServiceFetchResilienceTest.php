<?php

use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('proxy.m3u_proxy_host', 'http://test-proxy.example');
    config()->set('proxy.m3u_proxy_port', null);

    $this->user = User::factory()->create(['permissions' => ['use_proxy']]);
    $this->actingAs($this->user);
});

it('classifies connection failures and returns an empty collection', function (string $method, string $path, string $collectionKey) {
    Http::fake(['*'.$path => Http::failedConnection()]);

    $result = app(M3uProxyService::class)->{$method}();

    expect($result['success'])->toBeFalse()
        ->and($result['error_category'])->toBe('connection')
        ->and($result[$collectionKey])->toBe([])
        ->and($result['error'])->toContain('M3U Proxy unreachable');
})->with([
    'streams' => ['fetchActiveStreams', '/streams', 'streams'],
    'clients' => ['fetchActiveClients', '/clients', 'clients'],
    'broadcasts' => ['fetchBroadcasts', '/broadcast', 'broadcasts'],
]);

it('classifies HTTP errors as http category', function (string $method, string $path, string $collectionKey) {
    Http::fake(['*'.$path => Http::response('', 503)]);

    $result = app(M3uProxyService::class)->{$method}();

    expect($result['success'])->toBeFalse()
        ->and($result['error_category'])->toBe('http')
        ->and($result[$collectionKey])->toBe([])
        ->and($result['error'])->toContain('503');
})->with([
    'streams' => ['fetchActiveStreams', '/streams', 'streams'],
    'clients' => ['fetchActiveClients', '/clients', 'clients'],
    'broadcasts' => ['fetchBroadcasts', '/broadcast', 'broadcasts'],
]);

it('retries on connection failure before giving up', function () {
    Http::fake(['*/streams' => Http::failedConnection()]);

    app(M3uProxyService::class)->fetchActiveStreams();

    Http::assertSentCount(2);
});

it('does not retry on HTTP errors', function () {
    Http::fake(['*/clients' => Http::response('boom', 500)]);

    app(M3uProxyService::class)->fetchActiveClients();

    Http::assertSentCount(1);
});

it('sends the api token header when configured', function () {
    config()->set('proxy.m3u_proxy_token', 'secret-token');

    Http::fake(['*/streams' => Http::response(['streams' => []], 200)]);

    app(M3uProxyService::class)->fetchActiveStreams();

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-API-Token', 'secret-token'));
});
