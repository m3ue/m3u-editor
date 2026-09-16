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

it('prefers title_custom over title when both are set and the custom value carries a resolution', function () {
    // Regression: user-edited metadata should win over the base field so
    // resolution is extracted from the string the user actually wants scored.
    $channel = new Channel([
        'title' => 'Movie.Name.2024.720p.BluRay',
        'title_custom' => 'Movie.Name.2024.2160p.UHD.BluRay',
    ]);

    expect(VodResolutionExtractor::extract($channel))
        ->toBe(['resolution' => 2160, 'source' => 'title']);
});

it('prefers name_custom over name when name has no resolution but the custom override does', function () {
    $channel = new Channel([
        'name' => 'Movie (2024)',
        'name_custom' => 'Movie.4K-UHD.mkv',
    ]);

    expect(VodResolutionExtractor::extract($channel))
        ->toBe(['resolution' => 2160, 'source' => 'name']);
});

it('prefers url_custom over url when the base url has no resolution token', function () {
    $channel = new Channel([
        'url' => 'https://cdn.example.com/movie/stream/index.m3u8',
        'url_custom' => 'https://cdn.example.com/movie/stream-1080p/index.m3u8',
    ]);

    expect(VodResolutionExtractor::extract($channel))
        ->toBe(['resolution' => 1080, 'source' => 'url']);
});

it('falls back to the base field when the custom override is blank', function () {
    $channel = new Channel([
        'title' => 'Movie.1080p.BluRay',
        'title_custom' => '   ',
    ]);

    expect(VodResolutionExtractor::extract($channel))
        ->toBe(['resolution' => 1080, 'source' => 'title']);
});
