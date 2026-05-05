<?php

use App\Services\M3uProxyService;
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

    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return str_contains($request->url(), '/streams/by-metadata')
            && ($query['field'] ?? null) === 'channel_id'
            && ($query['value'] ?? null) === '42'
            && ($query['force'] ?? null) === 'false';
    });
});

it('returns 204 for valid episode stop request', function () {
    Http::fake(['*' => Http::response(['deleted_count' => 1], 200)]);

    config(['proxy.m3u_proxy_host' => 'http://proxy.test']);

    $this->postJson('/api/m3u-proxy/player-stream/stop', [
        'id' => 7,
        'type' => 'episode',
    ])->assertNoContent();

    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return str_contains($request->url(), '/streams/by-metadata')
            && ($query['field'] ?? null) === 'episode_id'
            && ($query['value'] ?? null) === '7'
            && ($query['force'] ?? null) === 'false';
    });
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

it('sends force=true by default when stopping streams by metadata directly', function () {
    Http::fake(['*' => Http::response(['deleted_count' => 1, 'deleted_streams' => [], 'skipped_streams' => []], 200)]);

    config(['proxy.m3u_proxy_host' => 'http://proxy.test']);

    M3uProxyService::stopStreamsByMetadata('playlist_uuid', 'some-uuid');

    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return str_contains($request->url(), '/streams/by-metadata')
            && ($query['force'] ?? null) === 'true';
    });
});

it('forwards client_id to the proxy when provided in the stop request', function () {
    Http::fake(['*' => Http::response(['deleted_count' => 1], 200)]);

    config(['proxy.m3u_proxy_host' => 'http://proxy.test']);

    $this->postJson('/api/m3u-proxy/player-stream/stop', [
        'id' => 42,
        'type' => 'channel',
        'client_id' => 'floating-player-test-abc',
    ])->assertNoContent();

    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return str_contains($request->url(), '/streams/by-metadata')
            && ($query['client_id'] ?? null) === 'floating-player-test-abc'
            && ($query['force'] ?? null) === 'false';
    });
});

it('does not include client_id in proxy request when not provided', function () {
    Http::fake(['*' => Http::response(['deleted_count' => 1], 200)]);

    config(['proxy.m3u_proxy_host' => 'http://proxy.test']);

    $this->postJson('/api/m3u-proxy/player-stream/stop', [
        'id' => 42,
        'type' => 'channel',
    ])->assertNoContent();

    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return str_contains($request->url(), '/streams/by-metadata')
            && ! array_key_exists('client_id', $query);
    });
});
