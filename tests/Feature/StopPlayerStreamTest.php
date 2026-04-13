<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

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
            && ($query['value'] ?? null) === '42';
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
            && ($query['value'] ?? null) === '7';
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

it('stops the stream when the last player session unregisters', function () {
    Http::fake(['*' => Http::response(['deleted_count' => 1], 200)]);
    config(['proxy.m3u_proxy_host' => 'http://proxy.test']);

    $sessionKey = 'player_sessions:channel:42';
    Redis::del($sessionKey);
    Redis::sadd($sessionKey, 'session-abc');

    $this->postJson('/api/m3u-proxy/player-stream/stop', [
        'id' => 42,
        'type' => 'channel',
        'player_session' => 'session-abc',
    ])->assertNoContent();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/streams/by-metadata'));
    expect((int) Redis::scard($sessionKey))->toBe(0);
});

it('skips stopping when other player sessions remain', function () {
    Http::fake();
    config(['proxy.m3u_proxy_host' => 'http://proxy.test']);

    $sessionKey = 'player_sessions:channel:42';
    Redis::del($sessionKey);
    Redis::sadd($sessionKey, 'session-abc', 'session-def');

    $this->postJson('/api/m3u-proxy/player-stream/stop', [
        'id' => 42,
        'type' => 'channel',
        'player_session' => 'session-abc',
    ])->assertNoContent();

    Http::assertNothingSent();
    expect((int) Redis::scard($sessionKey))->toBe(1);

    Redis::del($sessionKey);
});

it('falls back to stopping when no player_session is provided (legacy clients)', function () {
    Http::fake(['*' => Http::response(['deleted_count' => 1], 200)]);
    config(['proxy.m3u_proxy_host' => 'http://proxy.test']);

    $this->postJson('/api/m3u-proxy/player-stream/stop', [
        'id' => 42,
        'type' => 'channel',
    ])->assertNoContent();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/streams/by-metadata'));
});

it('handles session-guarded episode stop correctly', function () {
    Http::fake(['*' => Http::response(['deleted_count' => 1], 200)]);
    config(['proxy.m3u_proxy_host' => 'http://proxy.test']);

    $sessionKey = 'player_sessions:episode:7';
    Redis::del($sessionKey);
    Redis::sadd($sessionKey, 'session-ep1');

    $this->postJson('/api/m3u-proxy/player-stream/stop', [
        'id' => 7,
        'type' => 'episode',
        'player_session' => 'session-ep1',
    ])->assertNoContent();

    Http::assertSent(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return str_contains($request->url(), '/streams/by-metadata')
            && ($query['field'] ?? null) === 'episode_id'
            && ($query['value'] ?? null) === '7';
    });

    Redis::del($sessionKey);
});
