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
                'refs' => 4,
            ]],
            ['stream' => [
                'codec_type' => 'audio',
                'codec_name' => 'aac',
                'channels' => 2,
                'sample_rate' => '48000',
                'bit_rate' => '128000',
                'tags' => ['language' => 'eng'],
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
        ->video_ref_frames->toBe(4)
        ->source_fps->toBe(25.0)
        ->ffmpeg_output_bitrate->toBe(5000.0)
        ->audio_codec->toBe('aac')
        ->audio_channels->toBe('stereo')
        ->sample_rate->toBe(48000)
        ->audio_bitrate->toBe(128.0)
        ->audio_language->toBe('eng');
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

// ──────────────────────────────────────────────────────────────────────────────
// ensureStreamStats() – on-demand probe with DB persistence
// ──────────────────────────────────────────────────────────────────────────────

it('returns existing stream_stats without probing when already populated', function () {
    $stats = [['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080]]];

    $channel = Channel::factory()->for($this->playlist)->create([
        'stream_stats' => $stats,
        'stream_stats_probed_at' => now()->subHour(),
    ]);

    $mock = Mockery::mock(Channel::class)->makePartial();
    $mock->setRawAttributes($channel->getAttributes());
    $mock->shouldNotReceive('probeStreamStats');

    expect($mock->ensureStreamStats())->toBe($stats);
});

it('probes and calls updateQuietly with stats when stream_stats is null', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'stream_stats' => null,
        'stream_stats_probed_at' => null,
    ]);

    $probedStats = [['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080]]];

    $mock = Mockery::mock(Channel::class)->makePartial();
    $mock->setRawAttributes($channel->getAttributes());
    $mock->shouldReceive('probeStreamStats')->once()->andReturn($probedStats);
    $mock->shouldReceive('updateQuietly')
        ->once()
        ->withArgs(fn ($args) => $args['stream_stats'] === $probedStats && isset($args['stream_stats_probed_at']))
        ->andReturnTrue();

    expect($mock->ensureStreamStats())->toBe($probedStats);
});

it('returns empty array and does not persist when probe yields nothing', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'stream_stats' => null,
        'url' => null,
        'url_custom' => null,
    ]);

    $result = $channel->ensureStreamStats();

    expect($result)->toBe([]);

    $channel->refresh();
    expect($channel->stream_stats)->toBe([])
        ->and($channel->stream_stats_probed_at)->toBeNull();
});

// ──────────────────────────────────────────────────────────────────────────────
// Format-level fallback for video bitrate (Issue #330 — Smart Channels)
// ──────────────────────────────────────────────────────────────────────────────

it('falls back to format.bit_rate minus audio.bit_rate when video bit_rate is null', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'stream_stats' => [
            ['stream' => [
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'width' => 1920,
                'height' => 1080,
                'bit_rate' => null, // typical for MPEG-TS
            ]],
            ['stream' => [
                'codec_type' => 'audio',
                'codec_name' => 'aac',
                'channels' => 2,
                'bit_rate' => '128000',
            ]],
            ['format' => [
                'bit_rate' => '5128000',
                'duration' => '10.0',
                'format_name' => 'mpegts',
            ]],
        ],
    ]);

    expect($channel->getEmbyStreamStats()['ffmpeg_output_bitrate'])->toBe(5000.0);
});

it('prefers per-stream video bit_rate over format-level fallback', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'stream_stats' => [
            ['stream' => [
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'width' => 1920,
                'height' => 1080,
                'bit_rate' => '4500000',
            ]],
            ['format' => [
                'bit_rate' => '9999000',
                'duration' => '10.0',
                'format_name' => 'mpegts',
            ]],
        ],
    ]);

    expect($channel->getEmbyStreamStats()['ffmpeg_output_bitrate'])->toBe(4500.0);
});

it('uses format.bit_rate directly when audio bit_rate is unavailable', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'stream_stats' => [
            ['stream' => [
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'width' => 1920,
                'height' => 1080,
                'bit_rate' => null,
            ]],
            ['stream' => [
                'codec_type' => 'audio',
                'codec_name' => 'aac',
                'bit_rate' => null,
            ]],
            ['format' => [
                'bit_rate' => '5000000',
                'duration' => '10.0',
                'format_name' => 'mpegts',
            ]],
        ],
    ]);

    expect($channel->getEmbyStreamStats()['ffmpeg_output_bitrate'])->toBe(5000.0);
});

it('clamps fallback video bitrate to null when audio bit_rate exceeds format bit_rate', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'stream_stats' => [
            ['stream' => [
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'width' => 1920,
                'height' => 1080,
                'bit_rate' => null,
            ]],
            ['stream' => [
                'codec_type' => 'audio',
                'codec_name' => 'aac',
                'bit_rate' => '10000000',
            ]],
            ['format' => [
                'bit_rate' => '5000000',
                'duration' => '10.0',
                'format_name' => 'mpegts',
            ]],
        ],
    ]);

    expect($channel->getEmbyStreamStats()['ffmpeg_output_bitrate'])->toBeNull();
});

it('parses old stream_stats rows without a format entry without errors', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'stream_stats' => [
            ['stream' => [
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'width' => 1920,
                'height' => 1080,
                'bit_rate' => null,
            ]],
            ['stream' => [
                'codec_type' => 'audio',
                'codec_name' => 'aac',
                'bit_rate' => '128000',
            ]],
        ],
    ]);

    $result = $channel->getEmbyStreamStats();

    expect($result['ffmpeg_output_bitrate'])->toBeNull()
        ->and($result['resolution'])->toBe('1920x1080')
        ->and($result['audio_bitrate'])->toBe(128.0);
});

// ──────────────────────────────────────────────────────────────────────────────
// getStreamStatsForDisplay() with format entry
// ──────────────────────────────────────────────────────────────────────────────

it('skips format entries when building all_streams display list', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'stream_stats' => [
            ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080, 'tags' => []]],
            ['stream' => ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2, 'tags' => ['language' => 'eng']]],
            ['stream' => ['codec_type' => 'audio', 'codec_name' => 'ac3', 'channels' => 6, 'tags' => ['language' => 'spa']]],
            ['format' => ['bit_rate' => '5000000', 'duration' => '10.0', 'format_name' => 'mpegts']],
        ],
    ]);

    $display = $channel->getStreamStatsForDisplay();

    expect($display['advanced']['all_streams'])->toHaveCount(3)
        ->and(collect($display['advanced']['all_streams'])->pluck('codec')->all())
        ->toBe(['h264', 'aac', 'ac3']);
});
