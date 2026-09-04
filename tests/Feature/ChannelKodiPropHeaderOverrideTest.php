<?php

/**
 * Regression test: `#EXTVLCOPT:` and `#KODIPROP:` tags captured at M3U import into
 * `channels.extvlcopt` / `channels.kodidrop` were only ever re-emitted verbatim in
 * this app's own generated M3U for players (Kodi/VLC) to read directly - the proxy's
 * upstream fetch never saw them. Some providers' CDN edges require the Referer/Cookie/
 * User-Agent the source M3U specified per-channel and reject a fetch without it, even
 * though the same URL plays fine outside the proxy. getChannelUrl() now forwards these
 * as the proxy's `user_agent` / `headers` payload, with per-channel values taking
 * precedence over the playlist's defaults.
 */

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create(['permissions' => ['use_proxy']]);
    config(['proxy.m3u_proxy_host' => 'http://localhost', 'proxy.m3u_proxy_port' => 8765]);
});

test('getChannelUrl forwards #EXTVLCOPT http-user-agent and http-referrer to the proxy', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
        'enable_proxy' => true,
        'available_streams' => 0,
        'xtream' => false,
        'user_agent' => 'PlaylistDefaultAgent/1.0',
    ]);

    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
        'url' => 'https://dash.akamaized.net/akamai/bbb_30fps/bbb_30fps.mpd',
        'extvlcopt' => [
            ['key' => 'http-user-agent', 'value' => 'ChannelSpecificAgent/2.0'],
            ['key' => 'http-referrer', 'value' => 'https://provider.example/'],
        ],
    ]);

    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [], 'total_matching' => 0, 'total_clients' => 0]),
        '*/streams' => Http::response(['stream_id' => 'kodiprop-stream-id']),
    ]);

    app(M3uProxyService::class)->getChannelUrl($playlist, $channel);

    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/streams')) {
            return false;
        }

        $body = $request->data();

        return ($body['user_agent'] ?? null) === 'ChannelSpecificAgent/2.0'
            && ($body['headers']['Referer'] ?? null) === 'https://provider.example/';
    });
});

test('getChannelUrl forwards #KODIPROP inputstream.adaptive.stream_headers and overrides EXTVLCOPT', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
        'enable_proxy' => true,
        'available_streams' => 0,
        'xtream' => false,
        'user_agent' => 'PlaylistDefaultAgent/1.0',
        'custom_headers' => [
            ['header' => 'X-Playlist-Header', 'value' => 'from-playlist'],
        ],
    ]);

    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
        'url' => 'https://dash.akamaized.net/akamai/bbb_30fps/bbb_30fps.mpd',
        'extvlcopt' => [
            ['key' => 'http-user-agent', 'value' => 'FromExtVlcOpt/1.0'],
        ],
        'kodidrop' => [
            ['key' => 'inputstream.adaptive.manifest_type', 'value' => 'mpd'],
            ['key' => 'inputstream.adaptive.stream_headers', 'value' => 'User-Agent=FromKodiProp%2F2.0&Referer=https%3A%2F%2Fprovider.example%2F&Cookie=session%3Dabc123'],
        ],
    ]);

    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [], 'total_matching' => 0, 'total_clients' => 0]),
        '*/streams' => Http::response(['stream_id' => 'kodiprop-stream-id-2']),
    ]);

    app(M3uProxyService::class)->getChannelUrl($playlist, $channel);

    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/streams')) {
            return false;
        }

        $body = $request->data();

        return ($body['user_agent'] ?? null) === 'FromKodiProp/2.0'
            && ($body['headers']['Referer'] ?? null) === 'https://provider.example/'
            && ($body['headers']['Cookie'] ?? null) === 'session=abc123'
            && ($body['headers']['X-Playlist-Header'] ?? null) === 'from-playlist';
    });
});

test('getChannelUrl leaves user_agent/headers untouched for channels without EXTVLCOPT/KODIPROP tags', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
        'enable_proxy' => true,
        'available_streams' => 0,
        'xtream' => false,
        'user_agent' => 'PlaylistDefaultAgent/1.0',
    ]);

    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
        'url' => 'http://provider.example/live/user/pass/1234.ts',
    ]);

    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [], 'total_matching' => 0, 'total_clients' => 0]),
        '*/streams' => Http::response(['stream_id' => 'ordinary-stream-id']),
    ]);

    app(M3uProxyService::class)->getChannelUrl($playlist, $channel);

    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/streams')) {
            return false;
        }

        $body = $request->data();

        return ($body['user_agent'] ?? null) === 'PlaylistDefaultAgent/1.0'
            && ! isset($body['headers']);
    });
});
