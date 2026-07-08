<?php

use App\Enums\TranscodeMode;
use App\Models\MediaServerIntegration;
use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Services\NetworkBroadcastService;
use Illuminate\Http\Client\Factory;
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
function invokeStartViaProxyAndCapturePayload(array $networkAttrs = [], string $streamUrl = 'http://example.com/stream.ts', int $seekPosition = 0): array
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
    $method->invoke($service, $network, $streamUrl, $seekPosition, 3600, $programme);

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

    expect($payload)->toHaveKey('output_dir')
        ->and($payload['output_dir'])->toBe(config('proxy.broadcast_temp_dir'));
});

it('sends required broadcast fields in the payload', function () {
    $payload = invokeStartViaProxyAndCapturePayload();

    expect($payload)
        ->toHaveKey('stream_url')
        ->toHaveKey('segment_start_number')
        ->toHaveKey('callback_url');
});

it('sends subtitles_enabled true for direct mode when the toggle is on', function () {
    $payload = invokeStartViaProxyAndCapturePayload([
        'transcode_mode' => TranscodeMode::Direct->value,
        'subtitles_enabled' => true,
    ]);

    expect($payload['subtitles_enabled'])->toBeTrue();
});

it('forces subtitles_enabled false in Server transcode mode even when the toggle is on', function () {
    $payload = invokeStartViaProxyAndCapturePayload([
        'transcode_mode' => TranscodeMode::Server->value,
        'subtitles_enabled' => true,
    ]);

    expect($payload['subtitles_enabled'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Seek handling (avoid double-seeking video while subtitles need the
| full offset — see NetworkBroadcastService::startViaProxy)
|--------------------------------------------------------------------------
*/

it('applies the full ffmpeg seek for a static Direct-mode URL that does not seek server-side', function () {
    $payload = invokeStartViaProxyAndCapturePayload(
        ['transcode_mode' => TranscodeMode::Direct->value],
        'http://emby.local/Videos/1/stream.ts?api_key=abc&static=true',
        2715,
    );

    expect($payload['seek_seconds'])->toBe(2715)
        ->and($payload['subtitle_seek_seconds'])->toBe(2715);
});

it('rewrites a VideoCodec=copy remux URL to static when seek is required, so Emby server-seeks via StartTimeTicks', function () {
    // Uses an explicit Mockery setup (not invokeStartViaProxyAndCapturePayload) because
    // this test needs to control resolveAudioStreamIndex and resolveSubtitleInfo to
    // assert the post-rewrite contracts on audio_stream_index and subtitle_seek_seconds.
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'transcode_mode' => TranscodeMode::Direct->value,
        'subtitles_enabled' => true,
    ]);

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    // Emby resolved the preferred-language track at absolute MediaStreams index 1.
    $service->shouldReceive('resolveAudioStreamIndex')->with($network)->andReturn(1);
    // Subtitle URL is also server-pre-seeked by Emby (Jul 6 single-authority fix).
    $service->shouldReceive('resolveSubtitleInfo')->andReturn([
        'url' => 'http://emby.local/Videos/1/ms_1/Subtitles/2/27150000000/Stream.srt?api_key=abc',
        'language' => 'eng',
        'server_seeked' => true,
    ]);
    $service->shouldReceive('computeNextStreamConfig')->andReturn(null);

    $method = new ReflectionMethod(NetworkBroadcastService::class, 'startViaProxy');
    $method->invoke(
        $service,
        $network,
        // Remux URL with StartTimeTicks — would normally break seek (Emby ignores
        // StartTimeTicks on the remux endpoint). The PHP side must rewrite it.
        'http://emby.local/Videos/1/stream.ts?api_key=abc&StartTimeTicks=27150000000&AudioStreamIndex=1&VideoCodec=copy',
        2715,
        3600,
        $programme,
    );

    $captured = [];
    Http::assertSent(function ($request) use (&$captured) {
        if (str_contains($request->url(), '/broadcast/') && str_contains($request->url(), '/start')) {
            $captured = $request->data();

            return true;
        }

        return false;
    });

    // URL rewritten to seek-capable static endpoint:
    //   - VideoCodec=copy removed (Emby ignores StartTimeTicks on remux and Range is unsupported)
    //   - StartTimeTicks removed (Emby ALSO ignores it on static — verified by md5 on real Emby).
    //     The static endpoint is seekable via Range requests, so ffmpeg input -ss does the seek.
    //   - AudioStreamIndex kept (no-op on static but harmless)
    //   - static=true added (the static endpoint key for Range support)
    expect($captured['stream_url'])->not->toContain('VideoCodec=copy')
        ->and($captured['stream_url'])->not->toContain('StartTimeTicks')
        ->and($captured['stream_url'])->toContain('AudioStreamIndex=1')
        ->and($captured['stream_url'])->toContain('static=true')
        // Static doesn't server-seek (Emby ignores StartTimeTicks) -> ffmpeg MUST seek via input -ss.
        ->and($captured['seek_seconds'])->toBe(2715)
        // Static preserves all original streams; resolved absolute MediaStreams index is the
        // correct ffmpeg -map target (replaces the old "remux -> audio at position 0" rule).
        ->and($captured['audio_stream_index'])->toBe(1)
        // Subtitle URL was already server-pre-seeked by Emby (Jul 6 single-authority fix).
        ->and($captured['subtitle_seek_seconds'])->toBe(0);
});

it('keeps a VideoCodec=copy remux URL unchanged when no seek is required (programme start)', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'transcode_mode' => TranscodeMode::Direct->value,
    ]);

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    // Emby resolved the preferred-language track at absolute MediaStreams index 1.
    $service->shouldReceive('resolveAudioStreamIndex')->with($network)->andReturn(1);
    $service->shouldReceive('resolveSubtitleInfo')->andReturn(['url' => null, 'language' => null, 'server_seeked' => false]);
    $service->shouldReceive('computeNextStreamConfig')->andReturn(null);

    $method = new ReflectionMethod(NetworkBroadcastService::class, 'startViaProxy');
    $method->invoke(
        $service,
        $network,
        // No seek at all — broadcast begins from the start of the programme. The remux URL
        // is fine as-is; we shouldn't waste a rewrite.
        'http://emby.local/Videos/1/stream.ts?api_key=abc&AudioStreamIndex=1&VideoCodec=copy',
        0,
        3600,
        $programme,
    );

    $captured = [];
    Http::assertSent(function ($request) use (&$captured) {
        if (str_contains($request->url(), '/broadcast/') && str_contains($request->url(), '/start')) {
            $captured = $request->data();

            return true;
        }

        return false;
    });

    // URL untouched (no StartTimeTicks to honor, no rewrite needed).
    expect($captured['stream_url'])->toContain('VideoCodec=copy')
        ->and($captured['seek_seconds'])->toBe(0)
        // AudioStreamIndex on a remux means the server already remuxed to a single
        // audio track at position 0 — ffmpeg must map 0:a:0.
        ->and($captured['audio_stream_index'])->toBe(0);
});

