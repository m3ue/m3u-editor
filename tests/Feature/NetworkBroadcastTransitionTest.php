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
| Auto-transition (proxy-side seamless programme switch)
|--------------------------------------------------------------------------
*/

it('auto_transitioned callback updates DB with new pid without starting a new broadcast', function () {
    Carbon::setTestNow(now());

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now()->subMinutes(60),
        'broadcast_fail_count' => 2,
        'broadcast_segment_sequence' => 100,
    ]);

    // Next programme available for the reference update.
    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now(),
        'end_time' => now()->addMinutes(60),
        'duration_seconds' => 3600,
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class);
    $service->shouldReceive('cleanupTranscodeSession')->once();
    $service->shouldNotReceive('start');
    app()->instance(NetworkBroadcastService::class, $service);

    $response = $this->postJson('/api/m3u-proxy/broadcast/callback', [
        'network_id' => $network->uuid,
        'event' => 'programme_ended',
        'data' => [
            'final_segment_number' => 100,
            'auto_transitioned' => true,
            'new_pid' => 99999,
        ],
    ]);

    $response->assertOk();

    $network->refresh();
    expect($network->broadcast_pid)->toBe(99999);
    expect($network->broadcast_started_at)->not->toBeNull();
    expect($network->broadcast_segment_sequence)->toBe(101);
    expect($network->broadcast_fail_count)->toBe(0);
    expect($network->broadcast_transcode_session_id)->toBeNull();
    expect($network->broadcast_error)->toBeNull();

    Carbon::setTestNow();
});

it('auto_transitioned callback records next programme reference', function () {
    Carbon::setTestNow(now());

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now()->subMinutes(60),
    ]);

    $nextProgramme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now(),
        'end_time' => now()->addMinutes(60),
        'duration_seconds' => 3600,
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class);
    $service->shouldReceive('cleanupTranscodeSession')->once();
    $service->shouldNotReceive('start');
    app()->instance(NetworkBroadcastService::class, $service);

    $this->postJson('/api/m3u-proxy/broadcast/callback', [
        'network_id' => $network->uuid,
        'event' => 'programme_ended',
        'data' => [
            'final_segment_number' => 50,
            'auto_transitioned' => true,
            'new_pid' => 11111,
        ],
    ])->assertOk();

    $network->refresh();
    expect($network->broadcast_programme_id)->toBe($nextProgramme->id);

    Carbon::setTestNow();
});

it('auto_transitioned callback increments discontinuity sequence', function () {
    Carbon::setTestNow(now());

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now(),
        'broadcast_discontinuity_sequence' => 3,
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class);
    $service->shouldReceive('cleanupTranscodeSession')->once();
    $service->shouldNotReceive('start');
    app()->instance(NetworkBroadcastService::class, $service);

    $this->postJson('/api/m3u-proxy/broadcast/callback', [
        'network_id' => $network->uuid,
        'event' => 'programme_ended',
        'data' => [
            'final_segment_number' => 0,
            'auto_transitioned' => true,
            'new_pid' => 22222,
        ],
    ])->assertOk();

    expect($network->fresh()->broadcast_discontinuity_sequence)->toBe(4);

    Carbon::setTestNow();
});

it('auto_transitioned callback clears broadcast_restart_locked even if it was stuck true', function () {
    Carbon::setTestNow(now());

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now()->subMinutes(60),
        'broadcast_restart_locked' => true,
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class);
    $service->shouldReceive('cleanupTranscodeSession')->once();
    $service->shouldNotReceive('start');
    app()->instance(NetworkBroadcastService::class, $service);

    $this->postJson('/api/m3u-proxy/broadcast/callback', [
        'network_id' => $network->uuid,
        'event' => 'programme_ended',
        'data' => [
            'final_segment_number' => 10,
            'auto_transitioned' => true,
            'new_pid' => 33333,
        ],
    ])->assertOk();

    expect($network->fresh()->broadcast_restart_locked)->toBeFalse('auto-transition must clear a stuck restart lock');

    Carbon::setTestNow();
});

it('auto_transitioned callback resolves correct next programme when callback arrives before wall clock crosses end_time', function () {
    // Simulate a fast callback: the ended programme's end_time is still in the future
    // (i.e. the stream ended a couple of seconds early), so getCurrentProgramme() would
    // naively return the ended programme. The fix must skip it and find the real next one.
    Carbon::setTestNow(now());

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now()->subMinutes(60),
    ]);

    // Ended programme: end_time is 10 seconds in the future (callback arrived early)
    $endedProgramme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(60),
        'end_time' => now()->addSeconds(10),
        'duration_seconds' => 3610,
    ]);

    // Actual next programme
    $nextProgramme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->addSeconds(10),
        'end_time' => now()->addMinutes(70),
        'duration_seconds' => 3600,
    ]);

    // Set network's current broadcast_programme_id to the ended programme
    $network->update(['broadcast_programme_id' => $endedProgramme->id]);

    $service = Mockery::mock(NetworkBroadcastService::class);
    $service->shouldReceive('cleanupTranscodeSession')->once();
    $service->shouldNotReceive('start');
    app()->instance(NetworkBroadcastService::class, $service);

    $this->postJson('/api/m3u-proxy/broadcast/callback', [
        'network_id' => $network->uuid,
        'event' => 'programme_ended',
        'data' => [
            'final_segment_number' => 100,
            'auto_transitioned' => true,
            'new_pid' => 44444,
        ],
    ])->assertOk();

    // Must resolve to the *next* programme, not the ended one
    expect($network->fresh()->broadcast_programme_id)->toBe($nextProgramme->id);

    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| Normal programme transition (callback round-trip path)
