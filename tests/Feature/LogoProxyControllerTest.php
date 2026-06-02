<?php

use App\Services\LogoCacheService;
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
