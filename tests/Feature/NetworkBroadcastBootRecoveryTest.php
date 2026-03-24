<?php

use App\Models\MediaServerIntegration;
use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Services\NetworkBroadcastService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
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

it('cleans up Plex transcode session during boot recovery', function () {
    Http::fake([
        '*/health' => Http::response(['status' => 'ok'], 200),
        '*/broadcast/*/stop' => Http::response(['status' => 'stopped'], 200),
        '*' => Http::response([], 200),
    ]);

    $integration = MediaServerIntegration::withoutEvents(function () {
        return MediaServerIntegration::factory()->create([
            'type' => 'plex',
            'host' => 'plex',
            'port' => 32400,
            'api_key' => 'abc123',
        ]);
    });

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_transcode_session_id' => 'stale-session-abc',
        'media_server_integration_id' => $integration->id,
    ]);

    $service = app(NetworkBroadcastService::class);
    $service->performBootRecovery($network);

    // Verify the Plex stop-transcode call was made
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/video/:/transcode/universal/stop')
            && $request['session'] === 'stale-session-abc';
    });

    // Session ID should be cleared
    expect($network->fresh()->broadcast_transcode_session_id)->toBeNull();
});

it('calls proxy DELETE to clean up stale segment files during boot recovery', function () {
    Http::fake([
        '*/health' => Http::response(['status' => 'ok'], 200),
        '*/broadcast/*/stop' => Http::response(['status' => 'stopped'], 200),
        '*' => Http::response([], 200),
    ]);

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => false,
        'broadcast_segment_sequence' => 500,
        'broadcast_discontinuity_sequence' => 3,
    ]);

    $service = app(NetworkBroadcastService::class);
    $service->performBootRecovery($network);

    // Verify proxy DELETE was called to clean up segment files
    Http::assertSent(function ($request) use ($network) {
        return $request->method() === 'DELETE'
            && str_contains($request->url(), "/broadcast/{$network->uuid}");
    });

    // Sequences should be reset since stale files were deleted
    $network->refresh();
    expect($network->broadcast_segment_sequence)->toBe(0);
    expect($network->broadcast_discontinuity_sequence)->toBe(0);
});

it('calls proxy stop endpoint during boot recovery to kill lingering FFmpeg', function () {
    Http::fake([
        '*/health' => Http::response(['status' => 'ok'], 200),
        '*/broadcast/*/stop' => Http::response(['status' => 'stopped', 'final_segment_number' => 0], 200),
        '*' => Http::response([], 200),
    ]);

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 99999,
        'broadcast_started_at' => Carbon::now()->subHours(2),
    ]);

    $service = app(NetworkBroadcastService::class);
    $service->performBootRecovery($network);

    // Verify proxy stop was called
    Http::assertSent(function ($request) use ($network) {
        return $request->method() === 'POST'
            && str_contains($request->url(), "/broadcast/{$network->uuid}/stop");
    });
});

it('continues boot recovery even when proxy is unreachable for cleanup calls', function () {
    // Simulate proxy being partially available: health OK but stop/delete fail
    Http::fake([
        '*/health' => Http::response(['status' => 'ok'], 200),
        '*/broadcast/*/stop' => function () {
            throw new ConnectionException('Connection refused');
        },
        '*' => function () {
            throw new ConnectionException('Connection refused');
        },
    ]);

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => false,
        'broadcast_pid' => 99999,
        'broadcast_started_at' => Carbon::now()->subHour(),
        'broadcast_fail_count' => 5,
    ]);

    $service = app(NetworkBroadcastService::class);
    $recovered = $service->performBootRecovery($network);

    // Recovery should still succeed even though cleanup calls failed
    expect($recovered)->toBe(1);

    $network->refresh();
    expect($network->broadcast_requested)->toBeTrue();
    expect($network->broadcast_pid)->toBeNull();
    expect($network->broadcast_fail_count)->toBe(0);
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
            throw new ConnectionException('Connection refused');
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
| Boot grace period tests
|--------------------------------------------------------------------------
*/

it('performBootRecovery stamps broadcast_boot_recovery_until approximately 4 minutes in the future', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => false,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
    ]);

    $before = now()->addMinutes(4)->subSeconds(5);
    $after = now()->addMinutes(4)->addSeconds(5);

    $service = app(NetworkBroadcastService::class);
    $service->performBootRecovery($network);

    $gracePeriodUntil = $network->fresh()->broadcast_boot_recovery_until;

    expect($gracePeriodUntil)->not->toBeNull();
    expect($gracePeriodUntil->between($before, $after))->toBeTrue();
});

it('missing programme during grace period returns false and preserves broadcast_requested', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'broadcast_boot_recovery_until' => now()->addMinutes(2),
    ]);

    // No programme created — simulates slow storage not yet loaded

    $service = Mockery::mock(NetworkBroadcastService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('isProcessRunning')->andReturn(false);

    $result = $service->start($network);

    expect($result)->toBeFalse();

    $network->refresh();
    expect($network->broadcast_requested)->toBeTrue();
    expect($network->broadcast_error)->toBe('Waiting for schedule data (boot recovery)...');
});

