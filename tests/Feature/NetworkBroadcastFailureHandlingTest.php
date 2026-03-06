<?php

use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Services\NetworkBroadcastService;
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
| Fatal exit code handling (125, 126, 127)
|--------------------------------------------------------------------------
*/

it('stops retries on fatal exit code 127 (command not found)', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now(),
        'broadcast_fail_count' => 0,
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $response = $this->postJson('/api/m3u-proxy/broadcast/callback', [
        'network_id' => $network->uuid,
        'event' => 'broadcast_failed',
        'data' => [
            'exit_code' => 127,
            'error' => 'ffmpeg: command not found',
            'final_segment_number' => 10,
        ],
    ]);

    $response->assertOk();

    $network->refresh();

    expect($network->broadcast_requested)->toBeFalse();
    expect($network->broadcast_last_exit_code)->toBe(127);
    expect($network->broadcast_fail_count)->toBe(1);
    expect($network->broadcast_error)->toContain('Fatal error');
    expect($network->broadcast_error)->toContain('exit code 127');
});

it('stops retries on fatal exit code 126 (permission denied)', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now(),
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $response = $this->postJson('/api/m3u-proxy/broadcast/callback', [
        'network_id' => $network->uuid,
        'event' => 'broadcast_failed',
        'data' => [
            'exit_code' => 126,
            'error' => 'Permission denied',
            'final_segment_number' => 0,
        ],
    ]);

    $response->assertOk();

    $network->refresh();

    expect($network->broadcast_requested)->toBeFalse();
    expect($network->broadcast_last_exit_code)->toBe(126);
    expect($network->broadcast_error)->toContain('Fatal error');
});

it('stops retries on fatal exit code 125', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now(),
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $response = $this->postJson('/api/m3u-proxy/broadcast/callback', [
        'network_id' => $network->uuid,
        'event' => 'broadcast_failed',
        'data' => [
            'exit_code' => 125,
            'error' => 'Container command failed',
            'final_segment_number' => 0,
        ],
    ]);

    $response->assertOk();

    $network->refresh();

    expect($network->broadcast_requested)->toBeFalse();
    expect($network->broadcast_last_exit_code)->toBe(125);
});

/*
|--------------------------------------------------------------------------
| Max retries exceeded
|--------------------------------------------------------------------------
*/

it('stops retrying after max retries exceeded', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now(),
        'broadcast_fail_count' => 4, // Already failed 4 times, next will be 5th (= max)
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $response = $this->postJson('/api/m3u-proxy/broadcast/callback', [
        'network_id' => $network->uuid,
        'event' => 'broadcast_failed',
        'data' => [
            'exit_code' => 8,
            'error' => 'Stream negotiation failed',
            'final_segment_number' => 20,
        ],
    ]);

    $response->assertOk();

    $network->refresh();

    expect($network->broadcast_requested)->toBeFalse();
    expect($network->broadcast_fail_count)->toBe(5);
    expect($network->broadcast_error)->toContain('Failed after 5 retries');
});

/*
|--------------------------------------------------------------------------
| Transient exit code handling (retries allowed)
|--------------------------------------------------------------------------
*/

it('increments fail count and records exit code on transient failure', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now(),
        'broadcast_fail_count' => 0,
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    // Mock the service to avoid actual sleep in tests
    $service = Mockery::mock(NetworkBroadcastService::class)->makePartial();
    $service->shouldReceive('start')->once()->andReturn(true);
    app()->instance(NetworkBroadcastService::class, $service);

    $response = $this->postJson('/api/m3u-proxy/broadcast/callback', [
        'network_id' => $network->uuid,
        'event' => 'broadcast_failed',
        'data' => [
            'exit_code' => 8,
            'error' => 'Stream negotiation failed',
            'final_segment_number' => 10,
        ],
    ]);

    $response->assertOk();

    $network->refresh();

    expect($network->broadcast_fail_count)->toBe(1);
    expect($network->broadcast_last_exit_code)->toBe(8);
    expect($network->broadcast_last_failed_at)->not->toBeNull();
    // Should still be requested since it's transient and under max retries
    expect($network->broadcast_requested)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Retry counter reset on success
|--------------------------------------------------------------------------
*/

it('resets fail count on successful programme transition', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now(),
        'broadcast_fail_count' => 3,
        'broadcast_last_exit_code' => 8,
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $response = $this->postJson('/api/m3u-proxy/broadcast/callback', [
        'network_id' => $network->uuid,
        'event' => 'programme_ended',
        'data' => [
            'final_segment_number' => 520,
            'duration_streamed' => 3600.5,
        ],
    ]);

    $response->assertOk();

    $network->refresh();

    expect($network->broadcast_fail_count)->toBe(0);
    expect($network->broadcast_last_exit_code)->toBeNull();
});

it('resets fail count when stop is called', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now(),
        'broadcast_fail_count' => 3,
        'broadcast_last_exit_code' => 8,
        'broadcast_restart_locked' => true,
    ]);

    $service = app(NetworkBroadcastService::class);
    $service->stop($network);

    $network->refresh();

    expect($network->broadcast_fail_count)->toBe(0);
    expect($network->broadcast_last_exit_code)->toBeNull();
    expect($network->broadcast_restart_locked)->toBeFalse();
});

