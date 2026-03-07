<?php

use Illuminate\Support\Facades\Http;

it('returns 422 when id is missing', function () {
    $this->postJson('/api/m3u-proxy/player-stream/stop', [
        'type' => 'channel',
    ])->assertNoContent(422);
});

it('returns 422 when type is missing', function () {
    $this->postJson('/api/m3u-proxy/player-stream/stop', [
        'id' => 1,
    ])->assertNoContent(422);
});

it('returns 422 for unknown type', function () {
    $this->postJson('/api/m3u-proxy/player-stream/stop', [
        'id' => 1,
        'type' => 'unknown',
    ])->assertNoContent(422);
});

it('returns 204 for valid channel stop request', function () {
    Http::fake(['*' => Http::response(['deleted_count' => 1], 200)]);

    config(['proxy.m3u_proxy_host' => 'http://proxy.test']);

    $this->postJson('/api/m3u-proxy/player-stream/stop', [
        'id' => 42,
        'type' => 'channel',
    ])->assertNoContent();
});

it('returns 204 for valid episode stop request', function () {
    Http::fake(['*' => Http::response(['deleted_count' => 1], 200)]);

    config(['proxy.m3u_proxy_host' => 'http://proxy.test']);

    $this->postJson('/api/m3u-proxy/player-stream/stop', [
        'id' => 7,
        'type' => 'episode',
    ])->assertNoContent();
});

it('returns 204 even when proxy is not configured', function () {
    config(['proxy.m3u_proxy_host' => '']);

    $this->postJson('/api/m3u-proxy/player-stream/stop', [
        'id' => 1,
        'type' => 'channel',
    ])->assertNoContent();
});

it('returns 204 even when proxy request fails', function () {
    Http::fake(['*' => Http::response('Server error', 500)]);

    config(['proxy.m3u_proxy_host' => 'http://proxy.test']);

    $this->postJson('/api/m3u-proxy/player-stream/stop', [
        'id' => 1,
        'type' => 'channel',
    ])->assertNoContent();
});
