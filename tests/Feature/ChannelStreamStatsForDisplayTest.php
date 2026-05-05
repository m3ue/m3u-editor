<?php

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

function makeChannel(Playlist $playlist, array $streamStats): Channel
{
    return Channel::factory()->for($playlist)->create([
        'stream_stats' => $streamStats,
    ]);
}

it('returns compact and advanced sections when video + audio streams are present', function () {
    $channel = makeChannel($this->playlist, [
        ['stream' => [
            'codec_type' => 'video',
            'codec_name' => 'h264',
            'codec_long_name' => 'H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10',
            'profile' => 'High',
            'level' => 40,
            'width' => 1920,
            'height' => 1080,
            'bit_rate' => '4200000',
            'avg_frame_rate' => '25/1',
            'display_aspect_ratio' => '16:9',
            'bits_per_raw_sample' => '8',
            'refs' => 4,
        ]],
        ['stream' => [
            'codec_type' => 'audio',
            'codec_name' => 'aac',
            'codec_long_name' => 'AAC (Advanced Audio Coding)',
            'channels' => 2,
            'sample_rate' => '48000',
            'bit_rate' => '192000',
            'tags' => ['language' => 'eng'],
        ]],
    ]);

    $display = $channel->getStreamStatsForDisplay();

    expect($display['compact'])
        ->resolution->toBe('1920x1080')
        ->video_codec_display->toBe('h264 (High)')
        ->source_fps->toBe(25.0)
        ->ffmpeg_output_bitrate->toBe(4200.0)
        ->audio_codec->toBe('aac')
        ->audio_channels->toBe('stereo')
        ->audio_bitrate->toBe(192.0)
        ->audio_language->toBe('eng');

    expect($display['advanced']['video'])
        ->codec_long_name->toBe('H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10')
        ->level->toBe(40)
        ->bit_depth->toBe(8)
        ->ref_frames->toBe(4)
        ->display_aspect_ratio->toBe('16:9');

    expect($display['advanced']['audio'])
        ->sample_rate->toBe(48000)
        ->codec_long_name->toBe('AAC (Advanced Audio Coding)');
});

it('returns null-filled structure when stream_stats is empty', function () {
    $channel = makeChannel($this->playlist, []);

    $display = $channel->getStreamStatsForDisplay();

    expect($display['compact']['resolution'])->toBeNull();
    expect($display['compact']['video_codec_display'])->toBeNull();
    expect($display['advanced']['video']['codec_long_name'])->toBeNull();
    expect($display['advanced']['audio']['sample_rate'])->toBeNull();
    expect($display['advanced']['all_streams'])->toBeNull();
    expect($display['advanced']['tags'])->toBeNull();
});

it('returns video_codec_display without profile suffix when profile is missing', function () {
    $channel = makeChannel($this->playlist, [
        ['stream' => [
            'codec_type' => 'video',
            'codec_name' => 'hevc',
            'width' => 3840,
            'height' => 2160,
        ]],
    ]);

    expect($channel->getStreamStatsForDisplay()['compact']['video_codec_display'])
        ->toBe('hevc');
});

it('populates advanced.all_streams only when more than two streams exist', function () {
    $channel = makeChannel($this->playlist, [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080]],
        ['stream' => ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2, 'tags' => ['language' => 'eng']]],
        ['stream' => ['codec_type' => 'audio', 'codec_name' => 'ac3', 'channels' => 6, 'tags' => ['language' => 'fra']]],
        ['stream' => ['codec_type' => 'subtitle', 'codec_name' => 'dvb_subtitle', 'tags' => ['language' => 'eng']]],
    ]);

    $allStreams = $channel->getStreamStatsForDisplay()['advanced']['all_streams'];

    expect($allStreams)->toBeArray()->toHaveCount(4);
    expect($allStreams[2])
        ->type->toBe('audio')
        ->codec->toBe('ac3')
        ->lang->toBe('fra');
    expect($allStreams[3])
        ->type->toBe('subtitle')
        ->codec->toBe('dvb_subtitle')
        ->lang->toBe('eng');
});

it('leaves advanced.all_streams as null when only video + audio are present', function () {
    $channel = makeChannel($this->playlist, [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080]],
        ['stream' => ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2]],
    ]);

    expect($channel->getStreamStatsForDisplay()['advanced']['all_streams'])->toBeNull();
});

it('populates advanced.tags from the first video stream when present', function () {
    $channel = makeChannel($this->playlist, [
        ['stream' => [
            'codec_type' => 'video',
            'codec_name' => 'h264',
            'width' => 1920,
            'height' => 1080,
            'tags' => ['TITLE' => 'Main Feed', 'ENCODER' => 'Lavc'],
        ]],
        ['stream' => ['codec_type' => 'audio', 'codec_name' => 'aac', 'channels' => 2]],
    ]);

    expect($channel->getStreamStatsForDisplay()['advanced']['tags'])
        ->toBe(['TITLE' => 'Main Feed', 'ENCODER' => 'Lavc']);
});

it('leaves advanced.tags null when no stream tags exist', function () {
    $channel = makeChannel($this->playlist, [
        ['stream' => ['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720]],
    ]);

    expect($channel->getStreamStatsForDisplay()['advanced']['tags'])->toBeNull();
});
