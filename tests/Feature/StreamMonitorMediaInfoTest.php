<?php

use App\Filament\Pages\M3uProxyStreamMonitor;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Playlist;
use App\Models\User;
use App\Services\M3uProxyService;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create(['permissions' => ['use_proxy']]);
    $this->playlist = Playlist::factory()->for($this->owner)->createQuietly();

    $this->bindProxyMock = function (array $streams): void {
        $service = Mockery::mock(M3uProxyService::class);
        $service->shouldReceive('fetchActiveStreams')->andReturn([
            'success' => true,
            'streams' => $streams,
        ]);
        $service->shouldReceive('fetchActiveClients')->andReturn([
            'success' => true,
            'clients' => [],
        ]);
        $service->shouldReceive('fetchBroadcasts')->andReturn([
            'success' => true,
            'broadcasts' => [],
        ]);
        app()->instance(M3uProxyService::class, $service);
    };

    $this->actingAs($this->owner);
});

it('lets live proxy media_info win over stored probe data per-field', function () {
    // Channel has stored probe data from a sync-time ffprobe run — slightly stale
    // (was 1080p h264 50fps when probed). The proxy reports live ffmpeg data
    // showing the stream is currently 1280x720 (e.g. provider re-encoded). Live
    // values must win where present; probe-only fields stay.
    $channel = Channel::factory()->createQuietly([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'BBC ONE',
        'stream_stats' => [
            ['stream' => [
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'profile' => 'High',
                'width' => 1920,
                'height' => 1080,
                'avg_frame_rate' => '50/1',
                'bit_rate' => '6500000',
            ]],
            ['stream' => [
                'codec_type' => 'audio',
                'codec_name' => 'aac',
                'channels' => 2,
                'bit_rate' => '192000',
                'tags' => ['language' => 'eng'],
            ]],
        ],
        'stream_stats_probed_at' => now(),
    ]);

    ($this->bindProxyMock)([
        [
            'stream_id' => 'live-stream-1',
            'metadata' => [
                'playlist_uuid' => $this->playlist->uuid,
                'type' => 'channel',
                'id' => $channel->id,
                'transcoding' => true,
            ],
            'original_url' => 'http://example.com/s',
            'current_url' => 'http://example.com/s',
            'stream_type' => 'mpegts',
            'is_active' => true,
            'client_count' => 1,
            'total_bytes_served' => 1024,
            'total_segments_served' => 0,
            'created_at' => now()->toIso8601String(),
            'has_failover' => false,
            'error_count' => 0,
            'media_info' => [
                'resolution' => '1280x720',
                'video_codec' => 'hevc',
                'fps' => 30.0,
                'bitrate_kbps' => 4500.0,
                'audio_codec' => 'opus',
                'audio_channels' => 'stereo',
                'container' => 'MPEGTS',
            ],
        ],
    ]);

    Livewire::test(M3uProxyStreamMonitor::class)
        ->assertSet('streams', function (array $streams) {
            $info = $streams[0]['model']['media_info'] ?? [];

            return $info['resolution'] === '1280x720'         // live wins
                && $info['video_codec'] === 'hevc'             // live wins
                && $info['source_fps'] === 30.0                // live wins
                && $info['video_bitrate_kbps'] === 4500.0      // live wins
                && $info['audio_codec'] === 'opus'             // live wins
                && $info['video_profile'] === 'High'           // probe-only, kept
                && $info['audio_language'] === 'eng'           // probe-only, kept
                && $info['audio_bitrate_kbps'] === 192.0       // probe-only, kept
                && $info['is_live'] === true;                  // flagged for the live indicator dot
        });
});

it('falls back to stored probe data when the proxy reports no live media_info', function () {
    // Plain HTTP-proxy stream — no transcoding, no live ffmpeg, no media_info from
    // the proxy. The page should still surface badges from the stored probe data
    // so non-transcoded streams aren't badge-less.
    $channel = Channel::factory()->createQuietly([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
        'stream_stats' => [
            ['stream' => [
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'width' => 1920,
                'height' => 1080,
                'avg_frame_rate' => '25/1',
            ]],
        ],
        'stream_stats_probed_at' => now(),
    ]);

    ($this->bindProxyMock)([
        [
            'stream_id' => 'plain-stream',
            'metadata' => [
                'playlist_uuid' => $this->playlist->uuid,
                'type' => 'channel',
                'id' => $channel->id,
            ],
            'original_url' => 'http://example.com/s',
            'current_url' => 'http://example.com/s',
            'stream_type' => 'hls',
            'is_active' => true,
            'client_count' => 1,
            'total_bytes_served' => 0,
            'total_segments_served' => 0,
            'created_at' => now()->toIso8601String(),
            'has_failover' => false,
            'error_count' => 0,
            // no media_info key — proxy isn't transcoding
        ],
    ]);

    Livewire::test(M3uProxyStreamMonitor::class)
        ->assertSet('streams', function (array $streams) {
            $info = $streams[0]['model']['media_info'] ?? [];

            return $info['resolution'] === '1920x1080'
                && $info['video_codec'] === 'h264'
                && $info['source_fps'] === 25.0
                && ! isset($info['is_live']);                  // probe-only must not show the live dot
        });
});