it('keeps a VideoCodec=copy remux URL unchanged when no seek is required', function () {
    // No seek at all — broadcast begins from the start of the programme. The remux URL
    // is fine as-is; we shouldn't waste a rewrite or strip server-side audio selection.
    //
    // This test requires mocking resolveAudioStreamIndex because invokeStartViaProxyAndCapturePayload
    // doesn't mock it (and the real resolver would need an Emby item with the right MediaStreams).
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'transcode_mode' => TranscodeMode::Direct->value,
    ]);

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    // Without the rewrite, the remux pre-selects a single audio track at position 0.
    $service->shouldReceive('resolveAudioStreamIndex')->with($network)->andReturn(1);
    $service->shouldReceive('resolveSubtitleInfo')->andReturn(['url' => null, 'language' => null, 'server_seeked' => false]);
    $service->shouldReceive('computeNextStreamConfig')->andReturn(null);

    $method = new ReflectionMethod(NetworkBroadcastService::class, 'startViaProxy');
    $method->invoke(
        $service,
        $network,
        // No StartTimeTicks in URL, no seek passed -> no rewrite triggers.
        'http://emby.local/Videos/1/stream.ts?api_key=abc&AudioStreamIndex=1&VideoCodec=copy',
        0,
        3600,
        $programme,
    );

    $captured = [];
    Http::assertSent(function ($request) use (&$captured) {
        if (str_contains($request->url(), '/broadcast/') && str_contains($request->url(), '/start')) {
            $captured = $request->data();

            return true;
        }

        return false;
    });

    // URL untouched (no StartTimeTicks to rewrite around, no seek required).
    expect($captured['stream_url'])->toContain('VideoCodec=copy')
        ->and($captured['stream_url'])->not->toContain('StartTimeTicks')
        ->and($captured['seek_seconds'])->toBe(0)
        // Remux pre-selected audio -> ffmpeg must map the single track at position 0.
        ->and($captured['audio_stream_index'])->toBe(0);
});

