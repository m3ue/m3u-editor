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

it('passes through media_info from the proxy when ffmpeg is active', function () {
    $channel = Channel::factory()->createQuietly([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'BBC ONE',
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
                'resolution' => '1920x1080',
                'video_codec' => 'h264',
                'fps' => 50.0,
                'bitrate_kbps' => 6500.0,
                'audio_codec' => 'aac',
                'audio_channels' => 'stereo',
                'container' => 'MPEGTS',
            ],
        ],
    ]);

    Livewire::test(M3uProxyStreamMonitor::class)
        ->assertSet('streams', function (array $streams) {
            expect($streams)->toHaveCount(1);
            $info = $streams[0]['media_info'] ?? [];

            return $info['resolution'] === '1920x1080'
                && $info['video_codec'] === 'h264'
                && $info['fps'] === 50.0
                && $info['bitrate_kbps'] === 6500.0
                && $info['audio_codec'] === 'aac'
                && $info['audio_channels'] === 'stereo'
                && $info['container'] === 'MPEGTS';
        });
});

it('exposes the failover channel name by matching current_url to a failover candidate', function () {
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
            expect($streams)->toHaveCount(1);
            $stream = $streams[0];

            return ($stream['model']['title'] ?? null) === 'UK: BBC ONE 4K'
                && ($stream['failover_channel']['title'] ?? null) === 'UK: BBC ONE 720P'
                && $stream['using_failover'] === true;
        });
});

it('matches the correct failover channel even when the resolver skipped earlier candidates', function () {
    // Simulates dynamic resolver mode: the resolver skipped the first
    // failover (e.g. capacity full) and picked the second. The proxy bumps
    // current_failover_index by one per successful failover, so the index
    // (1) does NOT line up with the picked candidate's position (2 in the
    // failoverChannels list). URL match is the only reliable identifier.
    $primary = Channel::factory()->createQuietly([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Primary',
        'url' => 'http://example.com/primary',
    ]);
    $skippedBackup = Channel::factory()->createQuietly([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Skipped Backup',
        'url' => 'http://example.com/backup-1',
    ]);
    $activeBackup = Channel::factory()->createQuietly([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
        'name' => 'Active Backup',
        'url' => 'http://example.com/backup-2',
    ]);

    ChannelFailover::create([
        'user_id' => $this->owner->id,
        'channel_id' => $primary->id,
        'channel_failover_id' => $skippedBackup->id,
        'sort' => 1,
        'metadata' => '{}',
    ]);
    ChannelFailover::create([
        'user_id' => $this->owner->id,
        'channel_id' => $primary->id,
        'channel_failover_id' => $activeBackup->id,
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

it('returns empty media_info when the proxy does not provide it', function () {
    $channel = Channel::factory()->createQuietly([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
    ]);

    ($this->bindProxyMock)([
        [
            'stream_id' => 'no-ffmpeg-stream',
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
            // no media_info key — plain HTTP-proxy stream
        ],
    ]);

    Livewire::test(M3uProxyStreamMonitor::class)
        ->assertSet('streams', function (array $streams) {
            return $streams[0]['media_info'] === [];
        });
});
