<?php

use App\Filament\Pages\M3uProxyStreamMonitor;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Models\Playlist;
use App\Models\User;
use App\Services\EpgCacheService;
use App\Services\M3uProxyService;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create(['permissions' => ['use_proxy']]);
    $this->playlist = Playlist::factory()->for($this->owner)->createQuietly();

    $this->bindProxyMock = function (array $streams = [], array $broadcasts = []): void {
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
            'broadcasts' => $broadcasts,
        ]);
        app()->instance(M3uProxyService::class, $service);
    };

    $this->actingAs($this->owner);
});

it('attaches the current programme, progress and up-next from the EPG cache for a channel stream', function () {
    $epg = Epg::factory()->for($this->owner)->create();
    $epgChannel = EpgChannel::factory()->create([
        'epg_id' => $epg->id,
        'channel_id' => 'cnn.us',
    ]);
    $channel = Channel::factory()->createQuietly([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
        'epg_channel_id' => $epgChannel->id,
        'name' => 'CNN',
    ]);

    // Synthetic programmes: current is half-way through, next starts in 30 min.
    $start = now()->subMinutes(30)->toIso8601String();
    $stop = now()->addMinutes(30)->toIso8601String();
    $nextStart = now()->addMinutes(30)->toIso8601String();
    $nextStop = now()->addMinutes(90)->toIso8601String();

    $cache = Mockery::mock(EpgCacheService::class);
    $cache->shouldReceive('getCachedProgrammesRange')
        ->once()
        ->andReturn([
            'cnn.us' => [
                ['start' => $start, 'stop' => $stop, 'title' => 'Anderson Cooper 360', 'desc' => 'Live news.'],
                ['start' => $nextStart, 'stop' => $nextStop, 'title' => 'CNN Newsroom'],
            ],
        ]);
    app()->instance(EpgCacheService::class, $cache);

    ($this->bindProxyMock)([
        [
            'stream_id' => 'epg-stream',
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
            $epg = $streams[0]['epg'] ?? null;

            return $epg !== null
                && ($epg['current']['title'] ?? null) === 'Anderson Cooper 360'
                && ($epg['current']['progress'] ?? 0) >= 0.45
                && ($epg['current']['progress'] ?? 0) <= 0.55
                && ($epg['next']['title'] ?? null) === 'CNN Newsroom';
        });
});

it('leaves epg null when the channel has no mapped EPG', function () {
    $channel = Channel::factory()->createQuietly([
        'user_id' => $this->owner->id,
        'playlist_id' => $this->playlist->id,
        'epg_channel_id' => null,
    ]);

    ($this->bindProxyMock)([
        [
            'stream_id' => 'no-epg-stream',
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
            return array_key_exists('epg', $streams[0])
                && $streams[0]['epg'] === null;
        });
});

it('attaches current and next programme to a network broadcast stream', function () {
    $network = Network::factory()->for($this->owner)->create();

    NetworkProgramme::factory()->for($network)->create([
        'title' => 'Morning Show',
        'description' => 'Today\'s news.',
        'start_time' => now()->subMinutes(30),
        'end_time' => now()->addMinutes(30),
    ]);
    NetworkProgramme::factory()->for($network)->create([
        'title' => 'Afternoon Show',
        'start_time' => now()->addMinutes(30),
        'end_time' => now()->addMinutes(90),
    ]);

    ($this->bindProxyMock)([], [
        [
            'network_id' => $network->uuid,
            'started_at' => now()->subHour()->toIso8601String(),
            'status' => 'running',
            'stream_url' => 'http://example.com/broadcast.m3u8',
            'current_segment_number' => 12,
        ],
    ]);

    Livewire::test(M3uProxyStreamMonitor::class)
        ->assertSet('streams', function (array $streams) {
            $epg = $streams[0]['epg'] ?? null;

            return $epg !== null
                && ($epg['current']['title'] ?? null) === 'Morning Show'
                && ($epg['next']['title'] ?? null) === 'Afternoon Show'
                && ($streams[0]['broadcast'] ?? false) === true;
        });
});