it('skips the redundant ffmpeg seek for Server transcode mode URLs that already seeked server-side', function () {
    $payload = invokeStartViaProxyAndCapturePayload(
        ['transcode_mode' => TranscodeMode::Server->value],
        'http://emby.local/Videos/1/master.m3u8?api_key=abc&StartTimeTicks=27150000000',
        2715,
    );

    expect($payload['seek_seconds'])->toBe(0)
        ->and($payload['subtitle_seek_seconds'])->toBe(2715);
});

it('zeroes subtitle_seek_seconds when the subtitle url was already seeked server-side', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'transcode_mode' => TranscodeMode::Direct->value,
        'subtitles_enabled' => true,
    ]);

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('resolveAudioStreamIndex')->andReturn(null);
    $service->shouldReceive('computeNextStreamConfig')->andReturn(null);
    // Emby served the subtitle already seeked server-side (startPositionTicks), so it
    // shares the video's timeline origin and the proxy must not seek it again.
    $service->shouldReceive('resolveSubtitleInfo')->andReturn([
        'url' => 'http://emby.local/Videos/1/ms_1/Subtitles/2/27150000000/Stream.srt?api_key=abc',
        'language' => 'eng',
        'server_seeked' => true,
    ]);

    $method = new ReflectionMethod(NetworkBroadcastService::class, 'startViaProxy');
    $method->invoke(
        $service,
        $network,
        'http://emby.local/Videos/1/stream.ts?api_key=abc&StartTimeTicks=27150000000&VideoCodec=copy',
        2715,
        3600,
        $programme,
    );

    $captured = [];
    Http::assertSent(function ($request) use (&$captured) {
        if (str_contains($request->url(), '/broadcast/') && str_contains($request->url(), '/start')) {
            $captured = $request->data();

            return true;
        }

        return false;
    });

    // Video remux URL is rewritten to static (StartTimeTicks stripped) so ffmpeg can
    // input-seek via Range. Subtitle URL is independently server-pre-seeked by Emby
    // (Jul 6 single-authority fix), so its seek stays 0.
    expect($captured['seek_seconds'])->toBe(2715)
        ->and($captured['subtitle_seek_seconds'])->toBe(0)
        ->and($captured['subtitle_url'])->toContain('/27150000000/Stream.srt');
});

/**
 * Replace the HTTP factory's stub callbacks with a single dispatcher closure so a
 * specific URL wins over the catch-all `*` pattern installed in beforeEach().
 * Http::fake() only appends to the callback list — Laravel's stub pipeline calls
 * every callback and uses ->first(), so a catch-all registered first wins against
 * any specific pattern appended later. Reflection-based replacement is the only
 * way to guarantee our specific patterns match for tests that exercise the real
 * EmbyJellyfinService HTTP layer.
 */
