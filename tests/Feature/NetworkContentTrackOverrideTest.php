<?php

use App\Enums\TranscodeMode;
use App\Models\Episode;
use App\Models\Network;
use App\Models\NetworkContent;
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
 * Helper: create a network + episode content item, invoke startViaProxy, and capture
 * the broadcast start payload sent to the proxy.
 */
function invokeStartViaProxyWithContent(array $networkAttrs, ?array $networkContentOverride = null): array
{
    $network = Network::factory()->create(array_merge([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'transcode_mode' => TranscodeMode::Direct->value,
    ], $networkAttrs));

    $episode = Episode::factory()->create();

    if ($networkContentOverride !== null) {
        NetworkContent::create(array_merge([
            'network_id' => $network->id,
            'contentable_type' => Episode::class,
            'contentable_id' => $episode->id,
            'sort_order' => 1,
            'weight' => 1,
        ], $networkContentOverride));
    }

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'contentable_type' => Episode::class,
        'contentable_id' => $episode->id,
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

it('uses the network-level default when the content item has no override', function () {
    $payload = invokeStartViaProxyWithContent([
        'preferred_audio_track' => 'eng',
    ]);

    expect($payload['preferred_audio_language'])->toBe('eng');
});

it('prefers a per-item NetworkContent override over the network-level default', function () {
    $payload = invokeStartViaProxyWithContent(
        ['preferred_audio_track' => 'eng'],
        ['preferred_audio_track' => 'jpn'],
    );

    expect($payload['preferred_audio_language'])->toBe('jpn');
});

it('falls back to the network default when the override column is null', function () {
    $payload = invokeStartViaProxyWithContent(
        ['preferred_audio_track' => 'eng'],
        ['preferred_audio_track' => null],
    );

    expect($payload['preferred_audio_language'])->toBe('eng');
});

it('prefers a per-item subtitle override and enables subtitles for it even with no network-level default', function () {
    $payload = invokeStartViaProxyWithContent(
        ['preferred_subtitle_track' => null],
        ['preferred_subtitle_track' => 'jpn'],
    );

    expect($payload['subtitles_enabled'])->toBeTrue()
        ->and($payload['subtitle_language'])->toBe('jpn');
});

it('does not enable subtitles when neither the network nor the item override sets a subtitle track', function () {
    $payload = invokeStartViaProxyWithContent(['preferred_subtitle_track' => null]);

    expect($payload['subtitles_enabled'])->toBeFalse();
});
