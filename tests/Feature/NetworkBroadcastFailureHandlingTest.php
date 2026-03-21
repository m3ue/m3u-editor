<?php

use App\Models\MediaServerIntegration;
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

    $integration = MediaServerIntegration::factory()->create([
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

/*
|--------------------------------------------------------------------------
| Boot recovery grace period: callback is fully deferred to tick loop
|--------------------------------------------------------------------------
*/

it('does not increment fail_count during boot recovery', function () {
    // During boot recovery, the callback handler must NOT increment fail_count.
    // The proxy sends TWO callbacks per failure (exit_code + input_error), which
    // would double-count failures and burn through max retries too fast.
    // The tick loop is the sole retry mechanism during boot recovery.
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now(),
        'broadcast_fail_count' => 4,
        'broadcast_boot_recovery_until' => now()->addMinutes(2),
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

    // fail_count should NOT be incremented — callback defers entirely during boot recovery
    expect($network->broadcast_fail_count)->toBe(4);
    // broadcast_requested should still be true (tick loop will retry)
    expect($network->broadcast_requested)->toBeTrue();
    // Error message should be updated for observability
    expect($network->broadcast_error)->toBe('Stream negotiation failed');
    // broadcast_last_exit_code should be updated for observability
    expect($network->broadcast_last_exit_code)->toBe(8);
});

it('callback skips retry and state changes during boot recovery', function () {
    // During boot recovery, the callback handler should return early without
    // modifying retry state. Only the error message and exit code are updated
    // for observability. The tick loop handles all retry logic.
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now(),
        'broadcast_fail_count' => 1,
        'broadcast_boot_recovery_until' => now()->addMinutes(2),
        'broadcast_transcode_session_id' => 'existing-session-123',
    ]);

    $programme = NetworkProgramme::factory()->create([
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

    // Fail count should NOT be incremented during boot recovery
    expect($network->broadcast_fail_count)->toBe(1);
    // broadcast_requested should still be true (tick loop will retry)
    expect($network->broadcast_requested)->toBeTrue();
    // Session ID should be preserved for reuse by tick loop
    expect($network->broadcast_transcode_session_id)->toBe('existing-session-123');
    // restart_locked should NOT be set (callback didn't enter the retry branch)
    expect($network->broadcast_restart_locked)->toBeFalse();
    // broadcast_pid and broadcast_started_at should be preserved (not cleared)
    expect($network->broadcast_pid)->toBe(5678);
    expect($network->broadcast_started_at)->not->toBeNull();
});

it('callback resumes normal fail_count tracking after grace period expires', function () {
    // Once the boot recovery grace period has expired, the callback handler should
    // resume normal behavior: increment fail_count, clean up sessions, and apply
    // the standard max retries logic.
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now(),
        'broadcast_fail_count' => 0,
        // Grace period already expired
        'broadcast_boot_recovery_until' => now()->subMinutes(1),
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

    // fail_count should be incremented normally after grace period
    expect($network->broadcast_fail_count)->toBe(1);
    // broadcast_requested should still be true (under max retries of 5)
    expect($network->broadcast_requested)->toBeTrue();
});

it('detail callback (error_type=input_error) does not increment fail_count', function () {
    // The proxy sends two callbacks per FFmpeg failure:
    // 1. Primary: exit_code=8, final_segment_number=N (the real exit)
    // 2. Detail: error_type="input_error" (supplementary context like "400 Bad Request")
    // Only the primary should increment fail_count. The detail callback should only
    // update the error message for observability.
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now(),
        'broadcast_fail_count' => 3,
    ]);

    // Send the detail callback (arrives alongside or just before the primary)
    $response = $this->postJson('/api/m3u-proxy/broadcast/callback', [
        'network_id' => $network->uuid,
        'event' => 'broadcast_failed',
        'data' => [
            'error' => '[in#0 @ 0x7951fef15940] Error opening input: Server returned 400 Bad Request',
            'error_type' => 'input_error',
        ],
    ]);

    $response->assertOk();

    $network->refresh();

    // fail_count should NOT be incremented by the detail callback
    expect($network->broadcast_fail_count)->toBe(3);
    // broadcast_requested should still be true
    expect($network->broadcast_requested)->toBeTrue();
    // Error message should be updated for observability
    expect($network->broadcast_error)->toContain('400 Bad Request');
    // broadcast_pid and broadcast_started_at should be preserved
    expect($network->broadcast_pid)->toBe(5678);
    expect($network->broadcast_started_at)->not->toBeNull();
});
