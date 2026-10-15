<?php

/**
 * Regression tests for Jellyfin 12 compatibility (issue #1618): Jellyfin 12
 * removed the legacy authorization mechanisms (the X-Emby-Token header and
 * the lowercase api_key query parameter). Jellyfin integrations must use the
 * Authorization header with the MediaBrowser scheme for JSON API calls and
 * the ApiKey query parameter for raw media URLs consumed by external players
 * and proxies. Emby keeps its legacy mechanisms unchanged.
 */

use App\Models\MediaServerIntegration;
use App\Services\EmbyJellyfinService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

function jellyfin12Integration(array $attributes = []): MediaServerIntegration
{
    return MediaServerIntegration::factory()->create(array_merge([
        'type' => 'jellyfin',
        'host' => 'jellyfin12.local',
        'port' => 8096,
        'ssl' => false,
        'api_key' => 'jf-token',
        'enabled' => true,
    ], $attributes));
}

it('tests connections against Jellyfin 12 servers reporting the new 12.x version scheme', function () {
    Http::fake([
        'http://jellyfin12.local:8096/System/Info/Public' => Http::response([
            'ServerName' => 'jf12',
            'Version' => '12.0.0',
        ]),
    ]);

    $result = EmbyJellyfinService::make(jellyfin12Integration())->testConnection();

    expect($result)->toBe([
        'success' => true,
        'message' => 'Connection successful',
        'server_name' => 'jf12',
        'version' => '12.0.0',
    ]);
});

it('authenticates Jellyfin API calls with the MediaBrowser Authorization header instead of the removed X-Emby-Token header', function () {
    Http::fake([
        'http://jellyfin12.local:8096/Library/VirtualFolders' => Http::response([
            ['ItemId' => 'lib-1', 'Name' => 'Movies', 'CollectionType' => 'movies'],
        ]),
    ]);

    $libraries = EmbyJellyfinService::make(jellyfin12Integration())->fetchLibraries();

    expect($libraries)->first()->id->toBe('lib-1');

    Http::assertSent(function (ClientRequest $request) {
        $authorization = $request->header('Authorization')[0] ?? '';

        return str_starts_with($authorization, 'MediaBrowser Token="jf-token"')
            && str_contains($authorization, 'Client="m3u editor"')
            && ! $request->hasHeader('X-Emby-Token');
    });
});

it('authenticates Jellyfin media URLs with the ApiKey query parameter instead of the removed api_key parameter', function () {
    $service = EmbyJellyfinService::make(jellyfin12Integration());

    $streamUrl = $service->getDirectStreamUrl(Request::create('/stream.ts'), 'item-1', 'mkv');
    $imageUrl = $service->getDirectImageUrl('item-1', 'Primary');

    expect($streamUrl)
        ->toContain('ApiKey=jf-token')
        ->not->toContain('api_key=');

    expect($imageUrl)
        ->toContain('ApiKey=jf-token')
        ->not->toContain('api_key=');
});

it('authenticates Jellyfin subtitle URLs with the ApiKey query parameter', function () {
    Http::fake([
        'http://jellyfin12.local:8096/*' => Http::response([
            'Items' => [
                [
                    'Id' => 'item-2',
                    'MediaSources' => [['Id' => 'ms-1']],
                    'MediaStreams' => [
                        ['Index' => 0, 'Type' => 'Video'],
                        ['Index' => 1, 'Type' => 'Subtitle', 'IsTextSubtitleStream' => true, 'Codec' => 'srt', 'Language' => 'eng'],
                    ],
                ],
            ],
        ]),
    ]);

    $result = EmbyJellyfinService::make(jellyfin12Integration())->getSubtitleUrl('item-2');

    expect($result)
        ->not->toBeNull()
        ->and($result['url'])
        ->toContain('ApiKey=jf-token')
        ->not->toContain('api_key=');
});

it('authenticates the Jellyfin stream byte-size probe through the Authorization header alone', function () {
    Http::fake([
        'http://jellyfin12.local:8096/Videos/*' => Http::response('', 200, ['Content-Length' => '1024']),
        'http://jellyfin12.local:8096/*' => Http::response([
            'Items' => [
                ['Id' => 'item-3', 'RunTimeTicks' => 60_000_0000, 'MediaStreams' => [['Index' => 0, 'Type' => 'Video']]],
            ],
        ]),
    ]);

    $meta = EmbyJellyfinService::make(jellyfin12Integration())->getStreamByteSize('item-3');

    expect($meta)->not->toBeNull()
        ->and($meta['bytes'])->toBe(1024);

    Http::assertSent(function (ClientRequest $request) {
        if ($request->method() !== 'HEAD') {
            return false;
        }

        $authorization = $request->header('Authorization')[0] ?? '';

        return str_starts_with($authorization, 'MediaBrowser Token="jf-token"')
            && ! $request->hasHeader('X-Emby-Token')
            && ! str_contains($request->url(), 'ApiKey=')
            && ! str_contains($request->url(), 'api_key=');
    });
});

it('keeps Emby on the legacy X-Emby-Token header and api_key query parameter', function () {
    Http::fake([
        'http://emby.local:8096/*' => Http::response([
            ['ItemId' => 'lib-1', 'Name' => 'Movies', 'CollectionType' => 'movies'],
        ]),
    ]);

    $emby = MediaServerIntegration::factory()->create([
        'type' => 'emby',
        'host' => 'emby.local',
        'port' => 8096,
        'ssl' => false,
        'api_key' => 'emby-token',
        'enabled' => true,
    ]);

    $service = EmbyJellyfinService::make($emby);
    $service->fetchLibraries();

    Http::assertSent(function (ClientRequest $request) {
        return $request->hasHeader('X-Emby-Token', 'emby-token')
            && ! $request->hasHeader('Authorization');
    });

    $streamUrl = $service->getDirectStreamUrl(Request::create('/stream.ts'), 'item-1', 'mkv');
    $imageUrl = $service->getDirectImageUrl('item-1', 'Primary');

    expect($streamUrl)
        ->toContain('api_key=emby-token')
        ->not->toContain('ApiKey=');

    expect($imageUrl)
        ->toContain('api_key=emby-token')
        ->not->toContain('ApiKey=');
});
