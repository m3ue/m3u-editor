<?php

use App\Enums\TranscodeMode;
use App\Models\Episode;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Models\NetworkProgramme;
use App\Services\NetworkBroadcastService;
use Illuminate\Support\Facades\Http;

/**
 * The Track Preferences picker (NetworkContentRelationManager) stores a per-item
 * override as a composite "{type_relative_position}:{native_id}" string — e.g.
 * "1:395784" for Plex's 2nd audio stream (native_id 395784 is Plex's database-wide
 * stream ID; position 1 is "the 2nd audio stream", 0-indexed).
 *
 * Neither half works for both modes alone: native_id is what Server mode needs
 * (forwarded straight to the media server's own PreferredAudioTrack/
 * PreferredSubtitleTrack resolution, which understands its own IDs); position is
 * what Direct/Local mode needs (forwarded to the proxy for FFmpeg's plain,
 * gracefully-optional `-map 0:a:{N}?` index specifier — exact, and unlike a raw
 * media-server ID or a language code, never ambiguous between two same-language
 * tracks). Critically, resolving this requires NO extra media-server API call at
 * broadcast start — both halves were already known when the picker saved the value.
 */
beforeEach(function () {
    Http::fake([
        '*/broadcast/*/status' => Http::response(['status' => 'stopped'], 404),
        '*/broadcast/*/stop' => Http::response(['status' => 'stopped', 'final_segment_number' => 0], 200),
        '*/broadcast/*/start' => Http::response(['status' => 'started', 'ffmpeg_pid' => 12345], 200),
        '*' => Http::response([], 200),
    ]);
});

function makeNetworkWithCompositeOverride(string $transcodeMode, string $overrideValue): array
{
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'transcode_mode' => $transcodeMode,
    ]);

    $episode = Episode::factory()->create();

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Episode::class,
        'contentable_id' => $episode->id,
        'sort_order' => 1,
        'weight' => 1,
        'preferred_audio_track' => $overrideValue,
    ]);

    $programme = NetworkProgramme::factory()->create([
        'network_id' => $network->id,
        'contentable_type' => Episode::class,
        'contentable_id' => $episode->id,
        'start_time' => now()->subMinutes(5),
        'end_time' => now()->addMinutes(55),
        'duration_seconds' => 3600,
    ]);

    return [$network, $programme];
}

it('forwards the type-relative position (not the native ID) to the proxy for Direct mode', function () {
    // "1:395784" — Plex's 2nd audio stream (native stream ID 395784, meaningless to
    // FFmpeg) — Direct mode must forward "1" (the position), never "395784".
    [$network, $programme] = makeNetworkWithCompositeOverride(TranscodeMode::Direct->value, '1:395784');

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

    expect($captured['preferred_audio_language'])->toBe('1');
});

it('forwards the native ID (not the position) for Server mode', function () {
    // Server mode resolves PreferredAudioTrack itself against the media server's own
    // API, which needs the real native ID (395784), not the type-relative position.
    [$network, $programme] = makeNetworkWithCompositeOverride(TranscodeMode::Server->value, '1:395784');

    $service = app(NetworkBroadcastService::class);
    $method = new ReflectionMethod(NetworkBroadcastService::class, 'resolveTrackPreference');
    $result = $method->invoke($service, $network, $programme->contentable, 'preferred_audio_track');

    expect($result)->toBe('395784');
});

it('forwards a plain ISO code from the network-level default unchanged in both modes', function () {
    // The Network-level default (no per-item override) is always a plain ISO 639
    // code with no colon — resolveTrackPreference() must not try to split it.
    $network = Network::factory()->create(['preferred_audio_track' => 'eng']);
    $episode = Episode::factory()->create();

    $service = app(NetworkBroadcastService::class);
    $method = new ReflectionMethod(NetworkBroadcastService::class, 'resolveTrackPreference');

    expect($method->invoke($service, $network, $episode, 'preferred_audio_track'))->toBe('eng');
});

it('prefers the per-item composite override over the network-level default', function () {
    [$network, $programme] = makeNetworkWithCompositeOverride(TranscodeMode::Direct->value, '0:12345');

    $network->update(['preferred_audio_track' => 'eng']);

    $service = app(NetworkBroadcastService::class);
    $method = new ReflectionMethod(NetworkBroadcastService::class, 'resolveTrackPreference');
    $result = $method->invoke($service, $network, $programme->contentable, 'preferred_audio_track');

    expect($result)->toBe('0');
});

it('enables subtitles when the resolved per-item position is "0" (the first subtitle stream)', function () {
    // The exact field bug: a subtitle override resolving to position "0" was
    // silently disabling subtitles entirely, because PHP's empty("0") is true and
    // subtitlesEnabledForProxy()/getStreamUrl()'s PreferredSubtitleTrack merge both
    // used empty() to check the resolved value — treating a perfectly valid "first
    // subtitle track" pick as "no preference set".
    $network = Network::factory()->create([
        'enabled' => true,
        'broadcast_enabled' => true,
        'broadcast_requested' => true,
        'broadcast_pid' => null,
        'broadcast_started_at' => null,
        'transcode_mode' => TranscodeMode::Direct->value,
    ]);

    $episode = Episode::factory()->create();

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Episode::class,
        'contentable_id' => $episode->id,
        'sort_order' => 1,
        'weight' => 1,
        'preferred_subtitle_track' => '0:54321',
    ]);

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

    expect($captured['subtitles_enabled'])->toBeTrue()
        ->and($captured['subtitle_language'])->toBe('0');
});

it('sets PreferredSubtitleTrack for Server mode when the resolved native ID is "0"', function () {
    $network = Network::factory()->create(['transcode_mode' => TranscodeMode::Server->value]);
    $episode = Episode::factory()->create();

    NetworkContent::create([
        'network_id' => $network->id,
        'contentable_type' => Episode::class,
        'contentable_id' => $episode->id,
        'sort_order' => 1,
        'weight' => 1,
        'preferred_subtitle_track' => '3:0',
    ]);

    $service = app(NetworkBroadcastService::class);
    $method = new ReflectionMethod(NetworkBroadcastService::class, 'resolveTrackPreference');
    $result = $method->invoke($service, $network, $episode, 'preferred_subtitle_track');

    // Native ID "0" must still be treated as a real, present value.
    expect($result)->toBe('0');
});
