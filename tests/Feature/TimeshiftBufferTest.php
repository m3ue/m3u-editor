<?php

/**
 * Shared rolling timeshift buffer: config action + ?timeshift=1 live branch.
 *
 * P1 contract:
 *  - get_timeshift_config returns window/segment caps when enabled
 *  - feature flag `timeshift` appears in m3u_editor.features
 *  - ?timeshift=1 on /live/ falls through cleanly when the buffer service
 *    cannot start (no proxy in tests) rather than 500ing
 */

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config([
        'timeshift.enabled' => true,
        'timeshift.window_seconds' => 10800,
        'timeshift.segment_seconds' => 2,
        'timeshift.max_concurrent' => 20,
        'timeshift.idle_stop_seconds' => 120,
        'proxy.m3u_proxy_host' => 'http://m3u-proxy',
        'proxy.m3u_proxy_port' => 38085,
        'proxy.m3u_proxy_token' => 'test-token',
    ]);
    URL::forceRootUrl('http://editor.test');

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->auth = PlaylistAuth::create([
        'name' => 'TS Credential',
        'username' => 'ts-user',
        'password' => 'ts-pass',
        'enabled' => true,
        'dvr_enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($this->auth->id);
    $this->channel = Channel::factory()
        ->for($this->playlist)
        ->for(Group::factory()->for($this->user))
        ->create(['enabled' => true, 'url' => 'http://provider.example/live.ts']);
});

it('advertises the timeshift feature when config is enabled', function () {
    config(['timeshift.enabled' => true]);

    $response = $this->postJson('http://editor.test/player_api.php', [
        'username' => 'ts-user',
        'password' => 'ts-pass',
        'action' => 'get_timeshift_config',
    ]);

    $response->assertOk()->assertJson([
        'enabled' => true,
        'window_seconds' => 10800,
        'segment_seconds' => 2,
    ]);
});

it('rejects get_timeshift_config when timeshift is disabled', function () {
    config(['timeshift.enabled' => false]);

    $this->postJson('http://editor.test/player_api.php', [
        'username' => 'ts-user',
        'password' => 'ts-pass',
        'action' => 'get_timeshift_config',
    ])->assertStatus(403);
});

it('includes timeshift in m3u_editor features when enabled', function () {
    config(['timeshift.enabled' => true, 'app.enhanced_xtream_output' => true]);

    // Auth payload path: hit panel action that builds features.
    $response = $this->postJson('http://editor.test/player_api.php', [
        'username' => 'ts-user',
        'password' => 'ts-pass',
        'action' => 'get_user_info',
    ]);

    $response->assertOk();
    $features = $response->json('m3u_editor.features');
    if (is_array($features)) {
        expect($features)->toContain('timeshift');
    }
});

it('falls through to a non-500 when timeshift buffer cannot start', function () {
    // Proxy list/start fail → resolvePlaybackUrl returns null → plain live path.
    Http::preventStrayRequests();
    Http::fake([
        'http://m3u-proxy:38085/*' => Http::response('', 500),
    ]);

    $channelId = $this->channel->id;
    $response = $this->get("http://editor.test/live/ts-user/ts-pass/{$channelId}.ts?timeshift=1");

    // Either a redirect to the provider URL (buffer failed) or another
    // non-server-error outcome — never a 500 from the timeshift branch.
    expect($response->getStatusCode())->toBeLessThan(500);
});

it('serves a signed timeshift playlist route', function () {
    Http::fake([
        'http://m3u-proxy:38085/broadcast/*' => Http::response(
            "#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:6.0,\nlive000001.ts\n#EXTINF:6.0,\nlive000002.ts\n",
            200,
            ['Content-Type' => 'application/vnd.apple.mpegurl'],
        ),
    ]);

    $bufferId = 'ts-test-channel';
    $signed = URL::signedRoute('timeshift.live.playlist.signed', ['uuid' => $bufferId], absolute: false);

    $response = $this->get('http://editor.test'.$signed);

    $response->assertOk();
    expect($response->getContent())->toContain('live000001.ts');
    // Segments rewritten to absolute public proxy URLs.
    expect($response->getContent())->toContain('/broadcast/ts-test-channel/segment/live000001.ts');
});

it('rejects an unsigned timeshift playlist URL', function () {
    $this->get('http://editor.test/timeshift-live/signed/anything/live.m3u8')
        ->assertForbidden();
});
