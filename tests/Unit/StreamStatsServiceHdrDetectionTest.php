<?php

use App\Services\StreamStatsService;

it('detects Dolby Vision from codec_tag_string dvh1', function () {
    $stats = StreamStatsService::normalize([
        ['codec_type' => 'video', 'codec_name' => 'hevc', 'codec_tag_string' => 'dvh1', 'width' => 3840, 'height' => 2160],
    ]);

    expect(StreamStatsService::detectHdr($stats))->toBe('DV');
});

it('detects Dolby Vision from side_data_list DOVI configuration record', function () {
    $stats = StreamStatsService::normalize([
        [
            'codec_type' => 'video',
            'codec_name' => 'hevc',
            'side_data_list' => [
                ['side_data_type' => 'DOVI configuration record', 'dv_profile' => 8, 'dv_level' => 6],
            ],
        ],
    ]);

    expect(StreamStatsService::detectHdr($stats))->toBe('DV');
});

it('detects HDR10+ from SMPTE2094-40 dynamic metadata', function () {
    $stats = StreamStatsService::normalize([
        [
            'codec_type' => 'video',
            'codec_name' => 'hevc',
            'color_transfer' => 'smpte2084',
            'side_data_list' => [
                ['side_data_type' => 'HDR Dynamic Metadata SMPTE2094-40 (HDR10+)'],
            ],
        ],
    ]);

    expect(StreamStatsService::detectHdr($stats))->toBe('HDR10+');
});

it('detects HDR10 from PQ transfer plus mastering display side data', function () {
    $stats = StreamStatsService::normalize([
        [
            'codec_type' => 'video',
            'codec_name' => 'hevc',
            'color_transfer' => 'smpte2084',
            'color_space' => 'bt2020nc',
            'color_primaries' => 'bt2020',
            'side_data_list' => [
                ['side_data_type' => 'Mastering display metadata'],
                ['side_data_type' => 'Content light level metadata'],
            ],
        ],
    ]);

    expect(StreamStatsService::detectHdr($stats))->toBe('HDR10');
});

it('detects HLG from arib-std-b67 transfer', function () {
    $stats = StreamStatsService::normalize([
        ['codec_type' => 'video', 'codec_name' => 'hevc', 'color_transfer' => 'arib-std-b67'],
    ]);

    expect(StreamStatsService::detectHdr($stats))->toBe('HDR');
});

it('returns empty for SDR streams without color metadata', function () {
    $stats = StreamStatsService::normalize([
        ['codec_type' => 'video', 'codec_name' => 'h264', 'color_transfer' => 'bt709', 'color_space' => 'bt709'],
    ]);

    expect(StreamStatsService::detectHdr($stats))->toBe('');
});

it('preserves color metadata fields through normalize for nested ffprobe shape', function () {
    $stats = StreamStatsService::normalize([
        [
            'codec_type' => 'video',
            'codec_name' => 'hevc',
            'color_transfer' => 'smpte2084',
            'color_space' => 'bt2020nc',
            'color_primaries' => 'bt2020',
            'codec_tag_string' => 'dvh1',
        ],
    ]);

    expect($stats)
        ->toHaveKey('color_transfer', 'smpte2084')
        ->toHaveKey('color_space', 'bt2020nc')
        ->toHaveKey('color_primaries', 'bt2020')
        ->toHaveKey('codec_tag_string', 'dvh1');
});
