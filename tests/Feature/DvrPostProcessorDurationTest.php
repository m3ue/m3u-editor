<?php

/**
 * Tests for DvrPostProcessorService::durationFromManifest().
 *
 * Cancelled recordings must report the actually captured duration (sum of
 * EXTINF segment durations from the HLS manifest), NOT the originally
 * scheduled programme length.
 */

declare(strict_types=1);

use App\Services\DvrPostProcessorService;

it('sums EXTINF segment durations from an HLS manifest', function (): void {
    $manifest = <<<'M3U8'
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-TARGETDURATION:10
#EXTINF:9.984,
seg-001.ts
#EXTINF:10.000,
seg-002.ts
#EXTINF:5.500,Some Title
seg-003.ts
#EXT-X-ENDLIST
M3U8;

    $path = tempnam(sys_get_temp_dir(), 'm3u8_');
    file_put_contents($path, $manifest);

    $service = app(DvrPostProcessorService::class);

    $reflection = new ReflectionMethod($service, 'durationFromManifest');
    $reflection->setAccessible(true);

    $duration = $reflection->invoke($service, $path);

    @unlink($path);

    // 9.984 + 10.000 + 5.500 = 25.484 → rounded to 25
    expect($duration)->toBe(25);
});

it('returns null when the manifest has no EXTINF entries', function (): void {
    $manifest = <<<'M3U8'
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-ENDLIST
M3U8;

    $path = tempnam(sys_get_temp_dir(), 'm3u8_');
    file_put_contents($path, $manifest);

    $service = app(DvrPostProcessorService::class);

    $reflection = new ReflectionMethod($service, 'durationFromManifest');
    $reflection->setAccessible(true);

    $duration = $reflection->invoke($service, $path);

    @unlink($path);

    expect($duration)->toBeNull();
});

it('returns null when the manifest file does not exist', function (): void {
    $service = app(DvrPostProcessorService::class);

    $reflection = new ReflectionMethod($service, 'durationFromManifest');
    $reflection->setAccessible(true);

    expect($reflection->invoke($service, '/no/such/manifest.m3u8'))->toBeNull();
});
