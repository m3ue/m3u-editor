<?php

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

// ──────────────────────────────────────────────────────────────────────────────
// getEmbyStreamStats() – transforms raw ffprobe data to emby-xtream format
// ──────────────────────────────────────────────────────────────────────────────

it('transforms video and audio stats into emby-xtream format', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'stream_stats' => [
            ['stream' => [
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'profile' => 'High',
                'level' => 41,
                'width' => 1920,
                'height' => 1080,
                'bit_rate' => '5000000',
                'avg_frame_rate' => '25/1',
                'bits_per_raw_sample' => '8',
            ]],
            ['stream' => [
                'codec_type' => 'audio',
                'codec_name' => 'aac',
                'channels' => 2,
                'sample_rate' => '48000',
                'bit_rate' => '128000',
            ]],
        ],
    ]);

    $result = $channel->getEmbyStreamStats();

    expect($result)
        ->resolution->toBe('1920x1080')
        ->video_codec->toBe('h264')
        ->video_profile->toBe('High')
        ->video_level->toBe(41)
        ->video_bit_depth->toBe(8)
        ->source_fps->toBe(25.0)
        ->ffmpeg_output_bitrate->toBe(5000.0)
        ->audio_codec->toBe('aac')
        ->audio_channels->toBe('stereo')
        ->sample_rate->toBe(48000)
        ->audio_bitrate->toBe(128.0)
        ->audio_language->toBeNull();
});

it('returns empty array when stream_stats is empty', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'stream_stats' => null,
    ]);

    expect($channel->getEmbyStreamStats())->toBe([]);
});

it('maps channel counts to layout strings correctly', function (int $channels, string $expected) {
    $channel = Channel::factory()->for($this->playlist)->create([
        'stream_stats' => [
            ['stream' => ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => $channels, 'sample_rate' => '48000']],
        ],
    ]);

    expect($channel->getEmbyStreamStats()['audio_channels'])->toBe($expected);
})->with([
    'mono' => [1, 'mono'],
    'stereo' => [2, 'stereo'],
    '5.1' => [6, '5.1'],
    '7.1' => [8, '7.1'],
    'other' => [4, '4'],
]);

it('parses fractional frame rates correctly', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'stream_stats' => [
            ['stream' => [
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'width' => 1920,
                'height' => 1080,
                'avg_frame_rate' => '30000/1001',
            ]],
        ],
    ]);

    expect($channel->getEmbyStreamStats()['source_fps'])->toBe(29.97);
});

it('defaults video_bit_depth to 8 when bits_per_raw_sample is not set', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'stream_stats' => [
            ['stream' => [
                'codec_type' => 'video',
                'codec_name' => 'hevc',
                'width' => 3840,
                'height' => 2160,
            ]],
        ],
    ]);

    expect($channel->getEmbyStreamStats()['video_bit_depth'])->toBe(8);
});

it('handles 10-bit video correctly', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'stream_stats' => [
            ['stream' => [
                'codec_type' => 'video',
                'codec_name' => 'hevc',
                'width' => 3840,
                'height' => 2160,
                'bits_per_raw_sample' => '10',
            ]],
        ],
    ]);

    expect($channel->getEmbyStreamStats()['video_bit_depth'])->toBe(10);
});

// ──────────────────────────────────────────────────────────────────────────────
// getStreamStatsAttribute() – accessor priority
// ──────────────────────────────────────────────────────────────────────────────

it('returns persisted stream_stats from database when available', function () {
    $stats = [['stream' => ['codec_type' => 'video', 'codec_name' => 'h264']]];

    $channel = Channel::factory()->for($this->playlist)->create([
        'stream_stats' => $stats,
    ]);

    // Reload from DB to ensure attribute accessor is tested
    $channel = Channel::find($channel->id);

    expect($channel->stream_stats)->toBe($stats);
});

it('returns empty array when no stats are persisted or cached', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'stream_stats' => null,
    ]);

    $channel = Channel::find($channel->id);

    expect($channel->stream_stats)->toBe([]);
});
