<?php

/**
 * Which of the proxy's open streams a DVR recording reads from, and at which URL.
 */

use App\Models\StreamProfile;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'proxy.m3u_proxy_host' => 'http://localhost',
        'proxy.m3u_proxy_port' => 8765,
        'proxy.m3u_proxy_token' => 'test-token',
    ]);
});

/**
 * @param  array<string, mixed>  ...$streams
 */
function dvrActiveStreamUrlFor(array ...$streams): ?string
{
    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => array_map(fn (array $stream): array => [
                'stream_id' => $stream['stream_id'] ?? 'abc',
                'stream_type' => $stream['stream_type'] ?? 'Live Continuous',
                'url' => $stream['url'] ?? 'http://provider.test/live/1.ts',
                'metadata' => ['original_channel_id' => 7, ...($stream['metadata'] ?? [])],
            ], $streams),
        ]),
    ]);

    $url = app(M3uProxyService::class)->getActiveStreamUrlForChannel(7);

    return $url === null ? null : parse_url($url, PHP_URL_PATH);
}

it('uses the MPEG-TS endpoint for a direct stream', function () {
    expect(dvrActiveStreamUrlFor([]))->toEndWith('/stream/abc');
});

it('uses a stream opened through a wrapper playlist', function () {
    expect(dvrActiveStreamUrlFor(['metadata' => ['original_playlist_uuid' => 'a-custom-playlist']]))
        ->toEndWith('/stream/abc');
});

it('uses the HLS playlist for a direct HLS stream', function () {
    expect(dvrActiveStreamUrlFor(['stream_type' => 'HLS', 'url' => 'http://provider.test/live/1.m3u8']))
        ->toEndWith('/hls/abc/playlist.m3u8');
});

it('follows the stream profile format for a transcoded stream', function (string $format, ?string $expected) {
    $profile = StreamProfile::factory()->create(['format' => $format]);

    $url = dvrActiveStreamUrlFor([
        'stream_type' => 'Transcoding',
        'url' => 'http://provider.test/live/1.m3u8',
        'metadata' => ['transcoding' => 'true', 'profile_id' => $profile->id],
    ]);

    $expected === null ? expect($url)->toBeNull() : expect($url)->toEndWith($expected);
})->with([
    'HLS' => ['m3u8', '/hls/abc/playlist.m3u8'],
    'MPEG-TS' => ['ts', '/stream/abc'],
    'DASH' => ['mpd', null],
]);

it('never records from a catchup stream of the same channel', function () {
    expect(dvrActiveStreamUrlFor(['metadata' => ['timeshift' => 'true']]))->toBeNull();
});

it('prefers an untranscoded stream', function () {
    $profile = StreamProfile::factory()->create(['format' => 'ts']);

    expect(dvrActiveStreamUrlFor(
        ['stream_id' => 'low', 'metadata' => ['transcoding' => 'true', 'profile_id' => $profile->id]],
        ['stream_id' => 'source'],
    ))->toEndWith('/stream/source');
});

it('returns null when the proxy has nothing open for the channel', function () {
    expect(dvrActiveStreamUrlFor())->toBeNull();
});
