<?php

/**
 * Reproduction test for issue #1272: streaming through a PlaylistAlias of an
 * M3U-imported playlist with the proxy enabled must send the credential-swapped
 * provider URL to m3u-proxy (same swap that #1262 fixed for direct streaming).
 */

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create([
        'name' => 'owner',
        'permissions' => ['use_proxy'],
    ]);

    config(['proxy.m3u_proxy_host' => 'http://localhost', 'proxy.m3u_proxy_port' => 8765]);
    config(['proxy.m3u_proxy_token' => 'test-token']);
    config(['cache.default' => 'array']);
});

test('proxied live stream through M3U-playlist alias sends swapped credentials to m3u-proxy', function (string $channelUrl, string $expectedProxiedUrl) {
    // M3U-imported playlist: no stored xtream config
    $playlist = Playlist::factory()->for($this->user)->createQuietly([
        'xtream_config' => null,
        'enable_proxy' => false,
        'available_streams' => 0,
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'group_id' => null,
        'enabled' => true,
        'url' => $channelUrl,
    ]);

    $alias = PlaylistAlias::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Test Alias',
        'uuid' => Str::uuid()->toString(),
        'enable_proxy' => true,
        'xtream_config' => [[
            'url' => 'http://provider.example.com:8080',
            'username' => 'newuser',
            'password' => 'newpass',
        ]],
    ]);

    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [],
            'total_matching' => 0,
            'total_clients' => 0,
        ]),
        '*/streams' => Http::response(['stream_id' => 'test-stream-id']),
    ]);

    $response = $this->get("/live/owner/{$alias->uuid}/{$channel->id}.ts");

    $response->assertRedirect();

    $createRequest = null;
    Http::assertSent(function (ClientRequest $request) use (&$createRequest) {
        if ($request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/streams')) {
            $createRequest = $request;

            return true;
        }

        return false;
    });

    expect($createRequest)->not->toBeNull();
    expect($createRequest['url'])->toBe($expectedProxiedUrl);
})->with([
    'prefixed .ts URL' => [
        'http://provider.example.com:8080/live/olduser/oldpass/1234.ts',
        'http://provider.example.com:8080/live/newuser/newpass/1234.ts',
    ],
    'prefix-less URL without extension' => [
        'http://provider.example.com:8080/olduser/oldpass/1234',
        'http://provider.example.com:8080/newuser/newpass/1234',
    ],
    'prefix-less .m3u8 URL' => [
        'http://provider.example.com:8080/olduser/oldpass/1234.m3u8',
        'http://provider.example.com:8080/newuser/newpass/1234.m3u8',
    ],
]);
