<?php

use App\Enums\ImageProfile;
use App\Http\Controllers\LogoProxyController;
use App\Services\LogoCacheService;
use App\Settings\GeneralSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

function svgBytes(): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';
}

function pngBytes(): string
{
    // Minimal valid 1x1 PNG so getimagesizefromstring recognises it.
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
}

function proxyPathFor(string $url): string
{
    $encoded = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    $filename = LogoCacheService::buildProxyFilename($url);

    return "/logo-proxy/{$encoded}/{$filename}";
}

it('serves a remote logo when Content-Type is image/*', function () {
    $remoteUrl = 'https://example.com/with-content-type.png';

    Http::fake([
        $remoteUrl => Http::response(pngBytes(), 200, ['Content-Type' => 'image/png']),
    ]);

    $response = $this->get(proxyPathFor($remoteUrl));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('image/');
});

it('serves a remote logo when the response has no Content-Type header', function () {
    // Reproduces the bug: some EPG icon hosts (e.g. iptv-epg.org) return valid
    // image bytes with no Content-Type header. The proxy should sniff the body
    // and serve them instead of falling back to the placeholder.
    $remoteUrl = 'https://example.com/no-content-type';

    Http::fake([
        $remoteUrl => Http::response(pngBytes(), 200),
    ]);

    $response = $this->get(proxyPathFor($remoteUrl));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('image/');
});

it('serves a remote logo when Content-Type is a generic non-image type but bytes are an image', function () {
    $remoteUrl = 'https://example.com/octet-stream';

    Http::fake([
        $remoteUrl => Http::response(pngBytes(), 200, ['Content-Type' => 'application/octet-stream']),
    ]);

    $response = $this->get(proxyPathFor($remoteUrl));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('image/');
});

it('serves a remote SVG logo when the response has no Content-Type header', function () {
    $remoteUrl = 'https://example.com/logo.svg';

    Http::fake([
        $remoteUrl => Http::response(svgBytes(), 200),
    ]);

    $response = $this->get(proxyPathFor($remoteUrl));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('image/svg');
});

it('strips script tags and event handlers from proxied SVG logos', function () {
    $remoteUrl = 'https://example.com/xss.svg';
    $maliciousSvg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect onclick="evil()" width="10" height="10"/></svg>';

    Http::fake([
        $remoteUrl => Http::response($maliciousSvg, 200, ['Content-Type' => 'image/svg+xml']),
    ]);

    $response = $this->get(proxyPathFor($remoteUrl));

    $response->assertOk();
    $response->assertDontSee('<script>', false);
    $response->assertDontSee('onclick', false);
    expect($response->headers->get('Content-Type'))->toStartWith('image/svg');
});

it('falls back to the placeholder when the body is not an image', function () {
    $remoteUrl = 'https://example.com/not-an-image';

    Http::fake([
        $remoteUrl => Http::response('<html>not an image</html>', 200),
    ]);

    $response = $this->get(proxyPathFor($remoteUrl));

    // Placeholder still returns 200 — assert it served the local placeholder
    // bytes rather than the HTML payload.
    $response->assertOk();
    $response->assertDontSee('not an image');
});

it('does not follow a redirect from a public host to a private address', function () {
    // GHSA-jmr6-rmrq-f4wg / GHSA-cf47-h3fh-m4pq: the first URL passes the
    // private network guard, so the redirect target must be checked too.
    // IP literals (TEST-NET-2 is public per PHP's filter flags) avoid DNS.
    Http::preventStrayRequests();
    Http::fake([
        'http://198.51.100.1/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal.png']),
        'http://127.0.0.1/*' => Http::response(pngBytes(), 200, ['Content-Type' => 'image/png']),
    ]);

    $response = $this->get(proxyPathFor('http://198.51.100.1/logo.png'));

    // Placeholder (1 day cache) rather than a proxied logo (30 days).
    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('max-age=86400')
        ->and(Storage::disk('local')->files(LogoCacheService::CACHE_DIRECTORY))->toBeEmpty();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '127.0.0.1'));
});

it('follows a redirect between public hosts', function () {
    Http::preventStrayRequests();
    Http::fake([
        'http://198.51.100.1/*' => Http::response('', 302, ['Location' => 'http://198.51.100.2/logo.png']),
        'http://198.51.100.2/*' => Http::response(pngBytes(), 200, ['Content-Type' => 'image/png']),
    ]);

    $response = $this->get(proxyPathFor('http://198.51.100.1/logo.png'));

    $response->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('max-age=2592000');
});

