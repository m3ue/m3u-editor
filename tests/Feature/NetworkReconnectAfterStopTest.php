<?php

use App\Models\Network;
use App\Models\User;
use App\Services\NetworkBroadcastService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->user = User::factory()->create();
    config()->set('session.driver', 'array');
});

it('playlist returns 503 after broadcast is stopped', function () {
    Carbon::setTestNow(now());

    // Mock proxy returning 404 (broadcast not running)
    Http::fake([
        '*/broadcast/*/live.m3u8' => Http::response('Not found', 404),
        '*/broadcast/*/status' => Http::response(['status' => 'stopped'], 404),
        '*/broadcast/*/stop' => Http::response(['status' => 'stopped', 'final_segment_number' => 0], 200),
        '*/broadcast/*' => Http::response([], 200),
    ]);

    $network = Network::factory()->for($this->user)->create([
        'broadcast_enabled' => true,
        'enabled' => true,
        'broadcast_started_at' => now(),
        'broadcast_pid' => 999999,
    ]);

    // Stop the broadcast
    app(NetworkBroadcastService::class)->stop($network);

    // Verify playlist returns 503/404 (proxy returns 404)
    $playlistResp = $this->followingRedirects()->get(route('network.hls.playlist', ['network' => $network->uuid]));
    expect(in_array($playlistResp->getStatusCode(), [503, 404]))->toBeTrue();

    Carbon::setTestNow();
})->group('serial');

it('segment returns error after broadcast is stopped', function () {
    Carbon::setTestNow(now());

    // Mock proxy returning 404 for segment (broadcast not running)
    Http::fake([
        '*/broadcast/*/segment/*.ts' => Http::response('Not found', 404),
        '*/broadcast/*/status' => Http::response(['status' => 'stopped'], 404),
        '*/broadcast/*/stop' => Http::response(['status' => 'stopped', 'final_segment_number' => 0], 200),
        '*/broadcast/*' => Http::response([], 200),
    ]);

    $network = Network::factory()->for($this->user)->create([
        'broadcast_enabled' => true,
        'enabled' => true,
        'broadcast_started_at' => now(),
        'broadcast_pid' => 999999,
    ]);

    // Stop the broadcast
    app(NetworkBroadcastService::class)->stop($network);

    // Verify segment returns error (proxy returns 404)
    $segmentResp = $this->followingRedirects()->get(route('network.hls.segment', ['network' => $network->uuid, 'segment' => 'live000001']));
    expect(in_array($segmentResp->getStatusCode(), [503, 404]))->toBeTrue();

    Carbon::setTestNow();
})->group('serial');

it('playlist works while broadcast is running', function () {
    Carbon::setTestNow(now());

    // Mock proxy returning successful playlist
    Http::fake([
        '*/broadcast/*/live.m3u8' => Http::response("#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6,\nlive000001.ts\n", 200),
        '*/broadcast/*/status' => Http::response(['status' => 'running'], 200),
    ]);

    $network = Network::factory()->for($this->user)->create([
        'broadcast_enabled' => true,
        'enabled' => true,
        'broadcast_started_at' => now(),
        'broadcast_pid' => 999999,
    ]);

    // Verify playlist endpoint proxies content from the proxy service
    $playlistResp = $this->get(route('network.hls.playlist', ['network' => $network->uuid]));
    $playlistResp->assertStatus(200);
    $playlistResp->assertHeader('Content-Type', 'application/vnd.apple.mpegurl');

    // Verify the playlist contains valid HLS content (proxied from the mock)
    $content = $playlistResp->getContent();
    expect(str_contains($content, '#EXTM3U'))->toBeTrue();
    expect(str_contains($content, '#EXT-X-TARGETDURATION'))->toBeTrue();

    Carbon::setTestNow();
})->group('serial');

