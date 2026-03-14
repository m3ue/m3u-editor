<?php

use App\Enums\TranscodeMode;
use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Services\NetworkBroadcastService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        '*/broadcast/*/status' => Http::response(['status' => 'stopped'], 404),
        '*/broadcast/*/stop' => Http::response(['status' => 'stopped', 'final_segment_number' => 0], 200),
        '*/broadcast/*/start' => Http::response(['status' => 'started', 'ffmpeg_pid' => 12345], 200),
        '*' => Http::response([], 200),
    ]);
});

/**
 * Helper: call startViaProxy and capture the full broadcast start request payload.
 */
function invokeStartViaProxyAndCapturePayload(array $networkAttrs = []): array
{
    $network = Network::factory()->create(array_merge([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'transcode_mode' => TranscodeMode::Direct->value,
    ], $networkAttrs));

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $service = app(NetworkBroadcastService::class);
    $method = new ReflectionMethod(NetworkBroadcastService::class, 'startViaProxy');
    $method->invoke($service, $network, 'http://example.com/stream.ts', 0, 3600, $programme);

    $captured = [];
    Http::assertSent(function ($request) use (&$captured) {
        if (str_contains($request->url(), '/broadcast/') && str_contains($request->url(), '/start')) {
            $captured = $request->data();

            return true;
        }

        return false;
    });

    return $captured;
}

it('sends output_dir in the broadcast start payload', function () {
    $payload = invokeStartViaProxyAndCapturePayload();

    expect($payload)->toHaveKey('output_dir');
});

it('sends output_dir defaulting to /dev/shm when BROADCAST_TEMP_DIR is not set', function () {
    Config::set('proxy.broadcast_temp_dir', '/dev/shm');

    $payload = invokeStartViaProxyAndCapturePayload();

    expect($payload['output_dir'])->toBe('/dev/shm');
});

it('honors BROADCAST_TEMP_DIR env var and sends it as output_dir', function () {
    Config::set('proxy.broadcast_temp_dir', '/run/broadcast-segments');

    $payload = invokeStartViaProxyAndCapturePayload();

    expect($payload['output_dir'])->toBe('/run/broadcast-segments');
});
