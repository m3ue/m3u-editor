<?php

use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use App\Services\AIOStreamsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.stremio.meta_addons', [
        'cinemeta' => 'https://v3-cinemeta.strem.io',
        'kitsu' => 'https://anime-kitsu.strem.fun',
        'tmdb' => '',
    ]);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/abc/manifest.json',
        'playlist_id' => $this->playlist->id,
    ]);
    $this->playlist->update(['aiostreams_integration_id' => $this->integration->id]);
});

it('falls back to Cinemeta when the AIOStreams instance has no meta addon (404)', function () {
    Http::fake([
        'aiostreams.test/abc/meta/movie/tt1234567.json*' => Http::response([
            'success' => false,
            'error' => ['code' => 'NOT_FOUND', 'message' => 'no addon to handle meta resource'],
        ], 404),
        'v3-cinemeta.strem.io/meta/movie/tt1234567.json*' => Http::response([
            'meta' => ['id' => 'tt1234567', 'type' => 'movie', 'name' => 'Fallback Movie'],
        ], 200),
    ]);

    $response = $this->get("/{$this->user->name}/{$this->playlist->uuid}/aiostreams/{$this->integration->id}/meta/movie/tt1234567.json");

    $response->assertOk();
    expect($response->json('meta.name'))->toBe('Fallback Movie');
});

it('returns 404 when neither AIOStreams nor the fallback addon can answer', function () {
    Http::fake([
        'aiostreams.test/abc/meta/series/tt9999999.json*' => Http::response(['success' => false], 404),
        'v3-cinemeta.strem.io/meta/series/tt9999999.json*' => Http::response('', 404),
    ]);

    $response = $this->get("/{$this->user->name}/{$this->playlist->uuid}/aiostreams/{$this->integration->id}/meta/series/tt9999999.json");

    $response->assertStatus(404);
    expect($response->json('error'))->toBe('Meta not found');
});

it('uses the AIOStreams meta response as-is and never calls the fallback when it succeeds', function () {
    Http::fake([
        'aiostreams.test/abc/meta/movie/tt1234567.json*' => Http::response([
            'meta' => ['id' => 'tt1234567', 'type' => 'movie', 'name' => 'Native Movie'],
        ], 200),
        'v3-cinemeta.strem.io/*' => Http::response(['meta' => ['name' => 'Should Not Be Used']], 200),
    ]);

    $response = $this->get("/{$this->user->name}/{$this->playlist->uuid}/aiostreams/{$this->integration->id}/meta/movie/tt1234567.json");

    $response->assertOk();
    expect($response->json('meta.name'))->toBe('Native Movie');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'cinemeta'));
});

it('routes kitsu-prefixed ids to the Kitsu addon on fallback', function () {
    Http::fake([
        'aiostreams.test/abc/meta/series/kitsu:12345.json*' => Http::response(['success' => false], 404),
        'anime-kitsu.strem.fun/meta/series/kitsu:12345.json*' => Http::response([
            'meta' => ['id' => 'kitsu:12345', 'type' => 'series', 'name' => 'Kitsu Anime'],
        ], 200),
    ]);

    $meta = AIOStreamsService::make($this->integration)->fetchMeta('series', 'kitsu:12345');

    expect($meta['meta']['name'])->toBe('Kitsu Anime');
});

it('treats a 200 error envelope from AIOStreams as a miss and falls back', function () {
    Http::fake([
        'aiostreams.test/abc/meta/movie/tt2222222.json*' => Http::response([
            'success' => false,
            'error' => ['code' => 'NOT_FOUND', 'message' => 'no addon to handle meta resource'],
        ], 200),
        'v3-cinemeta.strem.io/meta/movie/tt2222222.json*' => Http::response([
            'meta' => ['id' => 'tt2222222', 'type' => 'movie', 'name' => 'Recovered Movie'],
        ], 200),
    ]);

    $meta = AIOStreamsService::make($this->integration)->fetchMeta('movie', 'tt2222222');

    expect($meta['meta']['name'])->toBe('Recovered Movie');
});

it('does not attempt a fallback for tmdb ids when no tmdb addon is configured', function () {
    Http::fake([
        'aiostreams.test/abc/meta/movie/tmdb:550.json*' => Http::response(['success' => false], 404),
    ]);

    $meta = AIOStreamsService::make($this->integration)->fetchMeta('movie', 'tmdb:550');

    expect($meta)->toBeNull();
    Http::assertSentCount(1);
});

// ── aiostreams_meta_id_prefixes: once a manifest sync has recorded that
// AIOStreams has no addon for meta at all (or none for this id's prefix), skip
// the guaranteed-404 primary call entirely instead of failing through to the
// fallback on every single request.

it('skips the AIOStreams call entirely when the manifest is known to have no meta resource', function () {
    $this->integration->update(['aiostreams_meta_id_prefixes' => []]);

    Http::fake([
        'v3-cinemeta.strem.io/meta/movie/tt1234567.json*' => Http::response([
            'meta' => ['id' => 'tt1234567', 'type' => 'movie', 'name' => 'Fallback Only'],
        ], 200),
    ]);

    $meta = AIOStreamsService::make($this->integration)->fetchMeta('movie', 'tt1234567');

    expect($meta['meta']['name'])->toBe('Fallback Only');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'aiostreams.test'));
    Http::assertSentCount(1);
});

it('skips the AIOStreams call when the manifest supports meta only for a different id prefix', function () {
    $this->integration->update(['aiostreams_meta_id_prefixes' => ['tt']]);

    Http::fake([
        // If this were hit, the test should fail via assertNotSent below.
        'aiostreams.test/*' => Http::response(['meta' => ['name' => 'Should not be called']], 200),
    ]);

    $meta = AIOStreamsService::make($this->integration)->fetchMeta('movie', 'tmdb:550');

    expect($meta)->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'aiostreams.test'));
});

it('still calls AIOStreams when the manifest supports meta for the requested id prefix', function () {
    $this->integration->update(['aiostreams_meta_id_prefixes' => ['tt']]);

    Http::fake([
        'aiostreams.test/abc/meta/movie/tt1234567.json*' => Http::response([
            'meta' => ['id' => 'tt1234567', 'type' => 'movie', 'name' => 'Native Movie'],
        ], 200),
    ]);

    $meta = AIOStreamsService::make($this->integration)->fetchMeta('movie', 'tt1234567');

    expect($meta['meta']['name'])->toBe('Native Movie');
});

it('still calls AIOStreams when the manifest supports meta without idPrefixes restriction', function () {
    $this->integration->update(['aiostreams_meta_id_prefixes' => ['*']]);

    Http::fake([
        'aiostreams.test/abc/meta/movie/tmdb:550.json*' => Http::response([
            'meta' => ['id' => 'tmdb:550', 'type' => 'movie', 'name' => 'Wildcard Movie'],
        ], 200),
    ]);

    $meta = AIOStreamsService::make($this->integration)->fetchMeta('movie', 'tmdb:550');

    expect($meta['meta']['name'])->toBe('Wildcard Movie');
});