it('resets fail count on boot recovery', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => false,
        'broadcast_fail_count' => 5,
        'broadcast_last_exit_code' => 127,
        'broadcast_restart_locked' => true,
    ]);

    $service = app(NetworkBroadcastService::class);
    $service->performBootRecovery($network);

    $network->refresh();

    expect($network->broadcast_fail_count)->toBe(0);
    expect($network->broadcast_last_exit_code)->toBeNull();
    expect($network->broadcast_restart_locked)->toBeFalse();
    expect($network->broadcast_requested)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Restart lock (tick loop guard)
|--------------------------------------------------------------------------
*/

it('tick skips restart when broadcast_restart_locked is true', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'broadcast_restart_locked' => true,
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
    // start() should NOT be called because restart is locked
    $service->shouldNotReceive('start');

    $result = $service->tick($network);

    expect($result['action'])->toBe('restart_locked');
});

/*
|--------------------------------------------------------------------------
| Heal command consistency
|--------------------------------------------------------------------------
*/

it('heal command clears both broadcast_pid and broadcast_started_at', function () {
    $network = Network::factory()->create([
        'broadcast_enabled' => true,
        'broadcast_pid' => 999999,
        'broadcast_started_at' => now(),
        'broadcast_restart_locked' => true,
    ]);

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    // Mock the service to simulate non-running process and successful restart
    $this->mock(NetworkBroadcastService::class, function ($mock) {
        $mock->shouldReceive('isProcessRunning')->andReturn(false);
        $mock->shouldReceive('start')->andReturn(true);
    });

    $this->artisan('network:broadcast:heal')->assertExitCode(0);

    $network->refresh();

    expect($network->broadcast_pid)->toBeNull();
    expect($network->broadcast_started_at)->toBeNull();
    expect($network->broadcast_restart_locked)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Plex transcode session cleanup
|--------------------------------------------------------------------------
*/

it('saves transcode session ID from stream URL on successful start', function () {
    $sessionId = bin2hex(random_bytes(8));
    $streamUrl = "http://plex:32400/video/:/transcode/universal/start.m3u8?session={$sessionId}&X-Plex-Token=abc123";

    // Mock proxy to return success and include the session in the URL
    Http::fake([
        '*/broadcast/*/status' => Http::response(['status' => 'stopped'], 404),
        '*/broadcast/*/start' => Http::response(['status' => 'started', 'ffmpeg_pid' => 12345], 200),
        '*' => Http::response([], 200),
    ]);

    $integration = \App\Models\MediaServerIntegration::factory()->create([
        'type' => 'plex',
        'host' => 'plex',
        'port' => 32400,
        'api_key' => 'abc123',
    ]);

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'media_server_integration_id' => $integration->id,
    ]);

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    // Use a partial mock to control getStreamUrl to return our crafted URL
    $service = Mockery::mock(NetworkBroadcastService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('getStreamUrl')->andReturn($streamUrl);

    $service->start($network);

    $network->refresh();

    expect($network->broadcast_transcode_session_id)->toBe($sessionId);
});

it('clears transcode session ID on stop', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now(),
        'broadcast_transcode_session_id' => 'abc123session',
    ]);

    $service = app(NetworkBroadcastService::class);
    $service->stop($network);

    $network->refresh();

    expect($network->broadcast_transcode_session_id)->toBeNull();
});

it('clears transcode session ID on programme ended', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now(),
        'broadcast_transcode_session_id' => 'session_to_clear',
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $response = $this->postJson('/api/m3u-proxy/broadcast/callback', [
        'network_id' => $network->uuid,
        'event' => 'programme_ended',
        'data' => [
            'final_segment_number' => 100,
            'duration_streamed' => 3600.0,
        ],
    ]);

    $response->assertOk();

    $network->refresh();

    expect($network->broadcast_transcode_session_id)->toBeNull();
});

it('clears transcode session ID on broadcast failed', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now(),
        'broadcast_transcode_session_id' => 'session_to_clear',
        'broadcast_fail_count' => 4, // Will hit max retries
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $response = $this->postJson('/api/m3u-proxy/broadcast/callback', [
        'network_id' => $network->uuid,
        'event' => 'broadcast_failed',
        'data' => [
            'exit_code' => 8,
            'error' => 'Stream error',
            'final_segment_number' => 50,
        ],
    ]);

    $response->assertOk();

    $network->refresh();

    expect($network->broadcast_transcode_session_id)->toBeNull();
});

it('clears transcode session ID on boot recovery', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => false,
        'broadcast_transcode_session_id' => 'stale_session',
    ]);

    $service = app(NetworkBroadcastService::class);
    $service->performBootRecovery($network);

    $network->refresh();

    expect($network->broadcast_transcode_session_id)->toBeNull();
});

it('clears transcode session ID on heal command', function () {
    $network = Network::factory()->create([
        'broadcast_enabled' => true,
        'broadcast_pid' => 999999,
        'broadcast_started_at' => now(),
        'broadcast_transcode_session_id' => 'stale_heal_session',
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $this->mock(NetworkBroadcastService::class, function ($mock) {
        $mock->shouldReceive('isProcessRunning')->andReturn(false);
        $mock->shouldReceive('start')->andReturn(true);
    });

    $this->artisan('network:broadcast:heal')->assertExitCode(0);

    $network->refresh();

    expect($network->broadcast_transcode_session_id)->toBeNull();
});
