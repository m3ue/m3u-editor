<?php

use App\Jobs\MergeChannels;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->playlist = Playlist::factory()->createQuietly([
        'user_id' => $this->user->id,
    ]);

    $this->group = Group::factory()->createQuietly([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);
});

/**
 * Helper: build stream_stats array with given fps + video bitrate (kbps).
 * Includes a format entry so the value path matches a real probe.
 */
function streamStats(?float $fps = null, ?int $videoKbps = null, int $audioKbps = 128, ?int $width = 1920, ?int $height = 1080): array
{
    $stats = [];

    $videoStream = [
        'codec_type' => 'video',
        'codec_name' => 'h264',
        'width' => $width,
        'height' => $height,
    ];
    if ($fps !== null) {
        $videoStream['avg_frame_rate'] = $fps.'/1';
    }
    if ($videoKbps !== null) {
        $videoStream['bit_rate'] = (string) ($videoKbps * 1000);
    }
    $stats[]['stream'] = $videoStream;

    $stats[]['stream'] = [
        'codec_type' => 'audio',
        'codec_name' => 'aac',
        'channels' => 2,
        'bit_rate' => (string) ($audioKbps * 1000),
    ];

    return $stats;
}

it('selects high-fps channel as master when fps is the priority', function () {
    $low = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => 'fps-test',
        'name' => 'Low FPS',
        'enabled' => true,
        'can_merge' => true,
        'stream_stats' => streamStats(fps: 25),
        'stream_stats_probed_at' => now(),
    ]);

    $high = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => 'fps-test',
        'name' => 'High FPS',
        'enabled' => true,
        'can_merge' => true,
        'stream_stats' => streamStats(fps: 60),
        'stream_stats_probed_at' => now(),
    ]);

    (new MergeChannels(
        user: $this->user,
        playlists: collect([['playlist_failover_id' => $this->playlist->id]]),
        playlistId: $this->playlist->id,
        weightedConfig: [
            'priority_attributes' => ['fps'],
            'priority_keywords' => [],
            'prefer_codec' => null,
            'exclude_disabled_groups' => false,
            'group_priorities' => [],
        ],
    ))->handle();

    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $high->id,
        'channel_failover_id' => $low->id,
    ]);
});

it('selects high-bitrate channel as master when bitrate is the priority', function () {
    $low = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => 'br-test',
        'name' => 'Low Bitrate',
        'enabled' => true,
        'can_merge' => true,
        'stream_stats' => streamStats(videoKbps: 2000),
        'stream_stats_probed_at' => now(),
    ]);

    $high = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => 'br-test',
        'name' => 'High Bitrate',
        'enabled' => true,
        'can_merge' => true,
        'stream_stats' => streamStats(videoKbps: 8000),
        'stream_stats_probed_at' => now(),
    ]);

    (new MergeChannels(
        user: $this->user,
        playlists: collect([['playlist_failover_id' => $this->playlist->id]]),
        playlistId: $this->playlist->id,
        weightedConfig: [
            'priority_attributes' => ['bitrate'],
            'priority_keywords' => [],
            'prefer_codec' => null,
            'exclude_disabled_groups' => false,
            'group_priorities' => [],
        ],
    ))->handle();

    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $high->id,
        'channel_failover_id' => $low->id,
    ]);
});

it('uses format-level bitrate fallback for ranking when per-stream video bit_rate is null', function () {
    // Channel with format-level bitrate only (typical for MPEG-TS): 8000 kbps total.
    $high = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => 'fmt-test',
        'name' => 'Format Fallback Hi',
        'enabled' => true,
        'can_merge' => true,
        'stream_stats' => [
            ['stream' => [
                'codec_type' => 'video', 'codec_name' => 'h264',
                'width' => 1920, 'height' => 1080,
                'bit_rate' => null,
            ]],
            ['stream' => [
                'codec_type' => 'audio', 'codec_name' => 'aac',
                'channels' => 2, 'bit_rate' => '128000',
            ]],
            ['format' => ['bit_rate' => '8128000', 'duration' => '10.0', 'format_name' => 'mpegts']],
        ],
        'stream_stats_probed_at' => now(),
    ]);

    $low = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => 'fmt-test',
        'name' => 'Format Fallback Lo',
        'enabled' => true,
        'can_merge' => true,
        'stream_stats' => streamStats(videoKbps: 2000),
        'stream_stats_probed_at' => now(),
    ]);

    (new MergeChannels(
        user: $this->user,
        playlists: collect([['playlist_failover_id' => $this->playlist->id]]),
        playlistId: $this->playlist->id,
        weightedConfig: [
            'priority_attributes' => ['bitrate'],
            'priority_keywords' => [],
            'prefer_codec' => null,
            'exclude_disabled_groups' => false,
            'group_priorities' => [],
        ],
    ))->handle();

    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $high->id,
        'channel_failover_id' => $low->id,
    ]);
});

it('treats missing stream_stats as zero score for fps', function () {
    $low = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => 'fps-empty',
        'name' => 'No Stats',
        'sort' => 1.0,
        'enabled' => true,
        'can_merge' => true,
        'stream_stats' => null,
        'stream_stats_probed_at' => now(),
        'url' => null,
        'url_custom' => null,
    ]);

    $high = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => 'fps-empty',
        'name' => 'Has Stats',
        'sort' => 2.0,
        'enabled' => true,
        'can_merge' => true,
        'stream_stats' => streamStats(fps: 50),
        'stream_stats_probed_at' => now(),
    ]);

    (new MergeChannels(
        user: $this->user,
        playlists: collect([['playlist_failover_id' => $this->playlist->id]]),
        playlistId: $this->playlist->id,
        weightedConfig: [
            'priority_attributes' => ['fps'],
            'priority_keywords' => [],
            'prefer_codec' => null,
            'exclude_disabled_groups' => false,
            'group_priorities' => [],
        ],
    ))->handle();

    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $high->id,
        'channel_failover_id' => $low->id,
    ]);
});