it('omits media_info entirely when neither probe nor live data is available', function () {
    $channel = Channel::factory()->createQuietly([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
        'stream_stats' => null,
    ]);

    ($this->bindProxyMock)([
        [
            'stream_id' => 'bare-stream',
            'metadata' => [
                'playlist_uuid' => $this->playlist->uuid,
                'type' => 'channel',
                'id' => $channel->id,
            ],
            'original_url' => 'http://example.com/s',
            'current_url' => 'http://example.com/s',
            'stream_type' => 'hls',
            'is_active' => true,
            'client_count' => 1,
            'total_bytes_served' => 0,
            'total_segments_served' => 0,
            'created_at' => now()->toIso8601String(),
            'has_failover' => false,
            'error_count' => 0,
        ],
    ]);

    Livewire::test(M3uProxyStreamMonitor::class)
        ->assertSet('streams', function (array $streams) {
            return ! isset($streams[0]['model']['media_info']);
        });
});

it('exposes the failover channel name by URL-matching the active failover candidate', function () {
    $primary = Channel::factory()->createQuietly([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'UK: BBC ONE 4K',
        'url' => 'http://example.com/primary',
    ]);
    $backup = Channel::factory()->createQuietly([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'UK: BBC ONE 720P',
        'url' => 'http://example.com/backup',
    ]);

    ChannelFailover::create([
        'user_id' => $this->owner->id,
        'channel_id' => $primary->id,
        'channel_failover_id' => $backup->id,
        'sort' => 1,
        'metadata' => '{}',
    ]);

    ($this->bindProxyMock)([
        [
            'stream_id' => 'failover-stream',
            'metadata' => [
                'playlist_uuid' => $this->playlist->uuid,
                'type' => 'channel',
                'id' => $primary->id,
            ],
            'original_url' => 'http://example.com/primary',
            'current_url' => 'http://example.com/backup',
            'stream_type' => 'hls',
            'is_active' => true,
            'client_count' => 1,
            'total_bytes_served' => 0,
            'total_segments_served' => 0,
            'created_at' => now()->toIso8601String(),
            'has_failover' => true,
            'error_count' => 0,
            'current_failover_index' => 1,
            'failover_attempts' => 1,
            'failover_urls' => ['http://example.com/backup'],
        ],
    ]);

    Livewire::test(M3uProxyStreamMonitor::class)
        ->assertSet('streams', function (array $streams) {
            $stream = $streams[0];

            return ($stream['failover_channel']['title'] ?? null) === 'UK: BBC ONE 720P'
                && $stream['using_failover'] === true;
        });
});

it('matches the correct failover channel when the dynamic resolver skipped earlier candidates', function () {
    // Dynamic resolver mode: the resolver skipped the first candidate (e.g. capacity
    // full) and picked the second. current_failover_index increments by one per
    // successful failover, so the index (1) does not line up with the picked
    // candidate's slot (2). URL match is the only reliable identifier.
    $primary = Channel::factory()->createQuietly([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Primary',
        'url' => 'http://example.com/primary',
    ]);
    $skipped = Channel::factory()->createQuietly([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Skipped Backup',
        'url' => 'http://example.com/backup-1',
    ]);
    $active = Channel::factory()->createQuietly([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Active Backup',
        'url' => 'http://example.com/backup-2',
    ]);

    ChannelFailover::create([
        'user_id' => $this->owner->id,
        'channel_id' => $primary->id,
        'channel_failover_id' => $skipped->id,
        'sort' => 1,
        'metadata' => '{}',
    ]);
    ChannelFailover::create([
        'user_id' => $this->owner->id,
        'channel_id' => $primary->id,
        'channel_failover_id' => $active->id,
        'sort' => 2,
        'metadata' => '{}',
    ]);

    ($this->bindProxyMock)([
        [
            'stream_id' => 'dynamic-failover-stream',
            'metadata' => [
                'playlist_uuid' => $this->playlist->uuid,
                'type' => 'channel',
                'id' => $primary->id,
            ],
            'original_url' => 'http://example.com/primary',
            'current_url' => 'http://example.com/backup-2',
            'stream_type' => 'hls',
            'is_active' => true,
            'client_count' => 1,
            'total_bytes_served' => 0,
            'total_segments_served' => 0,
            'created_at' => now()->toIso8601String(),
            'has_failover' => true,
            'error_count' => 0,
            'current_failover_index' => 1,
            'failover_attempts' => 1,
            'failover_resolver_url' => 'http://editor.test/api/m3u-proxy/failover-resolver',
            'failover_urls' => [],
        ],
    ]);

    Livewire::test(M3uProxyStreamMonitor::class)
        ->assertSet('streams', function (array $streams) {
            return ($streams[0]['failover_channel']['title'] ?? null) === 'Active Backup';
        });
});

it('omits the failover channel when no failover is active', function () {
    $channel = Channel::factory()->createQuietly([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
    ]);

    ($this->bindProxyMock)([
        [
            'stream_id' => 'plain-stream',
            'metadata' => [
                'playlist_uuid' => $this->playlist->uuid,
                'type' => 'channel',
                'id' => $channel->id,
            ],
            'original_url' => 'http://example.com/s',
            'current_url' => 'http://example.com/s',
            'stream_type' => 'hls',
            'is_active' => true,
            'client_count' => 1,
            'total_bytes_served' => 0,
            'total_segments_served' => 0,
            'created_at' => now()->toIso8601String(),
            'has_failover' => false,
            'error_count' => 0,
            'current_failover_index' => 0,
        ],
    ]);

    Livewire::test(M3uProxyStreamMonitor::class)
        ->assertSet('streams', function (array $streams) {
            return $streams[0]['failover_channel'] === null
                && $streams[0]['using_failover'] === false;
        });
});
