<?php

use App\Models\Channel;
use App\Services\VodResolutionExtractor;

it('returns probed resolution from stream stats and ignores title parsing', function () {
    $channel = new Channel([
        'title' => 'Movie.Name.2024.1080p.BluRay',
        'stream_stats' => ['resolution' => 2160],
    ]);

    expect(VodResolutionExtractor::extract($channel))
        ->toBe(['resolution' => 2160, 'source' => 'probed']);
});

it('parses resolution from the title', function () {
    $channel = new Channel(['title' => 'Movie.Name.2024.2160p.UHD.BluRay']);

    expect(VodResolutionExtractor::extract($channel))
        ->toBe(['resolution' => 2160, 'source' => 'title']);
});

it('returns null when no resolution token is present', function () {
    $channel = new Channel(['title' => 'Movie Name (2024)']);

    expect(VodResolutionExtractor::extract($channel))->toBeNull();
});

it('parses 4K from the name', function () {
    $channel = new Channel(['name' => 'Movie.4K-UHD.mkv']);

    expect(VodResolutionExtractor::extract($channel))
        ->toBe(['resolution' => 2160, 'source' => 'name']);
});

it('parses resolution from the url', function () {
    $channel = new Channel(['url' => 'https://cdn.example.com/movie/stream-1080p/index.m3u8']);

    expect(VodResolutionExtractor::extract($channel))
        ->toBe(['resolution' => 1080, 'source' => 'url']);
});

it('returns null when all fields are empty', function () {
    $channel = new Channel([]);

    expect(VodResolutionExtractor::extract($channel))->toBeNull();
});
