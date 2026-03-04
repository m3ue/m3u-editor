<?php

use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Services\NetworkBroadcastService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Mock proxy HTTP calls - proxy is not available in tests
    Http::fake([
        '*/broadcast/*/status' => Http::response(['status' => 'stopped'], 404),
        '*/broadcast/*/stop' => Http::response(['status' => 'stopped', 'final_segment_number' => 0], 200),
        '*' => Http::response([], 200),
    ]);
});

it('does not auto-start broadcast when broadcast_requested is false', function () {
    $network = Network::factory()->create([
        'broadcast_enabled' => true,
        'broadcast_requested' => false,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
    ]);

    // Create a programme so the network has content
    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
    ]);

    $service = app(NetworkBroadcastService::class);

    // Run a tick - it should NOT auto-start because broadcast_requested is false
    $result = $service->tick($network);

    expect($result['action'])->toBe('idle');
    expect($network->fresh()->broadcast_pid)->toBeNull();
});

it('auto-starts broadcast when broadcast_requested is true', function () {
    $network = Network::factory()->create([
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
    ]);

    // Create a programme so the network has content
    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
    ]);

    // Create a real service instance, then mock the start method
    $service = Mockery::mock(NetworkBroadcastService::class, function ($mock) {
        $mock->makePartial();
        $mock->shouldAllowMockingProtectedMethods();
        $mock->shouldReceive('start')->once()->andReturn(true);
        $mock->shouldReceive('isProcessRunning')->andReturn(false);
    });

    // Swap in the mock
    app()->instance(NetworkBroadcastService::class, $service);

    $result = $service->tick($network);

    expect($result['action'])->toBe('started');
});

it('sets broadcast_requested to false when stop is called', function () {
    $network = Network::factory()->create([
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => now(),
    ]);

    $service = app(NetworkBroadcastService::class);
    $service->stop($network);

    $network->refresh();
    expect($network->broadcast_requested)->toBeFalse();
});
