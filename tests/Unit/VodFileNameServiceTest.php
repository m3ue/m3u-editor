<?php

use App\Models\Channel;
use App\Models\StreamFileSetting;
use App\Services\StreamStatsService;
use App\Services\VodFileNameService;

beforeEach(function () {
    $this->setUpTraits = [];
});

it('generates a filesystem safe movie filename from stream stats', function () {
    $channel = new Channel([
        'name' => 'Blade: Runner / Final Cut',
        'year' => 1982,
        'edition' => 'Final Cut',
        'stream_stats' => [
            'resolution' => '1920x1080',
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
            'audio_channels' => 2,
            'hdr' => 'hdr10+',
        ],
    ]);
    $setting = new StreamFileSetting([
        'movie_format' => '{title} ({year}){edition} [{quality} {video} {audio} {hdr}]',
        'use_stream_stats' => true,
        'replace_char' => 'dash',
    ]);

    $fileName = (new VodFileNameService)->generateMovieFileName($channel, $setting);

    expect($fileName)->toBe('Blade- Runner - Final Cut (1982) Final Cut [1080p H.264 AAC 2.0 HDR10+]');
});

it('falls back to movie data year and manual fields when stream stats are disabled', function () {
    $channel = new Channel([
        'name' => 'Example Movie',
        'movie_data' => ['release_date' => '2024-03-01'],
    ]);
    $setting = new StreamFileSetting([
        'movie_format' => '{title} ({year}) [{quality} {video} {audio} {hdr}]',
        'use_stream_stats' => false,
        'quality' => '2160p',
        'video_codec' => 'H.265',
        'audio_codec' => 'DTS 5.1',
        'hdr' => 'DV',
    ]);

    $fileName = (new VodFileNameService)->generateMovieFileName($channel, $setting);

    expect($fileName)->toBe('Example Movie (2024) [2160p H.265 DTS 5.1 DV]');
});

it('detects quality from common resolution shapes', function (array $streamStats, string $quality) {
    expect(StreamStatsService::detectQuality($streamStats))->toBe($quality);
})->with([
    '720p height' => [['resolution' => '1280x720'], '720p'],
    '1080p height' => [['resolution' => '1920x1080'], '1080p'],
    '2160p as 4k' => [['resolution' => '3840x2160'], '4K'],
    'explicit 4k' => [['resolution' => '4K'], '4K'],
]);

it('detects display friendly audio codec and channels', function () {
    expect(StreamStatsService::detectAudio([
        'audio_codec' => 'aac',
        'audio_channels' => 'stereo',
    ]))->toBe('AAC 2.0');
});

it('detects display friendly video codecs', function (string $codec, string $expected) {
    expect(StreamStatsService::detectVideoCodec(['video_codec' => $codec]))->toBe($expected);
})->with([
    'h264' => ['h264', 'H.264'],
    'hevc' => ['hevc', 'H.265'],
    'av1' => ['av1', 'AV1'],
]);

it('detects hdr formats', function (array $streamStats, string $expected) {
    expect(StreamStatsService::detectHdr($streamStats))->toBe($expected);
})->with([
    'generic hdr' => [['hdr' => 'HDR'], 'HDR'],
    'hdr10 plus' => [['hdr' => 'HDR10+'], 'HDR10+'],
    'dolby vision' => [['video_profile' => 'Dolby Vision Profile 8'], 'DV'],
    'hlg transfer' => [['color_transfer' => 'arib-std-b67'], 'HDR'],
]);

it('normalizes ffprobe style stream stats while generating filenames', function () {
    $channel = new Channel([
        'name' => 'Stream Stats Movie',
        'stream_stats' => [
            ['stream' => ['codec_type' => 'video', 'codec_name' => 'hevc', 'width' => 3840, 'height' => 2160, 'profile' => 'Main 10']],
            ['stream' => ['codec_type' => 'audio', 'codec_name' => 'dts', 'channels' => 6]],
        ],
    ]);
    $setting = new StreamFileSetting([
        'movie_format' => '{title} [{quality} {video} {audio}]',
        'use_stream_stats' => true,
    ]);

    $fileName = (new VodFileNameService)->generateMovieFileName($channel, $setting);

    expect($fileName)->toBe('Stream Stats Movie [4K H.265 DTS 5.1]');
});
