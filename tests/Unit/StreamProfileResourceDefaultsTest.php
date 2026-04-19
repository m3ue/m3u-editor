<?php

use App\Filament\Resources\StreamProfiles\StreamProfileResource;

test('streamlink backend has optimized default args', function () {
    expect(StreamProfileResource::defaultArgsForBackend('streamlink'))
        ->toBe('best --hls-live-edge 3');
});

test('ytdlp backend keeps expected default args', function () {
    expect(StreamProfileResource::defaultArgsForBackend('ytdlp'))
        ->toBe('bestvideo+bestaudio/best');
});

test('ffmpeg backend keeps expected default args', function () {
    expect(StreamProfileResource::defaultArgsForBackend('ffmpeg'))
        ->toContain('-i {input_url}')
        ->toContain('-f mpegts {output_args|pipe:1}');
});
