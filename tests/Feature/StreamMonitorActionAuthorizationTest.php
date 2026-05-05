<?php

use App\Filament\Pages\M3uProxyStreamMonitor;
use App\Models\Network;
use App\Models\Playlist;
use App\Models\User;
use App\Services\M3uProxyService;
use Livewire\Livewire;

/**
 * Bind a mock M3uProxyService with fetchActiveClients and fetchBroadcasts stubbed,
 * then delegate further expectations to the caller via $configure.
 */
function mockProxyService(callable $configure): void
{
    $service = Mockery::mock(M3uProxyService::class);

    $service->shouldReceive('fetchActiveClients')->andReturn([
        'success' => true,
        'clients' => [],
    ]);
    $service->shouldReceive('fetchBroadcasts')->andReturn([
        'success' => true,
        'broadcasts' => [],
    ]);

    $configure($service);

    app()->instance(M3uProxyService::class, $service);
}

/**
 * Build a minimal stream payload for use in fetchActiveStreams stubs.
 */
function streamPayload(string $streamId, string $playlistUuid): array
{
    return [
        'stream_id' => $streamId,
        'metadata' => ['playlist_uuid' => $playlistUuid],
        'original_url' => 'http://example.com/s',
        'current_url' => 'http://example.com/s',
        'stream_type' => 'hls',
        'is_active' => true,
        'client_count' => 1,
        'total_bytes_served' => 0,
        'created_at' => now()->toIso8601String(),
        'has_failover' => false,
        'error_count' => 0,
        'total_segments_served' => 0,
    ];
}

beforeEach(function () {
    $this->owner = User::factory()->create(['permissions' => ['use_proxy']]);
    $this->attacker = User::factory()->create(['permissions' => ['use_proxy']]);

    $this->ownerPlaylist = Playlist::factory()->for($this->owner)->createQuietly();
});

it('blocks triggerFailover for a stream the user does not own', function () {
    mockProxyService(function ($service) {
        $service->shouldReceive('fetchActiveStreams')->andReturn([
            'success' => true,
            'streams' => [],
        ]);
        $service->shouldNotReceive('triggerFailover');
    });

    $this->actingAs($this->attacker);

    Livewire::test(M3uProxyStreamMonitor::class)
        ->callAction('triggerFailover', arguments: ['streamId' => 'stream-owned-by-someone-else'])
        ->assertNotified();
});

it('allows triggerFailover for a stream the user owns', function () {
    $streamId = 'stream-owned-by-me';

    mockProxyService(function ($service) use ($streamId) {
        $service->shouldReceive('fetchActiveStreams')->andReturn([
            'success' => true,
            'streams' => [streamPayload($streamId, $this->ownerPlaylist->uuid)],
        ]);
        $service->shouldReceive('triggerFailover')->with($streamId)->once()->andReturn(true);
    });

    $this->actingAs($this->owner);

    Livewire::test(M3uProxyStreamMonitor::class)
        ->callAction('triggerFailover', arguments: ['streamId' => $streamId])
        ->assertNotified();
});

it('blocks stopStream for a stream the user does not own', function () {
    mockProxyService(function ($service) {
        $service->shouldReceive('fetchActiveStreams')->andReturn([
            'success' => true,
            'streams' => [],
        ]);
        $service->shouldNotReceive('stopStream');
    });

    $this->actingAs($this->attacker);

    Livewire::test(M3uProxyStreamMonitor::class)
        ->callAction('stopStream', arguments: ['streamId' => 'stream-owned-by-someone-else'])
        ->assertNotified();
});

it('allows stopStream for a stream the user owns', function () {
    $streamId = 'stream-owned-by-me';

    mockProxyService(function ($service) use ($streamId) {
        $service->shouldReceive('fetchActiveStreams')->andReturn([
            'success' => true,
            'streams' => [streamPayload($streamId, $this->ownerPlaylist->uuid)],
        ]);
        $service->shouldReceive('stopStream')->with($streamId)->once()->andReturn(true);
    });

    $this->actingAs($this->owner);

    Livewire::test(M3uProxyStreamMonitor::class)
        ->callAction('stopStream', arguments: ['streamId' => $streamId])
        ->assertNotified();
});

it('allows stopStream for a broadcast the user owns', function () {
    $myNetwork = Network::factory()->create(['user_id' => $this->owner->id]);

    mockProxyService(function ($service) use ($myNetwork) {
        $service->shouldReceive('fetchActiveStreams')->andReturn([
            'success' => true,
            'streams' => [],
        ]);
        $service->shouldReceive('stopBroadcast')->with($myNetwork->uuid)->once()->andReturn(true);
    });

    $this->actingAs($this->owner);

    Livewire::test(M3uProxyStreamMonitor::class)
        ->callAction('stopStream', arguments: ['streamId' => 'broadcast:'.$myNetwork->uuid])
        ->assertNotified();
});
