<?php

use App\Models\MediaServerIntegration;
use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Services\EmbyJellyfinService;
use App\Services\NetworkBroadcastService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Http::fake([
        '*/broadcast/*/status' => Http::response(['status' => 'stopped'], 404),
        '*/broadcast/*/start' => Http::response([
            'status' => 'running',
            'ffmpeg_pid' => 12345,
        ], 200),
        '*' => Http::response([], 200),
    ]);
});

it('waits for connection in on-demand mode during worker tick', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'broadcast_last_connection_at' => null,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
    ]);

    $service = app(NetworkBroadcastService::class);
    $result = $service->tick($network);

    expect($result['action'])->toBe('waiting_for_connection');
    expect($network->fresh()->broadcast_pid)->toBeNull();
});

it('manual start bypasses on-demand waiting and starts broadcast', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'broadcast_last_connection_at' => null,
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class, function ($mock) use ($network) {
        $mock->makePartial();
        $mock->shouldAllowMockingProtectedMethods();
        $mock->shouldReceive('startInternal')->once()->with($network, true)->andReturn(true);
    });

    $result = $service->startNow($network);

    expect($result)->toBeTrue();
});

it('marks waiting state on model when requested and on-demand with no connection', function () {
    $network = Network::factory()->create([
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'broadcast_started_at' => null,
        'broadcast_pid' => null,
        'broadcast_last_connection_at' => null,
    ]);

    expect($network->isWaitingForConnection())->toBeTrue();
});

it('stops running on-demand broadcast after disconnect window but keeps request state', function () {
    config()->set('proxy.broadcast_on_demand_disconnect_seconds', 120);
    config()->set('proxy.broadcast_on_demand_overlap_seconds', 30);
    config()->set('proxy.broadcast_on_demand_startup_grace_seconds', 0);

    Http::fake([
        '*/broadcast/*/stop' => Http::response(['status' => 'stopped', 'final_segment_number' => 0], 200),
        '*/broadcast/*' => Http::response([], 200),
    ]);

    Carbon::setTestNow(now());

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'broadcast_started_at' => now()->subMinutes(10),
        'broadcast_pid' => 12345,
        'broadcast_last_connection_at' => now()->subMinutes(4),
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(20),
        'end_time' => now()->addMinutes(20),
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class, function ($mock): void {
        $mock->makePartial();
        $mock->shouldAllowMockingProtectedMethods();
        $mock->shouldReceive('isProcessRunning')->andReturn(true);
        $mock->shouldReceive('stop')
            ->once()
            ->withArgs(fn (Network $n, bool $keepRequested = false, bool $preservePlaybackReference = false): bool => $keepRequested && $preservePlaybackReference
            )
            ->andReturnUsing(function (Network $n, bool $keepRequested = false, bool $preservePlaybackReference = false): bool {
                return (new NetworkBroadcastService)->stop($n, $keepRequested, $preservePlaybackReference);
            });
    });

    $result = $service->tick($network);

    expect($result['action'])->toBe('stopped_waiting_for_connection');
    expect($network->fresh()->broadcast_requested)->toBeTrue();
    expect($network->fresh()->broadcast_pid)->toBeNull();

    Carbon::setTestNow();
});

it('does not stop running on-demand broadcast during startup grace without heartbeat', function () {
    config()->set('proxy.broadcast_on_demand_disconnect_seconds', 120);
    config()->set('proxy.broadcast_on_demand_overlap_seconds', 30);
    config()->set('proxy.broadcast_on_demand_startup_grace_seconds', 30);

    Carbon::setTestNow(now());

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'broadcast_started_at' => now()->subSeconds(10),
        'broadcast_pid' => 12345,
        'broadcast_last_connection_at' => null,
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(20),
        'end_time' => now()->addMinutes(20),
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class, function ($mock): void {
        $mock->makePartial();
        $mock->shouldAllowMockingProtectedMethods();
        $mock->shouldReceive('isProcessRunning')->andReturn(true);
        $mock->shouldNotReceive('stop');
    });

    $result = $service->tick($network);

    expect($result['action'])->toBe('monitoring');

    Carbon::setTestNow();
});

