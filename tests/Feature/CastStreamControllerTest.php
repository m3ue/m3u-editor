<?php

use Illuminate\Support\Facades\Http;

it('rejects cast segment requests without source', function () {
    $this->get(route('cast.stream.segment'))
        ->assertUnprocessable();
});

it('rejects cast segment requests with invalid source urls', function () {
    $this->get(route('cast.stream.segment', [
        'source' => 'not-a-url',
    ]))
        ->assertUnprocessable();
});

it('proxies cast segment requests for allowed absolute urls', function () {
    Http::fake([
        'https://cdn.example.com/hls/abc123/segment.ts?token=123' => Http::response('segment-bytes', 200, [
            'Content-Type' => 'video/mp2t',
        ]),
    ]);

    $response = $this->get(route('cast.stream.segment', [
        'source' => 'https://cdn.example.com/hls/abc123/segment.ts?token=123',
    ]));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'video/mp2t');
    expect($response->getContent())->toBe('segment-bytes');
});

it('rewrites uri attributes and nested playlist entries when proxying playlist resources', function () {
    Http::fake([
        'https://cdn.example.com/hls/variant.m3u8' => Http::response(implode("\n", [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-KEY:METHOD=AES-128,URI="keys/key.bin"',
            '#EXT-X-MAP:URI="init.mp4"',
            '#EXTINF:10,',
            'segments/seg-001.ts',
        ]), 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Content-Length' => '999',
        ]),
    ]);

    $response = $this->get(route('cast.stream.segment', [
        'source' => 'https://cdn.example.com/hls/variant.m3u8',
    ]));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'application/vnd.apple.mpegurl');
    $response->assertHeaderMissing('Content-Length');
    $response->assertSee(route('cast.stream.segment', [
        'source' => 'https://cdn.example.com/hls/keys/key.bin',
    ]), false);
    $response->assertSee(route('cast.stream.segment', [
        'source' => 'https://cdn.example.com/hls/init.mp4',
    ]), false);
    $response->assertSee(route('cast.stream.segment', [
        'source' => 'https://cdn.example.com/hls/segments/seg-001.ts',
    ]), false);
});
