<?php

use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Services\NetworkBroadcastService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        '*/broadcast/*/status' => Http::response(['status' => 'stopped'], 404),
        '*/broadcast/*/stop' => Http::response(['status' => 'stopped', 'final_segment_number' => 0], 200),
        '*/broadcast/*/start' => Http::response(['status' => 'started', 'ffmpeg_pid' => 12345], 200),
        '*' => Http::response([], 200),
    ]);
});

/*
|--------------------------------------------------------------------------
| performBootRecovery() tests
|--------------------------------------------------------------------------
*/

it('sets broadcast_requested to true for all enabled networks on boot recovery', function () {
    $network1 = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => false,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
    ]);

    $network2 = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => false,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
    ]);

    $service = app(NetworkBroadcastService::class);
    $recovered = $service->performBootRecovery();

    expect($recovered)->toBe(2);
    expect($network1->fresh()->broadcast_requested)->toBeTrue();
    expect($network2->fresh()->broadcast_requested)->toBeTrue();
});

it('clears stale pid and started_at during boot recovery', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 99999,
        'broadcast_started_at' => Carbon::now()->subHours(2),
        'broadcast_error' => 'Some old error',
    ]);

    $service = app(NetworkBroadcastService::class);
    $recovered = $service->performBootRecovery();

    $network->refresh();

    expect($recovered)->toBe(1);
    expect($network->broadcast_requested)->toBeTrue();
    expect($network->broadcast_pid)->toBeNull();
    expect($network->broadcast_started_at)->toBeNull();
    expect($network->broadcast_error)->toBeNull();
});

it('skips disabled networks during boot recovery', function () {
    // broadcast_enabled = false
    $disabledBroadcast = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => false,
        'broadcast_requested' => false,
    ]);

    // enabled = false
    $disabledNetwork = Network::factory()->create([
        'enabled' => false,
        'broadcast_enabled' => true,
        'broadcast_requested' => false,
    ]);

    $service = app(NetworkBroadcastService::class);
    $recovered = $service->performBootRecovery();

    expect($recovered)->toBe(0);
    expect($disabledBroadcast->fresh()->broadcast_requested)->toBeFalse();
    expect($disabledNetwork->fresh()->broadcast_requested)->toBeFalse();
});

it('recovers only a specific network when passed as argument', function () {
    $target = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => false,
        'broadcast_pid' => 11111,
        'broadcast_started_at' => Carbon::now()->subHour(),
    ]);

    $other = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => false,
        'broadcast_pid' => 22222,
        'broadcast_started_at' => Carbon::now()->subHour(),
    ]);

    $service = app(NetworkBroadcastService::class);
    $recovered = $service->performBootRecovery($target);

    expect($recovered)->toBe(1);
    expect($target->fresh()->broadcast_requested)->toBeTrue();
    expect($target->fresh()->broadcast_pid)->toBeNull();

    // Other network should be untouched
    expect($other->fresh()->broadcast_requested)->toBeFalse();
    expect($other->fresh()->broadcast_pid)->toBe(22222);
});

it('skips single network boot recovery if network is not broadcast-enabled', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => false,
        'broadcast_requested' => false,
    ]);

    $service = app(NetworkBroadcastService::class);
    $recovered = $service->performBootRecovery($network);

    expect($recovered)->toBe(0);
    expect($network->fresh()->broadcast_requested)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Transient failure resilience tests (startViaProxy returns null)
|--------------------------------------------------------------------------
*/

it('returns null from startViaProxy on connection exception', function () {
    // Fake HTTP to throw a connection exception on broadcast start
    Http::fake([
        '*/broadcast/*/status' => Http::response(['status' => 'stopped'], 404),
        '*/broadcast/*/start' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        },
        '*' => Http::response([], 200),
    ]);

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
    ]);

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $service = app(NetworkBroadcastService::class);

    // Call the protected startViaProxy method via reflection
    $method = new ReflectionMethod(NetworkBroadcastService::class, 'startViaProxy');

    $result = $method->invoke(
        $service,
        $network,
        'http://localhost:8096/video/stream.ts',
        300,
        3300,
        $programme,
    );

    expect($result)->toBeNull();
});

it('preserves broadcast_requested when startViaProxy returns null (transient failure)', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    // Mock: getStreamUrl returns a URL, isProcessRunning returns false,
    // startViaProxy returns null (transient failure)
    $service = Mockery::mock(NetworkBroadcastService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('getStreamUrl')->andReturn('http://localhost:8096/video/stream.ts');
    $service->shouldReceive('isProcessRunning')->andReturn(false);
    $service->shouldReceive('startViaProxy')->once()->andReturn(null);

    $result = $service->start($network);

    // start() should return false (null cast to bool)
    expect($result)->toBeFalse();

    // But broadcast_requested should still be true (not cleared)
    $network->refresh();
    expect($network->broadcast_requested)->toBeTrue();
});

it('clears broadcast_requested when proxy returns a permanent error', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    // Mock: getStreamUrl returns a URL, isProcessRunning returns false,
    // startViaProxy returns false (permanent proxy error)
    $service = Mockery::mock(NetworkBroadcastService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('getStreamUrl')->andReturn('http://localhost:8096/video/stream.ts');
    $service->shouldReceive('isProcessRunning')->andReturn(false);
    $service->shouldReceive('startViaProxy')->once()->andReturn(false);

    $result = $service->start($network);

    expect($result)->toBeFalse();

    // broadcast_requested SHOULD be cleared for permanent failures
    $network->refresh();
    expect($network->broadcast_requested)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Integration: boot recovery + tick loop
|--------------------------------------------------------------------------
*/

it('tick restarts broadcast after boot recovery sets broadcast_requested', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => false, // Simulates state after a failed start cleared it
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('isProcessRunning')->andReturn(false);
    $service->shouldReceive('start')->once()->andReturn(true);

    // Before boot recovery: tick should return idle
    $result = $service->tick($network);
    expect($result['action'])->toBe('idle');

    // Perform boot recovery
    $recovered = app(NetworkBroadcastService::class)->performBootRecovery($network);
    expect($recovered)->toBe(1);

    // After boot recovery: tick should attempt to start
    $network->refresh();
    $result = $service->tick($network);
    expect($result['action'])->toBe('started');
});
