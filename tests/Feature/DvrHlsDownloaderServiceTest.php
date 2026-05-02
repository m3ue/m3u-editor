<?php

/**
 * Tests for DvrHlsDownloaderService
 *
 * Covers:
 * - download() fetches manifest + all segments via HTTP and writes them locally
 * - download() throws when proxy_network_id is missing
 * - download() throws when manifest fetch fails
 * - download() throws when manifest is empty / has no segments
 * - download() throws when a segment fetch fails
 * - Auth header is applied when an API token is configured
 */

use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\User;
use App\Services\DvrHlsDownloaderService;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeRecording(array $overrides = []): DvrRecording
{
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()->enabled()->for($user)->for($playlist)->create([
        'storage_disk' => 'dvr',
    ]);

    return DvrRecording::factory()
        ->for($setting, 'dvrSetting')
        ->for($user)
        ->create(array_merge([
            'proxy_network_id' => 'net-abc',
            'season' => null,
            'episode' => null,
        ], $overrides));
}

function manifestBody(): string
{
    return "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:6\n#EXTINF:5.0,\nlive000000.ts\n#EXTINF:5.0,\nlive000001.ts\n#EXT-X-ENDLIST\n";
}

beforeEach(function () {
    Queue::fake();
    Storage::fake('dvr');

    $proxy = Mockery::mock(M3uProxyService::class);
    $proxy->shouldReceive('getApiBaseUrl')->andReturn('http://proxy.test:38085');
    $proxy->shouldReceive('getApiToken')->andReturn(null);
    app()->instance(M3uProxyService::class, $proxy);
});

it('downloads the manifest and all listed segments to the local dvr disk', function () {
    Http::fake([
        'http://proxy.test:38085/broadcast/net-abc/live.m3u8' => Http::response(manifestBody(), 200),
        'http://proxy.test:38085/broadcast/net-abc/segment/live000000.ts' => Http::response('SEG0BYTES', 200),
        'http://proxy.test:38085/broadcast/net-abc/segment/live000001.ts' => Http::response('SEG1BYTES', 200),
    ]);

    $recording = makeRecording();

    $manifestPath = app(DvrHlsDownloaderService::class)->download($recording, 'dvr');

    expect($manifestPath)->toBe(Storage::disk('dvr')->path("live/{$recording->uuid}/live.m3u8"));
    expect(Storage::disk('dvr')->get("live/{$recording->uuid}/live.m3u8"))->toBe(manifestBody());
    expect(Storage::disk('dvr')->get("live/{$recording->uuid}/live000000.ts"))->toBe('SEG0BYTES');
    expect(Storage::disk('dvr')->get("live/{$recording->uuid}/live000001.ts"))->toBe('SEG1BYTES');
});

it('throws when the recording has no proxy_network_id', function () {
    $recording = makeRecording(['proxy_network_id' => null]);

    expect(fn () => app(DvrHlsDownloaderService::class)->download($recording, 'dvr'))
        ->toThrow(Exception::class, 'no proxy_network_id');
});

it('throws when the manifest request fails', function () {
    Http::fake([
        'http://proxy.test:38085/broadcast/net-abc/live.m3u8' => Http::response('Not found', 404),
        'http://proxy.test:38085/broadcast/net-abc/live.m3u8.tmp' => Http::response('Not found', 404),
    ]);

    $recording = makeRecording();

    expect(fn () => app(DvrHlsDownloaderService::class)->download($recording, 'dvr'))
        ->toThrow(Exception::class, 'could not be fetched after retries');
});

it('throws when the manifest contains no segments', function () {
    Http::fake([
        'http://proxy.test:38085/broadcast/net-abc/live.m3u8' => Http::response("#EXTM3U\n#EXT-X-ENDLIST\n", 200),
    ]);

    $recording = makeRecording();

    expect(fn () => app(DvrHlsDownloaderService::class)->download($recording, 'dvr'))
        ->toThrow(Exception::class, 'no segments');
});

it('throws when a segment fetch fails', function () {
    Http::fake([
        'http://proxy.test:38085/broadcast/net-abc/live.m3u8' => Http::response(manifestBody(), 200),
        'http://proxy.test:38085/broadcast/net-abc/segment/live000000.ts' => Http::response('SEG0', 200),
        'http://proxy.test:38085/broadcast/net-abc/segment/live000001.ts' => Http::response('gone', 404),
    ]);

    $recording = makeRecording();

    expect(fn () => app(DvrHlsDownloaderService::class)->download($recording, 'dvr'))
        ->toThrow(Exception::class, 'live000001.ts');
});

it('sends the X-API-Token header when the proxy has a configured token', function () {
    $proxy = Mockery::mock(M3uProxyService::class);
    $proxy->shouldReceive('getApiBaseUrl')->andReturn('http://proxy.test:38085');
    $proxy->shouldReceive('getApiToken')->andReturn('secret-token');
    app()->instance(M3uProxyService::class, $proxy);

    Http::fake([
        'http://proxy.test:38085/broadcast/net-abc/live.m3u8' => Http::response(manifestBody(), 200),
        'http://proxy.test:38085/broadcast/net-abc/segment/*' => Http::response('SEG', 200),
    ]);

    $recording = makeRecording();

    app(DvrHlsDownloaderService::class)->download($recording, 'dvr');

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-API-Token', 'secret-token'));
});