it('playlist touch starts on-demand requested broadcast', function () {
    Carbon::setTestNow(now());

    Http::fake([
        '*/broadcast/*/live.m3u8' => Http::response("#EXTM3U\n#EXT-X-TARGETDURATION:6\n", 200),
    ]);

    $network = Network::factory()->for($this->user)->create([
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'enabled' => true,
        'broadcast_started_at' => null,
        'broadcast_pid' => null,
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class);
    $service->shouldReceive('markConnectionSeen')->once()->andReturnUsing(function (Network $n): void {
        $n->update(['broadcast_last_connection_at' => now()]);
    });
    $service->shouldReceive('startNow')->once()->andReturnUsing(function (Network $n): bool {
        $n->update([
            'broadcast_started_at' => now(),
            'broadcast_pid' => 777,
        ]);

        return true;
    });

    app()->instance(NetworkBroadcastService::class, $service);

    $playlistResp = $this->get(route('network.hls.playlist', ['network' => $network->uuid]));
    $playlistResp->assertStatus(200);

    $network->refresh();
    expect($network->broadcast_last_connection_at)->not->toBeNull();

    Carbon::setTestNow();
})->group('serial');

it('playlist fetch does not refresh on-demand connection heartbeat while already running', function () {
    Carbon::setTestNow(now());

    Http::fake([
        '*/broadcast/*/live.m3u8' => Http::response("#EXTM3U\n#EXT-X-TARGETDURATION:6\n", 200),
    ]);

    $initialHeartbeat = now()->subMinutes(5);

    $network = Network::factory()->for($this->user)->create([
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'enabled' => true,
        'broadcast_started_at' => now()->subMinutes(10),
        'broadcast_pid' => 222,
        'broadcast_last_connection_at' => $initialHeartbeat,
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class);
    $service->shouldNotReceive('markConnectionSeen');
    $service->shouldNotReceive('startRequested');
    app()->instance(NetworkBroadcastService::class, $service);

    $playlistResp = $this->get(route('network.hls.playlist', ['network' => $network->uuid]));
    $playlistResp->assertStatus(200);

    expect($network->fresh()->broadcast_last_connection_at?->toIso8601String())
        ->toBe($initialHeartbeat->toIso8601String());

    Carbon::setTestNow();
})->group('serial');

it('waits briefly for first on-demand playlist after start', function () {
    Carbon::setTestNow(now());
    Sleep::fake();

    config()->set('proxy.broadcast_on_demand_startup_wait_seconds', 2);
    config()->set('proxy.broadcast_on_demand_startup_poll_ms', 100);
    config()->set('proxy.broadcast_on_demand_startup_min_segments', 3);

    Http::fake([
        '*/broadcast/*/live.m3u8' => Http::sequence()
            ->push('Not found', 404)
            ->push("#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6,\nlive000001.ts\n", 200)
            ->push("#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6,\nlive000001.ts\n#EXTINF:6,\nlive000002.ts\n", 200)
            ->push("#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6,\nlive000001.ts\n#EXTINF:6,\nlive000002.ts\n#EXTINF:6,\nlive000003.ts\n", 200),
    ]);

    $network = Network::factory()->for($this->user)->create([
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'enabled' => true,
        'broadcast_started_at' => null,
        'broadcast_pid' => null,
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class);
    $service->shouldReceive('markConnectionSeen')->once()->andReturnUsing(function (Network $n): void {
        $n->update([
            'broadcast_last_connection_at' => now(),
        ]);
    });
    $service->shouldReceive('startNow')->once()->andReturnUsing(function (Network $n): bool {
        $n->update([
            'broadcast_started_at' => now(),
            'broadcast_pid' => 9876,
        ]);

        return true;
    });

    app()->instance(NetworkBroadcastService::class, $service);

    $playlistResp = $this->get(route('network.hls.playlist', ['network' => $network->uuid]));
    $playlistResp->assertStatus(200);
    expect(str_contains($playlistResp->getContent(), '#EXTM3U'))->toBeTrue();

    Sleep::fake(false);
    Carbon::setTestNow();
})->group('serial');

it('playlist cold-start is skipped when lock is already held by a concurrent request', function () {
    Carbon::setTestNow(now());

    Http::fake([
        '*/broadcast/*/live.m3u8' => Http::response("#EXTM3U\n#EXT-X-TARGETDURATION:6\n", 200),
    ]);

    $network = Network::factory()->for($this->user)->create([
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'enabled' => true,
        'broadcast_started_at' => null,
        'broadcast_pid' => null,
    ]);

    // Simulate a concurrent request holding the startup lock
    $lock = Cache::lock("network.on_demand.start.{$network->id}", 10);
    $lock->get();

    $service = Mockery::mock(NetworkBroadcastService::class);
    $service->shouldNotReceive('startNow');
    $service->shouldNotReceive('markConnectionSeen');
    app()->instance(NetworkBroadcastService::class, $service);

    $playlistResp = $this->get(route('network.hls.playlist', ['network' => $network->uuid]));
    $playlistResp->assertStatus(200);

    $lock->release();
    Carbon::setTestNow();
})->group('serial');

it('waits for runway even when startup playlist initially returns 200 with too few segments', function () {
    Carbon::setTestNow(now());
    Sleep::fake();

    config()->set('proxy.broadcast_on_demand_startup_wait_seconds', 2);
    config()->set('proxy.broadcast_on_demand_startup_poll_ms', 100);
    config()->set('proxy.broadcast_on_demand_startup_min_segments', 3);

    Http::fake([
        '*/broadcast/*/live.m3u8' => Http::sequence()
            ->push("#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6,\nlive000001.ts\n", 200)
            ->push("#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6,\nlive000001.ts\n#EXTINF:6,\nlive000002.ts\n", 200)
            ->push("#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6,\nlive000001.ts\n#EXTINF:6,\nlive000002.ts\n#EXTINF:6,\nlive000003.ts\n", 200),
    ]);

    $network = Network::factory()->for($this->user)->create([
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_on_demand' => true,
        'enabled' => true,
        'broadcast_started_at' => now(),
        'broadcast_pid' => 9876,
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class);
    $service->shouldNotReceive('startNow');
    app()->instance(NetworkBroadcastService::class, $service);

    $playlistResp = $this->get(route('network.hls.playlist', ['network' => $network->uuid]));
    $playlistResp->assertStatus(200);
    expect(substr_count($playlistResp->getContent(), '.ts'))->toBeGreaterThanOrEqual(3);

    Sleep::fake(false);
    Carbon::setTestNow();
})->group('serial');