it('missing stream URL during grace period returns false and preserves broadcast_requested', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'broadcast_boot_recovery_until' => now()->addMinutes(2),
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
    $service->shouldReceive('getStreamUrl')->andReturn(null);

    $result = $service->start($network);

    expect($result)->toBeFalse();

    $network->refresh();
    expect($network->broadcast_requested)->toBeTrue();
    expect($network->broadcast_error)->toBe('Waiting for stream URL (boot recovery)...');
});

it('missing programme after grace period expires disables broadcast_requested', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'broadcast_boot_recovery_until' => now()->subSeconds(1),
    ]);

    // No programme created

    $service = Mockery::mock(NetworkBroadcastService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('isProcessRunning')->andReturn(false);

    $service->start($network);

    $network->refresh();
    expect($network->broadcast_requested)->toBeFalse();
});

it('missing stream URL after grace period expires disables broadcast_requested', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'broadcast_boot_recovery_until' => now()->subSeconds(1),
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
    $service->shouldReceive('getStreamUrl')->andReturn(null);

    $service->start($network);

    $network->refresh();
    expect($network->broadcast_requested)->toBeFalse();
});

it('successful start clears broadcast_boot_recovery_until', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'broadcast_boot_recovery_until' => now()->addMinutes(2),
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
    $service->shouldReceive('getStreamUrl')->andReturn('http://localhost:8096/video/stream.ts');
    $service->shouldReceive('startViaProxy')->once()->andReturn(true);

    $result = $service->start($network);

    expect($result)->toBeTrue();
    expect($network->fresh()->broadcast_boot_recovery_until)->toBeNull();
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
    $service->shouldReceive('startRequested')->once()->andReturn(true);

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

/*
|--------------------------------------------------------------------------
| Proxy 500 errors during boot grace period
|--------------------------------------------------------------------------
*/

it('treats proxy 500 as transient during boot grace period and preserves broadcast_requested', function () {
    // Replace the Http facade root with a fresh Factory to clear beforeEach stubs.
    // Http::fake stacks callbacks (first match wins), so we need a clean slate
    // to ensure the 500 response is returned instead of beforeEach's 200.
    Http::swap(new Factory);

    Http::fake([
        '*/broadcast/*/status' => Http::response(['status' => 'stopped'], 404),
        '*/broadcast/*/start' => Http::response(
            ['detail' => 'Broadcast failed: FFmpeg exited with code 8'],
            500
        ),
        '*' => Http::response([], 200),
    ]);

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'broadcast_boot_recovery_until' => now()->addMinutes(2),
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    // Use the real service but call startViaProxy directly to bypass getStreamUrl
    $service = app(NetworkBroadcastService::class);
    $method = new ReflectionMethod(NetworkBroadcastService::class, 'startViaProxy');

    $programme = NetworkProgramme::find($network->getCurrentProgramme()->id);
    $result = $method->invoke(
        $service,
        $network,
        'http://localhost:8096/video/stream.ts',
        300,
        3300,
        $programme,
    );

    // During boot grace period, proxy 500 should return null (transient)
    expect($result)->toBeNull();

    $network->refresh();
    expect($network->broadcast_error)->toContain('Proxy error');
});

it('treats proxy 500 as permanent failure when NOT in boot grace period', function () {
    // Replace the Http facade root with a fresh Factory to clear beforeEach stubs
    Http::swap(new Factory);

    Http::fake([
        '*/broadcast/*/status' => Http::response(['status' => 'stopped'], 404),
        '*/broadcast/*/start' => Http::response(
            ['detail' => 'Broadcast failed: FFmpeg exited with code 8'],
            500
        ),
        '*' => Http::response([], 200),
    ]);

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'broadcast_boot_recovery_until' => null, // No grace period
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $service = app(NetworkBroadcastService::class);
    $method = new ReflectionMethod(NetworkBroadcastService::class, 'startViaProxy');

    $programme = NetworkProgramme::find($network->getCurrentProgramme()->id);
    $result = $method->invoke(
        $service,
        $network,
        'http://localhost:8096/video/stream.ts',
        300,
        3300,
        $programme,
    );

    // Without grace period, proxy 500 should return false (permanent)
    expect($result)->toBeFalse();
});

it('treats proxy 500 as permanent failure when boot grace period has expired', function () {
    // Replace the Http facade root with a fresh Factory to clear beforeEach stubs
    Http::swap(new Factory);

    Http::fake([
        '*/broadcast/*/status' => Http::response(['status' => 'stopped'], 404),
        '*/broadcast/*/start' => Http::response(
            ['detail' => 'Broadcast failed: FFmpeg exited with code 8'],
            500
        ),
        '*' => Http::response([], 200),
    ]);

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'broadcast_boot_recovery_until' => now()->subSeconds(1), // Expired
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $service = app(NetworkBroadcastService::class);
    $method = new ReflectionMethod(NetworkBroadcastService::class, 'startViaProxy');

    $programme = NetworkProgramme::find($network->getCurrentProgramme()->id);
    $result = $method->invoke(
        $service,
        $network,
        'http://localhost:8096/video/stream.ts',
        300,
        3300,
        $programme,
    );

    // Expired grace period — should return false (permanent)
    expect($result)->toBeFalse();
});
