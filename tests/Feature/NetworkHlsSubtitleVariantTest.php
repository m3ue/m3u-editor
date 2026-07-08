<?php

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

it('rewrites a master playlist to point sub-playlists at the hls-variant route', function () {
    $network = Network::factory()->for($this->user)->broadcasting()->create();

    $master = <<<'M3U8'
#EXTM3U
#EXT-X-VERSION:6
#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="subs",NAME="subtitle_0",DEFAULT=YES,LANGUAGE="eng",URI="live_0_vtt.m3u8"
#EXT-X-STREAM-INF:BANDWIDTH=2340800,RESOLUTION=320x240,CODECS="avc1.f4000d,mp4a.40.2",SUBTITLES="subs"
live_0.m3u8
M3U8;

    Http::fake([
        '*/broadcast/*/live.m3u8' => Http::response($master, 200),
    ]);

    $response = $this->get(url("/network/{$network->uuid}/live.m3u8"));

    $response->assertOk();
    $body = $response->getContent();

    $variantBase = url("/network/{$network->uuid}/hls-variant");
    expect($body)->toContain('URI="'.$variantBase.'/live_0_vtt.m3u8"');
    expect($body)->toContain($variantBase.'/live_0.m3u8');
    // Subtitles must be available-but-off by default, not forced on every viewer.
    expect($body)->toContain('DEFAULT=NO,AUTOSELECT=YES');
    expect($body)->not->toContain('DEFAULT=YES');
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
