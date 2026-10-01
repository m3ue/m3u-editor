<?php

use App\Http\Controllers\MediaServerProxyController;
use App\Models\MediaServerIntegration;
use App\Services\LogoCacheService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->integration = MediaServerIntegration::factory()->create(['type' => 'jellyfin']);
});

function mediaServerJpeg(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    for ($i = 0; $i < 400; $i++) {
        $colour = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
        imagefilledrectangle($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $colour);
    }
    ob_start();
    imagejpeg($image, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

function mediaServerImagePath(MediaServerIntegration $integration, string $itemId, string $imageType): string
{
    $url = MediaServerProxyController::generateImageProxyUrl($integration->id, $itemId, $imageType);

    return parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
}

it('caches media server artwork on disk at its profile width instead of in the cache store', function (string $imageType, int $expectedWidth) {
    Http::fake([
        '*/Items/*' => Http::response(mediaServerJpeg(2000, 3000), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    Cache::spy();

    $response = $this->get(mediaServerImagePath($this->integration, 'item1', $imageType));

    $response->assertOk();
    expect(getimagesizefromstring($response->streamedContent())[0])->toBe($expectedWidth)
        ->and($response->headers->get('X-Proxied-From'))->toBe('MediaServer')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=86400');

    $sourceKey = LogoCacheService::mediaServerSourceKey($this->integration->id, 'item1', $imageType);
    expect(Storage::disk('local')->exists(LogoCacheService::variantFileFor($sourceKey, 'jpg', $expectedWidth)))->toBeTrue();

    Cache::shouldNotHaveReceived('put');
})->with([
    'poster' => ['Primary', 600],
    'backdrop' => ['Backdrop', 1280],
    'title logo' => ['Logo', 800],
]);

it('serves a fresh cached image without contacting the media server', function () {
    Http::fake([
        '*/Items/*' => Http::response(mediaServerJpeg(2000, 3000), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $path = mediaServerImagePath($this->integration, 'item1', 'Primary');
    $first = $this->get($path)->streamedContent();

    Http::fake([
        '*/Items/*' => Http::response('should-not-be-called', 500),
    ]);
    $second = $this->get($path);

    $second->assertOk();
    expect($second->streamedContent())->toBe($first);
});

it('shares one cache entry across url versions and hosts for the same item', function () {
    Http::fake([
        '*/Items/*' => Http::response(mediaServerJpeg(2000, 3000), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $this->get(mediaServerImagePath($this->integration, 'item1', 'Primary'))->assertOk();
    LogoCacheService::clearByUrl('http://192.168.1.20:36400/media-server/'.$this->integration->id.'/image/item1/Primary?v=9&signature=other');

    expect(Storage::disk('local')->files(LogoCacheService::CACHE_DIRECTORY))->toBeEmpty();
});

it('refetches a cached image older than a day', function () {
    Http::fake([
        '*/Items/*' => Http::sequence()
            ->push(mediaServerJpeg(2000, 3000), 200, ['Content-Type' => 'image/jpeg'])
            ->push(mediaServerJpeg(2000, 3000), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $path = mediaServerImagePath($this->integration, 'item1', 'Primary');
    $this->get($path)->assertOk();

    $sourceKey = LogoCacheService::mediaServerSourceKey($this->integration->id, 'item1', 'Primary');
    $variant = LogoCacheService::variantFileFor($sourceKey, 'jpg', 600);
    touch(Storage::disk('local')->path($variant), now()->subHours(25)->timestamp);

    $this->get($path)->assertOk();

    Http::assertSentCount(2);
    expect(Storage::disk('local')->lastModified($variant))->toBeGreaterThan(now()->subHour()->timestamp);
});

it('serves an expired cached image when the media server is unavailable', function (Closure $failure) {
    Http::fake([
        '*/Items/*' => Http::response(mediaServerJpeg(2000, 3000), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $path = mediaServerImagePath($this->integration, 'item1', 'Primary');
    $cached = $this->get($path)->streamedContent();

    $sourceKey = LogoCacheService::mediaServerSourceKey($this->integration->id, 'item1', 'Primary');
    touch(Storage::disk('local')->path(LogoCacheService::variantFileFor($sourceKey, 'jpg', 600)), now()->subHours(25)->timestamp);

    Http::fake(['*/Items/*' => $failure]);
    $response = $this->get($path);

    $response->assertOk();
    expect($response->streamedContent())->toBe($cached);
})->with([
    'error response' => [fn () => fn () => Http::response('down', 500)],
    'connection failure' => [fn () => fn () => throw new ConnectionException('Connection refused')],
]);

it('serves refreshed artwork once an expired full-size copy is refetched', function () {
    Http::fake([
        '*/Items/*' => Http::sequence()
            ->push(mediaServerJpeg(400, 600), 200, ['Content-Type' => 'image/jpeg'])
            ->push(mediaServerJpeg(2000, 3000), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $path = mediaServerImagePath($this->integration, 'item1', 'Primary');
    $this->get($path)->assertOk();

    $sourceKey = LogoCacheService::mediaServerSourceKey($this->integration->id, 'item1', 'Primary');
    $original = LogoCacheService::cacheFileForUrl($sourceKey, 'jpg');
    expect(Storage::disk('local')->exists($original))->toBeTrue();
    touch(Storage::disk('local')->path($original), now()->subHours(25)->timestamp);

    $response = $this->get($path);

    $response->assertOk();
    expect(getimagesizefromstring($response->streamedContent())[0])->toBe(600);
});

it('asks Emby/Jellyfin for the profile width and caches a pre-sized image as-is', function () {
    $sized = mediaServerJpeg(600, 900);
    Http::fake([
        '*/Items/*' => Http::response($sized, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $response = $this->get(mediaServerImagePath($this->integration, 'item1', 'Primary'));

    $response->assertOk();
    expect($response->streamedContent())->toBe($sized);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/Items/item1/Images/Primary')
        && str_contains($request->url(), 'maxWidth=600'));
});

it('asks Plex for the profile width through its photo transcoder', function () {
    $plex = MediaServerIntegration::factory()->create(['type' => 'plex']);
    Http::fake([
        '*/photo/*' => Http::response(mediaServerJpeg(1280, 720), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $this->get(mediaServerImagePath($plex, '42', 'Backdrop'))->assertOk();

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/photo/:/transcode')
            && $query['width'] === '1280'
            && $query['url'] === '/library/metadata/42/art';
    });
});

it('falls back to the original and resizes it locally when the server rejects resizing', function () {
    Http::fake([
        '*maxWidth=*' => Http::response('bad request', 400),
        '*/Items/*' => Http::response(mediaServerJpeg(2000, 3000), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $response = $this->get(mediaServerImagePath($this->integration, 'item1', 'Primary'));

    $response->assertOk();
    expect(getimagesizefromstring($response->streamedContent())[0])->toBe(600);
    Http::assertSentCount(2);
});

it('fetches the original when image optimization is off', function () {
    config()->set('proxy.image_resize_enabled', false);
    Http::fake([
        '*/Items/*' => Http::response(mediaServerJpeg(2000, 3000), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $response = $this->get(mediaServerImagePath($this->integration, 'item1', 'Primary'));

    $response->assertOk();
    expect(getimagesizefromstring($response->streamedContent())[0])->toBe(2000);
    Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), 'maxWidth'));
});
