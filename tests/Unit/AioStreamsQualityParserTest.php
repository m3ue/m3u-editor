<?php

use App\Services\AioStreamsQualityParser;

beforeEach(function () {
    $this->parser = new AioStreamsQualityParser;
});

it('parses resolution, hdr, codec, and audio format from a stream title', function () {
    $parsed = $this->parser->parse([
        'name' => 'Movie.2024.2160p.HDR10.HEVC.Atmos',
    ]);

    expect($parsed['resolution'])->toBe(2160)
        ->and($parsed['hdr'])->toBeTrue()
        ->and($parsed['codec'])->toBe('hevc')
        ->and($parsed['audio_format'])->toBe('atmos');
});

it('parses cached status from behaviorHints', function () {
    $parsed = $this->parser->parse([
        'name' => 'Movie.1080p',
        'behaviorHints' => ['cached' => true],
    ]);

    expect($parsed['cached'])->toBeTrue();
});

it('filters out hdr streams when avoid_hdr preference is set', function () {
    $streams = [
        ['name' => 'Movie.2160p.HDR10'],
        ['name' => 'Movie.1080p'],
    ];

    $ranked = $this->parser->rank($streams, ['avoid_hdr' => true]);

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]['parsed']['hdr'])->toBeFalse();
});

it('ranks cached streams above uncached, then by resolution descending', function () {
    $streams = [
        ['name' => 'Movie.720p', 'behaviorHints' => ['cached' => false]],
        ['name' => 'Movie.2160p', 'behaviorHints' => ['cached' => false]],
        ['name' => 'Movie.1080p', 'behaviorHints' => ['cached' => true]],
    ];

    $ranked = $this->parser->rank($streams);

    expect($ranked[0]['parsed']['cached'])->toBeTrue()
        ->and($ranked[0]['parsed']['resolution'])->toBe(1080)
        ->and($ranked[1]['parsed']['resolution'])->toBe(2160)
        ->and($ranked[2]['parsed']['resolution'])->toBe(720);
});

it('prefers the behaviorHints filename extension when determining the container', function () {
    $parsed = $this->parser->parse([
        'name' => 'Movie.1080p',
        'url' => 'https://debrid.example.com/dl/ab12cd34ef56',
        'behaviorHints' => ['filename' => 'Movie.2024.1080p.WEB-DL.x264-GROUP.mkv'],
    ]);

    expect($parsed['container'])->toBe('mkv');
});

it('falls back to the stream URL extension for the container when no filename hint exists', function () {
    $parsed = $this->parser->parse([
        'name' => 'Movie.1080p',
        'url' => 'https://cdn.example.com/videos/movie.mp4',
    ]);

    expect($parsed['container'])->toBe('mp4');
});

it('falls back to a container keyword in the name/title when the URL is opaque', function () {
    $parsed = $this->parser->parse([
        'name' => 'Movie.1080p.WEB-DL.x264-GROUP.mkv',
        'url' => 'https://debrid.example.com/dl/ab12cd34ef56',
    ]);

    expect($parsed['container'])->toBe('mkv');
});

it('returns a null container rather than guessing when nothing indicates the real one', function () {
    $parsed = $this->parser->parse([
        'name' => 'Movie.1080p',
        'url' => 'https://debrid.example.com/dl/ab12cd34ef56',
    ]);

    expect($parsed['container'])->toBeNull();
});

it('ignores live-stream extensions (ts/m3u8) as a container guess since VOD content is never live', function () {
    $parsed = $this->parser->parse([
        'name' => 'Movie.1080p',
        'url' => 'https://cdn.example.com/videos/playlist.m3u8',
    ]);

    expect($parsed['container'])->toBeNull();
});