|--------------------------------------------------------------------------
*/

it('normal programme_ended callback sets restart lock while starting next broadcast', function () {
    Carbon::setTestNow(now());

    $locked = false;

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now()->subMinutes(60),
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now(),
        'end_time' => now()->addMinutes(60),
        'duration_seconds' => 3600,
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class);
    $service->shouldReceive('cleanupTranscodeSession')->once();
    $service->shouldReceive('start')->once()->andReturnUsing(function (Network $n) use (&$locked) {
        $locked = $n->fresh()->broadcast_restart_locked;

        return true;
    });
    app()->instance(NetworkBroadcastService::class, $service);

    $this->postJson('/api/m3u-proxy/broadcast/callback', [
        'network_id' => $network->uuid,
        'event' => 'programme_ended',
        'data' => ['final_segment_number' => 100],
    ])->assertOk();

    expect($locked)->toBeTrue('restart lock should be held while start() runs');
    expect($network->fresh()->broadcast_restart_locked)->toBeFalse('lock should be released after start()');

    Carbon::setTestNow();
});

it('normal programme_ended callback releases restart lock even if start throws', function () {
    Carbon::setTestNow(now());

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => 5678,
        'broadcast_started_at' => now(),
    ]);

    NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now(),
        'end_time' => now()->addMinutes(60),
        'duration_seconds' => 3600,
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class);
    $service->shouldReceive('cleanupTranscodeSession')->once();
    $service->shouldReceive('start')->once()->andThrow(new RuntimeException('proxy unavailable'));
    app()->instance(NetworkBroadcastService::class, $service);

    $response = $this->postJson('/api/m3u-proxy/broadcast/callback', [
        'network_id' => $network->uuid,
        'event' => 'programme_ended',
        'data' => ['final_segment_number' => 10],
    ]);

    // Callback itself reports the exception.
    $response->assertStatus(500);

    // Lock must be released regardless.
    expect($network->fresh()->broadcast_restart_locked)->toBeFalse();

    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| computeNextStreamConfig
|--------------------------------------------------------------------------
*/

it('computeNextStreamConfig returns null when no next programme exists', function () {
    $network = Network::factory()->create(['broadcast_enabled' => true]);

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $service = app(NetworkBroadcastService::class);
    $method = new ReflectionMethod(NetworkBroadcastService::class, 'computeNextStreamConfig');
    $result = $method->invoke($service, $network, $programme);

    expect($result)->toBeNull();
});

it('next_stream_config key is always present in the broadcast start payload', function () {
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
    $method = new ReflectionMethod(NetworkBroadcastService::class, 'startViaProxy');
    $method->invoke($service, $network, 'http://example.com/stream.ts', 0, 3300, $programme);

    $captured = [];
    Http::assertSent(function ($request) use (&$captured) {
        if (str_contains($request->url(), '/broadcast/') && str_contains($request->url(), '/start')) {
            $captured = $request->data();

            return true;
        }

        return false;
    });

    // Key must always be present (null when no next programme).
    expect($captured)->toHaveKey('next_stream_config');
});

it('computeNextStreamConfig resolves audio_stream_index for the NEXT programme, not the current one', function () {
    $network = Network::factory()->create([
        'broadcast_enabled' => true,
        'preferred_audio_language' => 'eng',
    ]);

    $currentProgramme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinute(),
        'duration_seconds' => 360,
    ]);

    $nextProgramme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->addMinute(),
        'end_time' => now()->addMinutes(31),
        'duration_seconds' => 1800,
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('getStreamUrl')
        ->with($network, Mockery::on(fn ($p) => $p->is($nextProgramme)), 0)
        ->andReturn('http://example.com/next.ts');
    $service->shouldReceive('resolveSubtitleInfo')
        ->with($network, Mockery::on(fn ($p) => $p->is($nextProgramme)), 0)
        ->andReturn(['url' => null, 'language' => null, 'server_seeked' => false]);
    $service->shouldReceive('resolveAudioStreamIndex')
        ->once()
        ->with($network, Mockery::on(fn ($p) => $p->is($nextProgramme)))
        ->andReturn(7);

    $method = new ReflectionMethod(NetworkBroadcastService::class, 'computeNextStreamConfig');
    $result = $method->invoke($service, $network, $currentProgramme);

    expect($result['audio_stream_index'])->toBe(7);
});
