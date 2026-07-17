<?php

use App\Enums\TranscodeMode;
use App\Models\Network;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('passes through a flat playlist unchanged in structure when subtitles are not active', function () {
    $network = Network::factory()->for($this->user)->broadcasting()->create();

    Http::fake([
        '*/broadcast/*/live.m3u8' => Http::response(
            "#EXTM3U\n#EXT-X-VERSION:6\n#EXTINF:6.000000,\nlive000001.ts\n",
            200
        ),
    ]);

    $response = $this->get(url("/network/{$network->uuid}/live.m3u8"));

    $response->assertOk();
    $body = $response->getContent();
    expect($body)->toContain(url("/network/{$network->uuid}/live000001.ts"));
    expect($body)->not->toContain('hls-variant');
});

it('synthesizes a master playlist referencing live_vtt.m3u8 when the network has subtitles enabled', function () {
    // FFmpeg itself never emits a master playlist (#EXT-X-STREAM-INF) — confirmed by
    // testing its HLS muxer directly — it just auto-derives a live_vtt.m3u8 WebVTT
    // sub-playlist alongside the flat video live.m3u8 whenever a subtitle stream is
    // mapped. NetworkHlsController::playlist() decides whether to synthesize a master
    // playlist purely from the network's own subtitle preference (not by inspecting
    // the fetched playlist body, which is always the flat one), so the proxy response
    // content here is irrelevant — only that the broadcast is reachable (200).
    $network = Network::factory()->for($this->user)->broadcasting()->create([
        'preferred_subtitle_track' => 'eng',
    ]);

    Http::fake([
        '*/broadcast/*/live.m3u8' => Http::response(
            "#EXTM3U\n#EXT-X-VERSION:6\n#EXTINF:6.000000,\nlive000001.ts\n",
            200
        ),
    ]);

    $response = $this->get(url("/network/{$network->uuid}/live.m3u8"));

    $response->assertOk();
    $body = $response->getContent();

    $variantBase = url("/network/{$network->uuid}/hls-variant");
    expect($body)->toContain('URI="'.$variantBase.'/live_vtt.m3u8"')
        ->and($body)->toContain($variantBase.'/live.m3u8')
        // Subtitles must be available-but-off by default, not forced on every viewer.
        ->and($body)->toContain('DEFAULT=NO,AUTOSELECT=YES')
        ->and($body)->not->toContain('DEFAULT=YES,');
});

it('does not synthesize a master playlist for a Server-mode network even with a subtitle preference set', function () {
    // Server mode always forces subtitles off at the proxy layer — the media server
    // itself is responsible for subtitle handling there, so the flat-playlist path
    // must still be taken regardless of preferred_subtitle_track.
    $network = Network::factory()->for($this->user)->broadcasting()->create([
        'transcode_mode' => TranscodeMode::Server->value,
        'preferred_subtitle_track' => 'eng',
    ]);

    Http::fake([
        '*/broadcast/*/live.m3u8' => Http::response(
            "#EXTM3U\n#EXT-X-VERSION:6\n#EXTINF:6.000000,\nlive000001.ts\n",
            200
        ),
    ]);

    $response = $this->get(url("/network/{$network->uuid}/live.m3u8"));

    $response->assertOk();
    $body = $response->getContent();
    expect($body)->toContain(url("/network/{$network->uuid}/live000001.ts"))
        ->and($body)->not->toContain('hls-variant');
});

it('rewrites a video variant sub-playlist segments through the hls-variant route', function () {
    $network = Network::factory()->for($this->user)->broadcasting()->create();

    $videoVariant = "#EXTM3U\n#EXT-X-VERSION:6\n#EXTINF:6.000000,\nlive0_000000.ts\n";

    Http::fake([
        '*/broadcast/*/segment/live_0.m3u8' => Http::response($videoVariant, 200),
    ]);

    $response = $this->get(url("/network/{$network->uuid}/hls-variant/live_0.m3u8"));

    $response->assertOk();
    $body = $response->getContent();
    $variantBase = url("/network/{$network->uuid}/hls-variant");
    expect($body)->toContain($variantBase.'/live0_000000.ts');
});

it('rewrites a subtitle variant sub-playlist vtt segments through the hls-variant route', function () {
    $network = Network::factory()->for($this->user)->broadcasting()->create();

    $subtitleVariant = "#EXTM3U\n#EXT-X-VERSION:6\n#EXTINF:6.000000,\nlive_00.vtt\n#EXT-X-ENDLIST\n";

    Http::fake([
        '*/broadcast/*/segment/live_0_vtt.m3u8' => Http::response($subtitleVariant, 200),
    ]);

    $response = $this->get(url("/network/{$network->uuid}/hls-variant/live_0_vtt.m3u8"));

    $response->assertOk();
    $body = $response->getContent();
    $variantBase = url("/network/{$network->uuid}/hls-variant");
    expect($body)->toContain($variantBase.'/live_00.vtt');
});

it('redirects a .ts segment request under hls-variant straight to the proxy', function () {
    $network = Network::factory()->for($this->user)->broadcasting()->create();

    $response = $this->get(url("/network/{$network->uuid}/hls-variant/live0_000000.ts"));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('live0_000000.ts');
});

it('redirects a .vtt segment request under hls-variant straight to the proxy', function () {
    $network = Network::factory()->for($this->user)->broadcasting()->create();

    $response = $this->get(url("/network/{$network->uuid}/hls-variant/live_00.vtt"));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('live_00.vtt');
});

it('returns 404 for hls-variant when broadcast is not enabled', function () {
    $network = Network::factory()->for($this->user)->create(['broadcast_enabled' => false]);

    $response = $this->get(url("/network/{$network->uuid}/hls-variant/live_0.m3u8"));

    $response->assertStatus(404);
});
