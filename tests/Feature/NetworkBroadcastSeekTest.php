<?php

use App\Enums\TranscodeMode;
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

/**
 * Helper to invoke the protected startViaProxy method and capture the seek_seconds
 * sent to the proxy in the HTTP payload.
 */
function invokeStartViaProxyAndCaptureSeek(
    string $streamUrl,
    TranscodeMode $transcodeMode,
    int $seekPosition = 300,
): ?int {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'transcode_mode' => $transcodeMode->value,
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

    $method->invoke(
        $service,
        $network,
        $streamUrl,
        $seekPosition,
        3600 - $seekPosition,
        $programme,
    );

    // Extract seek_seconds from the HTTP request sent to proxy
    $capturedSeekSeconds = null;
    Http::assertSent(function ($request) use (&$capturedSeekSeconds) {
        if (str_contains($request->url(), '/broadcast/') && str_contains($request->url(), '/start')) {
            $capturedSeekSeconds = $request->data()['seek_seconds'] ?? null;

            return true;
        }

        return false;
    });

    return $capturedSeekSeconds;
}

it('sends FFmpeg seek for direct mode with Emby/Jellyfin StartTimeTicks URL', function () {
    $embyUrl = 'http://emby:8096/emby/Videos/12345/stream.ts?static=true&StartTimeTicks=3000000000&api_key=abc123';
    $seekSeconds = invokeStartViaProxyAndCaptureSeek($embyUrl, TranscodeMode::Direct, 300);

    expect($seekSeconds)->toBe(300);
});

it('skips FFmpeg seek for server transcode mode with StartTimeTicks URL', function () {
    $embyUrl = 'http://emby:8096/emby/Videos/12345/stream.ts?StartTimeTicks=3000000000&api_key=abc123';
    $seekSeconds = invokeStartViaProxyAndCaptureSeek($embyUrl, TranscodeMode::Server, 300);

    expect($seekSeconds)->toBe(0);
});

it('sends FFmpeg seek for direct mode with local media URL (no seek params)', function () {
    $localUrl = 'http://localhost:8080/media/stream/abc123.ts';
    $seekSeconds = invokeStartViaProxyAndCaptureSeek($localUrl, TranscodeMode::Direct, 450);

    expect($seekSeconds)->toBe(450);
});

it('sends FFmpeg seek for direct mode with Plex URL (no offset param)', function () {
    $plexUrl = 'http://plex:32400/library/parts/12345/file.ts?X-Plex-Token=abc123';
    $seekSeconds = invokeStartViaProxyAndCaptureSeek($plexUrl, TranscodeMode::Direct, 600);

    expect($seekSeconds)->toBe(600);
});

it('sends FFmpeg seek for local transcode mode with StartTimeTicks URL', function () {
    $embyUrl = 'http://emby:8096/emby/Videos/12345/stream.ts?static=true&StartTimeTicks=3000000000&api_key=abc123';
    $seekSeconds = invokeStartViaProxyAndCaptureSeek($embyUrl, TranscodeMode::Local, 300);

    expect($seekSeconds)->toBe(300);
});

it('sends zero FFmpeg seek when seek position is zero regardless of mode', function () {
    $embyUrl = 'http://emby:8096/emby/Videos/12345/stream.ts?static=true&StartTimeTicks=0&api_key=abc123';
    $seekSeconds = invokeStartViaProxyAndCaptureSeek($embyUrl, TranscodeMode::Direct, 0);

    expect($seekSeconds)->toBe(0);
});

it('skips FFmpeg seek for server transcode mode with Plex offset URL', function () {
    $plexUrl = 'http://plex:32400/video/:/transcode?offset=300&X-Plex-Token=abc123';
    $seekSeconds = invokeStartViaProxyAndCaptureSeek($plexUrl, TranscodeMode::Server, 300);

    expect($seekSeconds)->toBe(0);
});
