<?php

use App\Models\Network;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
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
    app(\App\Services\NetworkBroadcastService::class)->stop($network);

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
    app(\App\Services\NetworkBroadcastService::class)->stop($network);

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
