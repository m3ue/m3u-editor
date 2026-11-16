<?php

/**
 * AIOStreams over player_api.php (get_aiostreams_catalog / _meta / _streams) and
 * its stream candidates played back over Xtream /movie/ and /series/ URLs, so a
 * client never needs a path an auth gateway in front of the server (ElfHosted,
 * for one) doesn't already let through.
 */

use App\Http\Controllers\MediaServerProxyController;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->integration = MediaServerIntegration::factory()->for($this->user)->create([
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/abc/manifest.json',
    ]);
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'aiostreams_integration_id' => $this->integration->id,
    ]);

    // The space in the password checks that playback URLs encode credentials.
    $this->auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'aiostreams_enabled' => true,
        'username' => 'aio-xtream-user',
        'password' => 'aio xtream pass',
    ]);
    $this->auth->assignTo($this->playlist);
});

function aiostreamsActionUrl(string $action, array $params = [], string $username = 'aio-xtream-user', string $password = 'aio xtream pass'): string
{
    return route('xtream.api.player').'?'.http_build_query([
        'username' => $username,
        'password' => $password,
        'action' => $action,
        ...$params,
    ]);
}

/**
 * Mint an Xtream aio stream id for a cached resolved URL, the way
 * get_aiostreams_streams does.
 */
function aiostreamsPlaybackStreamId(int $integrationId, string $resolvedUrl): string
{
    $token = str_repeat('a', 40);
    Cache::put(MediaServerProxyController::aioStreamsLiveCacheKey($integrationId, $token), $resolvedUrl, now()->addHour());

    return "aio-{$integrationId}-{$token}";
}

it('advertises the aiostreams player_api actions in the login payload', function () {
    $response = $this->getJson(aiostreamsActionUrl('panel'));

    $response->assertOk();
    expect($response->json('m3u_editor.aiostreams.0.actions'))->toBe([
        'catalog' => 'get_aiostreams_catalog',
        'meta' => 'get_aiostreams_meta',
        'streams' => 'get_aiostreams_streams',
    ]);
});

it('serves a catalog page over player_api.php', function () {
    Http::fake([
        'aiostreams.test/abc/catalog/movie/c54e3b0.tmdb.top/skip=20.json' => Http::response([
            'metas' => [['id' => 'tt1234567', 'type' => 'movie', 'name' => 'A Movie']],
        ]),
    ]);

    $response = $this->getJson(aiostreamsActionUrl('get_aiostreams_catalog', [
        'integration_id' => $this->integration->id,
        'type' => 'movie',
        'catalog_id' => 'c54e3b0.tmdb.top',
        'skip' => 20,
    ]));

    $response->assertOk();
    expect($response->json('metas.0.id'))->toBe('tt1234567');
});

it('requires a catalog_id for get_aiostreams_catalog', function () {
    $response = $this->getJson(aiostreamsActionUrl('get_aiostreams_catalog', [
        'integration_id' => $this->integration->id,
        'type' => 'movie',
    ]));

    $response->assertStatus(422);
    expect($response->json('fields'))->toContain('catalog_id');
});

it('rejects an integration assigned to a different playlist of the same user', function () {
    $otherIntegration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/other/manifest.json',
    ]);
    Playlist::factory()->for($this->user)->create([
        'aiostreams_integration_id' => $otherIntegration->id,
    ]);

    $response = $this->getJson(aiostreamsActionUrl('get_aiostreams_catalog', [
        'integration_id' => $otherIntegration->id,
        'type' => 'movie',
        'catalog_id' => 'top',
    ]));

    $response->assertStatus(401);
});

it('rejects a PlaylistAuth whose aiostreams toggle is off', function () {
    $this->auth->update(['aiostreams_enabled' => false]);

    $response = $this->getJson(aiostreamsActionUrl('get_aiostreams_meta', [
        'integration_id' => $this->integration->id,
        'type' => 'movie',
        'id' => 'tt1234567',
    ]));

    $response->assertStatus(401);
});

