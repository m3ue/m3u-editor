<?php

/**
 * Regression test for the PHP-FPM worker-exhaustion crash: a media-server-backed
 * (Plex/Emby/Jellyfin/WebDAV) channel's `url` column stores this app's own
 * API-key-hiding proxy URL (e.g. /media-server/{id}/stream/{item}.mkv), which used
 * to be handed straight to M3uProxyService::createStream() as the upstream source.
 * That made the Python m3u-proxy's upstream fetch loop back through PHP's raw
 * curl_exec() relay for the full duration of every stream, which — under a handful
 * of concurrent/rapidly-reseeked streams — exhausted PHP-FPM's worker pool and took
 * the whole app down (dashboard, Livewire, unrelated routes included).
 *
 * M3uProxyService::resolveMediaServerUpstreamUrl() now detects that shape of URL and
 * swaps it for the real upstream (Plex query-token / Emby api_key / WebDAV Basic Auth)
 * before it's ever sent to the proxy, so the proxy fetches the media server directly.
 */

use App\Models\Channel;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create(['permissions' => ['use_proxy']]);
    config(['proxy.m3u_proxy_host' => 'http://localhost', 'proxy.m3u_proxy_port' => 8765]);
});

test('getChannelUrl resolves an Emby/Jellyfin proxy URL to the real upstream before creating the stream', function () {
    $integration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'jellyfin',
        'host' => 'jellyfin.local',
        'port' => 8096,
        'ssl' => false,
        'api_key' => 'super-secret-key',
    ]);

    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
        'enable_proxy' => true,
        'available_streams' => 0,
        'xtream' => false,
    ]);

    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => true,
        'url' => "http://m3ueditor.test/media-server/{$integration->id}/stream/97255.mkv",
    ]);

    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [], 'total_matching' => 0, 'total_clients' => 0]),
        '*/streams' => Http::response(['stream_id' => 'resolved-stream-id']),
    ]);

    $url = app(M3uProxyService::class)->getChannelUrl($playlist, $channel);

    expect($url)->toContain('stream/resolved-stream-id');

    // The critical assertion: the proxy's upstream `url` is the real Jellyfin URL, hitting
    // Jellyfin directly — never PHP's /media-server/... curl-relay route.
    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/streams')) {
            return false;
        }

        $body = $request->data();

        return str_starts_with($body['url'] ?? '', 'http://jellyfin.local:8096/Videos/97255/stream.mkv')
            && str_contains($body['url'], 'api_key=super-secret-key')
            && ! str_contains($body['url'], '/media-server/');
    });
});

test('getChannelUrl resolves a WebDAV proxy URL to the real file URL with Basic Auth header', function () {
    $integration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'webdav',
        'host' => 'webdav.local',
        'port' => 443,
        'ssl' => true,
        'webdav_username' => 'alice',
        'webdav_password' => 'wonderland',
    ]);

    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
        'enable_proxy' => true,
        'available_streams' => 0,
        'xtream' => false,
    ]);

    $encodedItem = base64_encode('Movies/Inception (2010)/Inception.mkv');

    $channel = Channel::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => true,
        'url' => "http://m3ueditor.test/webdav-media/{$integration->id}/stream/{$encodedItem}",
    ]);

    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [], 'total_matching' => 0, 'total_clients' => 0]),
        '*/streams' => Http::response(['stream_id' => 'webdav-stream-id']),
    ]);

    $url = app(M3uProxyService::class)->getChannelUrl($playlist, $channel);

    expect($url)->toContain('stream/webdav-stream-id');

    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/streams')) {
            return false;
        }

        $body = $request->data();
        $expectedAuth = 'Basic '.base64_encode('alice:wonderland');

        return ($body['url'] ?? null) === 'https://webdav.local/Movies/Inception%20%282010%29/Inception.mkv'
            && ($body['headers']['Authorization'] ?? null) === $expectedAuth;
    });
});

test('getChannelUrl leaves ordinary Xtream/M3U provider URLs untouched', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
        'enable_proxy' => true,
        'available_streams' => 0,
        'xtream' => false,
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

        return ($request->data()['url'] ?? null) === 'http://provider.example/live/user/pass/1234.ts';
    });
});