function replaceHttpStubsWith(callable $dispatcher): void
{
    $factory = app(Factory::class);
    $reflection = new ReflectionProperty($factory, 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue($factory, collect([
        function ($request, $options) use ($dispatcher) {
            return $dispatcher($request, $options);
        },
    ]));
}

it('appends a #range= byte offset to the rewritten-static URL when seek is required and size meta is available', function () {
    // Sizes chosen so the math is easy to verify by hand: intval((2715/5102.306)*4083507852).
    // Computed value: 2172884930 (PHP intval truncates the fractional part).
    $runtimeSeconds = 5102.306;
    $totalBytes = 4083507852;
    $seekSeconds = 2715;
    $expectedOffset = intval(($seekSeconds / $runtimeSeconds) * $totalBytes);

    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'transcode_mode' => TranscodeMode::Direct->value,
        'subtitles_enabled' => false,
    ]);

    $integration = MediaServerIntegration::factory()->create([
        'type' => 'emby',
        'enabled' => true,
    ]);
    $network->update(['media_server_integration_id' => $integration->id]);
    $network->refresh();

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    // Stub the HTTP layer so the real EmbyJellyfinService::getStreamByteSize() can run
    // and get a deterministic Content-Length + RunTimeTicks. MediaStreams must be
    // non-empty or fetchItemWithMediaStreams() will retry and warn. See replaceHttpStubsWith()
    // for why we replace the stub callbacks rather than append via Http::fake().
    replaceHttpStubsWith(function ($request) use ($totalBytes) {
        $url = (string) $request->url();

        if (str_contains($url, '/Videos/42/stream.ts')) {
            return Http::response('', 200, ['Content-Length' => (string) $totalBytes]);
        }

        if (str_contains($url, '/Items')) {
            return Http::response(['Items' => [['Id' => '42', 'RunTimeTicks' => 51023060000, 'MediaStreams' => [['Index' => 0]]]]], 200);
        }

        return Http::response([], 200);
    });

    $service = Mockery::mock(NetworkBroadcastService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('resolveAudioStreamIndex')->andReturn(null);
    $service->shouldReceive('resolveSubtitleInfo')->andReturn(['url' => null, 'language' => null, 'server_seeked' => false]);
    $service->shouldReceive('computeNextStreamConfig')->andReturn(null);
    $service->shouldReceive('getMediaServerItemId')->andReturn('42');

    $method = new ReflectionMethod(NetworkBroadcastService::class, 'startViaProxy');
    $method->invoke(
        $service,
        $network,
        // Remux URL with StartTimeTicks — fires the rewrite to the static endpoint.
        'http://emby.local/Videos/42/stream.ts?api_key=abc&StartTimeTicks=27150000000&AudioStreamIndex=1&VideoCodec=copy',
        $seekSeconds,
        3600,
        $programme,
    );

    $captured = [];
    Http::assertSent(function ($request) use (&$captured) {
        if (str_contains($request->url(), '/broadcast/') && str_contains($request->url(), '/start')) {
            $captured = $request->data();

            return true;
        }

        return false;
    });

    expect($captured['stream_url'])
        ->toContain('static=true')
        ->not->toContain('VideoCodec=copy')
        ->not->toContain('StartTimeTicks')
        ->toContain('#range='.$expectedOffset.'-');
});