function bigJpegBytes(int $width = 1200, int $height = 800): string
{
    $image = imagecreatetruecolor($width, $height);
    // Some visual noise so the encoder produces a non-trivial payload that
    // clearly shrinks when downscaled.
    for ($i = 0; $i < 400; $i++) {
        $colour = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
        imagefilledrectangle(
            $image,
            random_int(0, $width),
            random_int(0, $height),
            random_int(0, $width),
            random_int(0, $height),
            $colour
        );
    }
    ob_start();
    imagejpeg($image, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

function cachedLogoFiles(): array
{
    return collect(Storage::disk('local')->files(LogoCacheService::CACHE_DIRECTORY))
        ->reject(fn (string $file): bool => str_ends_with($file, '.meta.json'))
        ->map(fn (string $file): string => basename($file))
        ->values()
        ->all();
}

function imageWidthOf(string $bytes): int
{
    return getimagesizefromstring($bytes)[0];
}

it('stores only the downscaled copy when a profile is requested', function () {
    $remoteUrl = 'https://example.com/big-poster.jpg';
    $original = bigJpegBytes(1600, 900);

    Http::fake([
        $remoteUrl => Http::response($original, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $resized = $this->get(proxyPathFor($remoteUrl).'?p=poster');
    $resized->assertOk();

    $body = $resized->streamedContent();
    $baseName = LogoCacheService::cacheKeyForUrl($remoteUrl);

    expect(strlen($body))->toBeLessThan(strlen($original))
        ->and(imageWidthOf($body))->toBe(600)
        ->and(cachedLogoFiles())->toBe(["{$baseName}@w600.jpg"]);

    // Served from the cached copy with no further outbound fetch.
    Http::fake([
        $remoteUrl => Http::response('should-not-be-called', 500),
    ]);
    $again = $this->get(proxyPathFor($remoteUrl).'?p=poster');
    $again->assertOk();
    expect($again->streamedContent())->toBe($body);
});

it('stores a single original when the source is already within the profile width', function () {
    $remoteUrl = 'https://example.com/small-headshot.jpg';
    $original = bigJpegBytes(280, 420);

    Http::fake([
        $remoteUrl => Http::response($original, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $response = $this->get(proxyPathFor($remoteUrl).'?p=photo');

    $response->assertOk();
    expect($response->streamedContent())->toBe($original)
        ->and(cachedLogoFiles())->toBe([LogoCacheService::cacheKeyForUrl($remoteUrl).'.jpg']);

    Http::fake([
        $remoteUrl => Http::response('should-not-be-called', 500),
    ]);
    expect($this->get(proxyPathFor($remoteUrl).'?p=photo')->streamedContent())->toBe($original);
});

it('snaps a legacy ?w= hint to the nearest profile', function (int $requested, int $stored) {
    $remoteUrl = "https://example.com/legacy-{$requested}.jpg";

    Http::fake([
        $remoteUrl => Http::response(bigJpegBytes(1600, 900), 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $response = $this->get(proxyPathFor($remoteUrl)."?w={$requested}");

    $response->assertOk();
    expect(imageWidthOf($response->streamedContent()))->toBe($stored)
        ->and(cachedLogoFiles())->toBe([LogoCacheService::cacheKeyForUrl($remoteUrl)."@w{$stored}.jpg"]);
})->with([
    'poster' => [550, 600],
    'backdrop' => [1000, 1280],
    'photo' => [250, 300],
]);

it('downscales an already cached original locally without refetching it', function () {
    $remoteUrl = 'https://example.com/cached-backdrop.jpg';

    Http::fake([
        $remoteUrl => Http::response(bigJpegBytes(1600, 900), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $this->get(proxyPathFor($remoteUrl))->assertOk();

    Http::fake([
        $remoteUrl => Http::response('should-not-be-called', 500),
    ]);
    $response = $this->get(proxyPathFor($remoteUrl).'?p=poster');

    $response->assertOk();
    expect(imageWidthOf($response->streamedContent()))->toBe(600);
    Http::assertNothingSent();
});

it('caches a new copy when a profile width changes and leaves the old one for cleanup', function (int $newWidth) {
    $remoteUrl = "https://example.com/width-change-{$newWidth}.jpg";
    $baseName = LogoCacheService::cacheKeyForUrl($remoteUrl);

    Http::fake([
        $remoteUrl => Http::response(bigJpegBytes(1600, 900), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $this->get(proxyPathFor($remoteUrl).'?p=poster')->assertOk();

    $settings = app(GeneralSettings::class);
    $settings->image_poster_width = $newWidth;
    $settings->save();

    $response = $this->get(proxyPathFor($remoteUrl).'?p=poster');

    $response->assertOk();
    expect(imageWidthOf($response->streamedContent()))->toBe($newWidth)
        ->and(cachedLogoFiles())->toEqualCanonicalizing(["{$baseName}@w600.jpg", "{$baseName}@w{$newWidth}.jpg"]);
})->with([
    'lowered' => [400],
    'raised' => [900],
]);

it('keeps a copy for every profile a url is used as', function () {
    $remoteUrl = 'https://example.com/poster-and-backdrop.jpg';
    $baseName = LogoCacheService::cacheKeyForUrl($remoteUrl);

    Http::fake([
        $remoteUrl => Http::response(bigJpegBytes(1600, 900), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $this->get(proxyPathFor($remoteUrl).'?p=backdrop')->assertOk();
    $poster = $this->get(proxyPathFor($remoteUrl).'?p=poster');

    $poster->assertOk();
    expect(imageWidthOf($poster->streamedContent()))->toBe(600)
        ->and(cachedLogoFiles())->toEqualCanonicalizing(["{$baseName}@w600.jpg", "{$baseName}@w1280.jpg"]);
});

it('refetches the original once optimization is turned off', function () {
    $remoteUrl = 'https://example.com/turned-off.jpg';
    $original = bigJpegBytes(1600, 900);

    Http::fake([
        $remoteUrl => Http::response($original, 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $this->get(proxyPathFor($remoteUrl).'?p=poster')->assertOk();

    config()->set('proxy.image_resize_enabled', false);

    $response = $this->get(proxyPathFor($remoteUrl).'?p=poster');

    $response->assertOk();
    $baseName = LogoCacheService::cacheKeyForUrl($remoteUrl);
    expect($response->streamedContent())->toBe($original)
        ->and(cachedLogoFiles())->toEqualCanonicalizing(["{$baseName}.jpg", "{$baseName}@w600.jpg"]);
});

it('serves the original when image optimization is disabled by config', function () {
    config()->set('proxy.image_resize_enabled', false);

    $remoteUrl = 'https://example.com/no-resize.jpg';
    $original = bigJpegBytes(1600, 900);

    Http::fake([
        $remoteUrl => Http::response($original, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $response = $this->get(proxyPathFor($remoteUrl).'?p=poster');

    $response->assertOk();
    expect(strlen($response->streamedContent()))->toBe(strlen($original));
});

it('never rasterises an SVG even when a profile is requested', function () {
    $remoteUrl = 'https://example.com/vector.svg';

    Http::fake([
        $remoteUrl => Http::response(svgBytes(), 200, ['Content-Type' => 'image/svg+xml']),
    ]);

    $response = $this->get(proxyPathFor($remoteUrl).'?p=poster');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('image/svg');
});

it('generateProxyUrl bakes in a profile name whenever one is given', function () {
    $url = 'https://example.com/poster.jpg';

    expect(LogoProxyController::generateProxyUrl($url))
        ->not->toContain('?')
        ->and(LogoProxyController::generateProxyUrl($url, profile: ImageProfile::Poster))
        ->toEndWith('?p=poster');

    // Kept while optimization is off, so toggling it never changes artwork urls.
    config()->set('proxy.image_resize_enabled', false);

    expect(LogoProxyController::generateProxyUrl($url, profile: ImageProfile::Poster))->toEndWith('?p=poster');
});

it('clears the original, its size variants and metadata for a url', function () {
    $remoteUrl = 'https://example.com/clear-me.jpg';

    Http::fake([
        $remoteUrl => Http::response(bigJpegBytes(1600, 900), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $this->get(proxyPathFor($remoteUrl))->assertOk();
    $this->get(proxyPathFor($remoteUrl).'?p=poster')->assertOk();
    $this->get(proxyPathFor($remoteUrl).'?p=backdrop')->assertOk();

    expect(cachedLogoFiles())->toHaveCount(3);

    LogoCacheService::clearByUrl($remoteUrl);

    expect(Storage::disk('local')->files(LogoCacheService::CACHE_DIRECTORY))->toBeEmpty();
});

it('keys media server image urls by integration, item and type rather than the signed url', function () {
    $first = 'http://localhost:36400/media-server/3/image/abc123/Backdrop?v=1&signature=aaa';
    $second = 'http://192.168.1.20:36400/media-server/3/image/abc123/Backdrop?v=2&signature=bbb';

    expect(LogoCacheService::sourceKeyForUrl($first))
        ->toBe('media-server://3/abc123/Backdrop')
        ->toBe(LogoCacheService::sourceKeyForUrl($second))
        ->and(LogoCacheService::sourceKeyForUrl('http://localhost/media-server/3/image/abc123'))
        ->toBe('media-server://3/abc123/Primary')
        ->and(LogoCacheService::sourceKeyForUrl('https://example.com/poster.jpg'))
        ->toBe('https://example.com/poster.jpg');
});
