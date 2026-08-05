<?php

/**
 * DASH (.mpd) proxy URL routing, per issue #723.
 *
 * Covers:
 * - Channel::getProxyUrl() detects a .mpd source URL and produces a .mpd
 *   extension in this app's own internal proxy URL.
 * - M3uProxyService::buildProxyUrl() routes an 'mpd' format to the m3u-proxy
 *   /dash/{stream_id}/manifest.mpd endpoint, mirroring the existing hls/direct
 *   branches.
 */

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

test('getProxyUrl detects a .mpd channel URL and uses mpd as the format', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'https://provider.test/live/stream.mpd',
        'is_vod' => false,
        'container_extension' => null,
    ]);

    [$url, $format] = $channel->getProxyUrl(withFormat: true);

    expect($format)->toBe('mpd')
        ->and($url)->toContain("/{$channel->id}.mpd");
});

test('a stream profile mpd format overrides the detected format from the channel URL', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'https://provider.test/live/stream.m3u8',
        'is_vod' => false,
    ]);

    $proxyUrl = $channel->getProxyUrl(profileFormat: 'mpd');

    expect($proxyUrl)->toContain("/{$channel->id}.mpd")
        ->and($proxyUrl)->not->toContain("/{$channel->id}.m3u8");
});

/**
 * Call the protected buildProxyUrl() method via reflection, same approach
 * used for M3uProxyService's other protected methods in
 * StreamProfileResolverFormatTest.
 */
function callBuildProxyUrl(M3uProxyService $service, string $streamId, string $format): string
{
    $method = new ReflectionMethod($service, 'buildProxyUrl');

    return $method->invoke($service, $streamId, $format);
}

describe('M3uProxyService::buildProxyUrl() dash routing', function () {
    beforeEach(function () {
        config(['proxy.m3u_proxy_host' => 'http://127.0.0.1', 'proxy.m3u_proxy_port' => 19999]);
    });

    test('mpd format builds a /dash/{stream_id}/manifest.mpd url', function () {
        $url = callBuildProxyUrl(app(M3uProxyService::class), 'stream123', 'mpd');

        expect($url)->toContain('/dash/stream123/manifest.mpd')
            ->and($url)->not->toContain('/hls/')
            ->and($url)->not->toContain('/stream/stream123');
    });

    test('dash format alias also builds a /dash/{stream_id}/manifest.mpd url', function () {
        $url = callBuildProxyUrl(app(M3uProxyService::class), 'stream123', 'dash');

        expect($url)->toContain('/dash/stream123/manifest.mpd');
    });

    test('hls format is unaffected by the dash branch (regression guard)', function () {
        $url = callBuildProxyUrl(app(M3uProxyService::class), 'stream123', 'hls');

        expect($url)->toContain('/hls/stream123/playlist.m3u8')
            ->and($url)->not->toContain('/dash/');
    });

    test('an unrecognised format still falls back to the direct stream route (regression guard)', function () {
        $url = callBuildProxyUrl(app(M3uProxyService::class), 'stream123', 'ts');

        expect($url)->toContain('/stream/stream123')
            ->and($url)->not->toContain('/dash/')
            ->and($url)->not->toContain('/hls/');
    });
});
