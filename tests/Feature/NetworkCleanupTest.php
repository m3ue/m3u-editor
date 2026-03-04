<?php

use App\Models\Network;
use App\Services\NetworkBroadcastService;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\mock;

beforeEach(function () {
    // Mock proxy HTTP calls - proxy handles HLS file management now
    Http::fake([
        '*/broadcast/*/status' => Http::response(['status' => 'stopped'], 404),
        '*/broadcast/*/stop' => Http::response(['status' => 'stopped', 'final_segment_number' => 0], 200),
        '*/broadcast/*' => Http::response([], 200), // DELETE cleanup
    ]);
});

it('calls NetworkBroadcastService::stop when a network is deleted', function () {
    $network = Network::factory()->activeBroadcast()->create();

    // Mock the service and expect stop() to be called with the network
    mock(NetworkBroadcastService::class)
        ->shouldReceive('stop')
        ->once()
        ->withArgs(function ($arg) use ($network) {
            return $arg->id === $network->id;
        })
        ->andReturnTrue();

    $network->delete();

    $this->assertDatabaseMissing('networks', ['id' => $network->id]);
});

it('stop sends cleanup request to proxy', function () {
    $network = Network::factory()->activeBroadcast()->create();

    $service = app(NetworkBroadcastService::class);
    $service->stop($network);

    // Verify proxy cleanup endpoint was called
    Http::assertSent(function ($request) use ($network) {
        return str_contains($request->url(), "/broadcast/{$network->uuid}") &&
               $request->method() === 'DELETE';
    });
});

it('stop updates network state correctly', function () {
    $network = Network::factory()->activeBroadcast()->create([
        'broadcast_requested' => true,
        'broadcast_pid' => 12345,
        'broadcast_started_at' => now(),
        'broadcast_segment_sequence' => 100,
    ]);

    $service = app(NetworkBroadcastService::class);
    $service->stop($network);

    $network->refresh();
    expect($network->broadcast_requested)->toBeFalse();
    expect($network->broadcast_pid)->toBeNull();
    expect($network->broadcast_started_at)->toBeNull();
    expect($network->broadcast_segment_sequence)->toBe(0);
});

it('deleted network endpoints return 404', function () {
    Http::fake([
        '*/broadcast/*/live.m3u8' => Http::response('Not found', 404),
        '*/broadcast/*/status' => Http::response(['status' => 'stopped'], 404),
        '*/broadcast/*/stop' => Http::response(['status' => 'stopped'], 200),
        '*/broadcast/*' => Http::response([], 200),
    ]);

    $network = Network::factory()->activeBroadcast()->create();
    $uuid = $network->uuid;

    $network->delete();

    // Playlist and stream endpoints should now return 404
    $playlistResponse = $this->get(route('network.hls.playlist', ['network' => $uuid]));
    $streamResponse = $this->get(url("/network/{$uuid}/stream.ts"));

    $playlistResponse->assertNotFound();
    $streamResponse->assertNotFound();
});

it('stops broadcast and clears schedule when last content is removed', function () {
    $network = Network::factory()->activeBroadcast()->create();
    $channel = \App\Models\Channel::factory()->create();

    // Add content to the network
    $networkContent = \App\Models\NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => get_class($channel),
        'contentable_id' => $channel->id,
        'sort_order' => 1,
    ]);

    // Create some programmes
    \App\Models\NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'Test Programme',
        'start_time' => now(),
        'end_time' => now()->addHour(),
        'duration_seconds' => 3600,
        'contentable_type' => get_class($channel),
        'contentable_id' => $channel->id,
    ]);

    expect($network->programmes()->count())->toBe(1);

    // Mock the services
    mock(\App\Services\NetworkBroadcastService::class)
        ->shouldReceive('stop')
        ->once()
        ->withArgs(fn ($arg) => $arg->id === $network->id);

    mock(\App\Services\NetworkEpgService::class)
        ->shouldReceive('generateEpg')
        ->once()
        ->withArgs(fn ($arg) => $arg->id === $network->id);

    // Delete the content
    $networkContent->delete();

    // Verify cleanup happened
    $network->refresh();
    expect($network->programmes()->count())->toBe(0);
    expect($network->schedule_generated_at)->toBeNull();
});