it('hands out Xtream movie playback urls for stream candidates, never the raw resolved url', function () {
    Http::fake([
        'aiostreams.test/abc/stream/movie/tt1234567.json' => Http::response([
            'streams' => [
                ['name' => 'Movie.1080p', 'url' => 'https://debrid.example.com/secret-token/1080p.mp4'],
                ['name' => 'Movie.720p', 'url' => 'https://debrid.example.com/secret-token/playback'],
            ],
        ]),
    ]);

    $response = $this->getJson(aiostreamsActionUrl('get_aiostreams_streams', [
        'integration_id' => $this->integration->id,
        'type' => 'movie',
        'id' => 'tt1234567',
    ]));

    $response->assertOk();
    $urls = array_column($response->json('streams'), 'url');

    expect($urls)->toHaveCount(2)
        ->and($urls[0])->toMatch("#/movie/aio-xtream-user/aio%20xtream%20pass/aio-{$this->integration->id}-[A-Za-z0-9]{40}\\.mp4$#")
        ->and($urls[1])->toMatch("#/movie/aio-xtream-user/aio%20xtream%20pass/aio-{$this->integration->id}-[A-Za-z0-9]{40}\\.mkv$#");

    foreach ($urls as $url) {
        expect($url)->not->toContain('debrid.example.com')->not->toContain('secret-token');
    }
});

it('hands out Xtream series playback urls for series stream candidates', function () {
    Http::fake([
        'aiostreams.test/abc/stream/series/tt7654321:1:2.json' => Http::response([
            'streams' => [['name' => 'Episode', 'url' => 'https://debrid.example.com/ep.mkv']],
        ]),
    ]);

    $response = $this->getJson(aiostreamsActionUrl('get_aiostreams_streams', [
        'integration_id' => $this->integration->id,
        'type' => 'series',
        'id' => 'tt7654321:1:2',
    ]));

    $response->assertOk();
    expect($response->json('streams.0.url'))->toContain('/series/aio-xtream-user/');
});

it('authorizes Xtream aio playback by the caller credentials', function () {
    $streamId = aiostreamsPlaybackStreamId($this->integration->id, 'not-a-valid-url');

    // A token that resolves reaches the proxy, which then rejects the cached
    // value as an invalid upstream URL - proof the credential check passed
    // without opening a real upstream connection.
    $this->get("/movie/aio-xtream-user/aio%20xtream%20pass/{$streamId}.mkv")
        ->assertStatus(404)
        ->assertJson(['error' => 'Resolved URL not found. Please re-sync.']);

    $this->get("/series/aio-xtream-user/aio%20xtream%20pass/{$streamId}.mkv")
        ->assertStatus(404);
});

it('rejects Xtream aio playback with wrong credentials', function () {
    $streamId = aiostreamsPlaybackStreamId($this->integration->id, 'https://debrid.example.com/file.mkv');

    $this->get("/movie/aio-xtream-user/wrong-pass/{$streamId}.mkv")->assertStatus(401);
});

it('rejects Xtream aio playback for an integration the credentials are not assigned to', function () {
    $otherIntegration = MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/other/manifest.json',
    ]);
    $streamId = aiostreamsPlaybackStreamId($otherIntegration->id, 'https://debrid.example.com/file.mkv');

    $this->get("/movie/aio-xtream-user/aio%20xtream%20pass/{$streamId}.mkv")->assertStatus(401);
});

it('stops Xtream aio playback once the viewer is disabled', function () {
    $streamId = aiostreamsPlaybackStreamId($this->integration->id, 'https://debrid.example.com/file.mkv');
    $this->auth->update(['enabled' => false]);

    $this->get("/movie/aio-xtream-user/aio%20xtream%20pass/{$streamId}.mkv")->assertStatus(401);
});

it('rejects a malformed aio stream id', function () {
    $this->get('/movie/aio-xtream-user/aio%20xtream%20pass/aio-garbage.mkv')->assertStatus(400);
});