it('treats startup as running when local pid exists during startup grace', function () {
    config()->set('proxy.broadcast_on_demand_startup_grace_seconds', 30);

    Carbon::setTestNow(now());

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'broadcast_started_at' => now()->subSeconds(5),
        'broadcast_pid' => 7654,
    ]);

    Http::fake([
        '*/broadcast/*/status' => Http::response(['status' => 'stopped'], 404),
    ]);

    $service = app(NetworkBroadcastService::class);

    expect($service->isProcessRunning($network))->toBeTrue();

    Carbon::setTestNow();
});

it('preserves playback timeline across on-demand idle stop and reconnect start', function () {
    Carbon::setTestNow(now());

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'broadcast_started_at' => now()->subMinutes(5),
        'broadcast_initial_offset_seconds' => 300,
        'broadcast_pid' => 43210,
        'broadcast_last_connection_at' => now()->subMinutes(2),
    ]);

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(20),
        'end_time' => now()->addMinutes(20),
    ]);

    $network->update([
        'broadcast_programme_id' => $programme->id,
    ]);

    $service = app(NetworkBroadcastService::class);
    $service->stop($network, keepRequested: true, preservePlaybackReference: true);

    $network->refresh();

    expect($network->broadcast_requested)->toBeTrue();
    expect($network->broadcast_programme_id)->toBe($programme->id);
    expect($network->broadcast_initial_offset_seconds)->toBe(600);

    Carbon::setTestNow(now()->addSeconds(30));

    expect($network->fresh()->getPersistedBroadcastSeekForNow())->toBe(630);

    Carbon::setTestNow();
});

it('logs a warning and resets seek to 0 when no programme is active during playback reference preserve', function () {
    Carbon::setTestNow(now());
    Log::spy();

    // Network that was broadcasting but has no active programme (e.g. schedule gap)
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'broadcast_started_at' => now()->subMinutes(5),
        'broadcast_pid' => 43210,
        // No persisted initial offset — forces the fallback path
        'broadcast_initial_offset_seconds' => null,
        'broadcast_programme_id' => null,
    ]);

    // Deliberately create no NetworkProgramme so getCurrentProgramme() returns null

    $service = app(NetworkBroadcastService::class);
    $service->stop($network, keepRequested: true, preservePlaybackReference: true);

    // Without a programme, the preserve branch is skipped (resumeProgrammeId stays null)
    // and a full stop is performed — but the warning must still be emitted.
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'No active programme'));

    $network->refresh();
    // Full stop: broadcast_started_at cleared, offset cleared
    expect($network->broadcast_started_at)->toBeNull();
    expect($network->broadcast_initial_offset_seconds)->toBeNull();
    expect($network->broadcast_requested)->toBeTrue();

    Carbon::setTestNow();
});

it('preserves static direct stream for emby even with internal options', function () {
    $integration = new MediaServerIntegration([
        'type' => 'emby',
        'host' => 'emby.local',
        'port' => 8096,
        'ssl' => false,
        'api_key' => 'emby-token',
    ]);

    Http::fake([
        'http://emby.local:8096/Users' => Http::response([
            ['Id' => 'user-1', 'Policy' => ['IsAdministrator' => true]],
        ], 200),
    ]);

    $service = EmbyJellyfinService::make($integration);
    $request = new Request;
    $request->merge([
        'StartTimeTicks' => 300000000,
    ]);

    $url = $service->getDirectStreamUrl($request, 'item-123', 'ts', [
        'skip_plex_transcode' => true,
        'session_id' => 'abc123',
    ]);

    expect($url)->toContain('/Videos/item-123/stream.ts');
    expect($url)->toContain('static=true');
    expect($url)->toContain('StartTimeTicks=300000000');
});