it('omits the #range= byte offset on the rewritten-static URL when size meta is unavailable', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'transcode_mode' => TranscodeMode::Direct->value,
        'subtitles_enabled' => false,
    ]);

    $integration = MediaServerIntegration::factory()->create([
        'type' => 'emby',
        'enabled' => true,
    ]);
    $network->update(['media_server_integration_id' => $integration->id]);
    $network->refresh();

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    // 404 on the HEAD forces getStreamByteSize() to return null. The /Items GET is
    // not reached because the byte-size lookup short-circuits before the retry loop
    // calls fetchItemWithMediaStreams(), but we fake it anyway to be safe.
    replaceHttpStubsWith(function ($request) {
        $url = (string) $request->url();

        if (str_contains($url, '/Videos/42/stream.ts')) {
            return Http::response('', 404);
        }

        return Http::response([], 200);
    });

    $service = Mockery::mock(NetworkBroadcastService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('resolveAudioStreamIndex')->andReturn(null);
    $service->shouldReceive('resolveSubtitleInfo')->andReturn(['url' => null, 'language' => null, 'server_seeked' => false]);
    $service->shouldReceive('computeNextStreamConfig')->andReturn(null);
    $service->shouldReceive('getMediaServerItemId')->andReturn('42');

    $method = new ReflectionMethod(NetworkBroadcastService::class, 'startViaProxy');
    $method->invoke(
        $service,
        $network,
        'http://emby.local/Videos/42/stream.ts?api_key=abc&StartTimeTicks=27150000000&AudioStreamIndex=1&VideoCodec=copy',
        2715,
        3600,
        $programme,
    );

    $captured = [];
    Http::assertSent(function ($request) use (&$captured) {
        if (str_contains($request->url(), '/broadcast/') && str_contains($request->url(), '/start')) {
            $captured = $request->data();

            return true;
        }

        return false;
    });

    // Rewrite still happened, but no #range= fragment because we couldn't learn the size.
    expect($captured['stream_url'])
        ->toContain('static=true')
        ->not->toContain('VideoCodec=copy')
        ->not->toContain('StartTimeTicks')
        ->not->toContain('#range=');
});

/*
|--------------------------------------------------------------------------
| Audio stream index handling (avoid mapping the original absolute index
| against an already-remuxed single-track stream)
|--------------------------------------------------------------------------
*/

it('maps audio track 0 when a VideoCodec=copy remux already selected the preferred audio track server-side', function () {
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'transcode_mode' => TranscodeMode::Direct->value,
    ]);

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    $service = Mockery::mock(NetworkBroadcastService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    // Emby resolved the preferred-language track at absolute MediaStreams index 2.
    $service->shouldReceive('resolveAudioStreamIndex')->with($network)->andReturn(2);
    $service->shouldReceive('resolveSubtitleInfo')->andReturn(['url' => null, 'language' => null, 'server_seeked' => false]);
    $service->shouldReceive('computeNextStreamConfig')->andReturn(null);

    $method = new ReflectionMethod(NetworkBroadcastService::class, 'startViaProxy');
    $method->invoke(
        $service,
        $network,
        'http://emby.local/Videos/1/stream.ts?api_key=abc&AudioStreamIndex=2&VideoCodec=copy',
        0,
        3600,
        $programme,
    );

    $captured = [];
    Http::assertSent(function ($request) use (&$captured) {
        if (str_contains($request->url(), '/broadcast/') && str_contains($request->url(), '/start')) {
            $captured = $request->data();

            return true;
        }

        return false;
    });

    // Emby's remux already narrowed the stream to a single audio track (always at
    // position 0) — ffmpeg must not re-select index 2, which no longer exists in the
    // already-filtered stream (that mismatch is what crashes the whole broadcast with
    // "Unable to map stream at a:0").
    expect($captured['audio_stream_index'])->toBe(0);
});

it('keeps the original resolved audio index when the URL is a raw passthrough with no server-side selection', function () {
    $payload = invokeStartViaProxyAndCapturePayload(
        ['transcode_mode' => TranscodeMode::Direct->value],
        'http://example.com/video.ts',
        0,
    );

    // No preferred_audio_language configured -> resolveAudioStreamIndex returns null,
    // and the URL has no VideoCodec=copy remux to begin with.
    expect($payload['audio_stream_index'])->toBeNull();
});
