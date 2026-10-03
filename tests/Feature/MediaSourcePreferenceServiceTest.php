<?php

use App\Models\Channel;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use App\Services\MediaSourceMatchService;
use App\Services\MediaSourcePreferenceService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Shared fixture: a provider playlist with the toggle on, an eligible
 * integration (emby by default) with a media playlist, a matched provider movie + enabled
 * media movie.
 */
function makeMatchedMovieFixture(bool $providerToggle = true, string $integrationType = 'emby'): array
{
    $user = User::factory()->create();
    $provider = Playlist::factory()->for($user)->create([
        'prefer_media_server_sources' => $providerToggle,
    ]);
    $media = Playlist::factory()->for($user)->create();
    $integration = MediaServerIntegration::factory()->for($user)->create([
        'type' => $integrationType,
        'enabled' => true,
        'playlist_id' => $media->id,
    ]);

    $providerMovie = Channel::factory()->for($provider)->for($user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);
    $mediaMovie = Channel::factory()->for($media)->for($user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);

    app(MediaSourceMatchService::class)->rebuildForPlaylist($provider);

    return compact('user', 'provider', 'media', 'integration', 'providerMovie', 'mediaMovie');
}

function mockGeneralSettings(): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->tmdb_auto_lookup_on_import = true;
    $mock->tmdb_auto_lookup_all_new = 'enabled';

    app()->instance(GeneralSettings::class, $mock);
}

// ── Resolver ────────────────────────────────────────────────────────────────

it('returns null with zero queries when the toggle is off', function () {
    $f = makeMatchedMovieFixture(providerToggle: false);

    // Matches exist in the table but the toggle is off, so the resolver must
    // short-circuit before touching media_source_matches.
    DB::enableQueryLog();
    $resolved = app(MediaSourcePreferenceService::class)->resolveChannel($f['providerMovie']);
    $toggleQueryCount = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'media_source_matches'))
        ->count();
    DB::disableQueryLog();

    expect($resolved)->toBeNull()
        ->and($toggleQueryCount)->toBe(0);
});

it('never swaps a live channel', function () {
    $f = makeMatchedMovieFixture();
    $f['providerMovie']->update(['is_vod' => false]);

    $resolved = app(MediaSourcePreferenceService::class)->resolveChannel($f['providerMovie']->refresh());

    expect($resolved)->toBeNull();
});

it('returns the media item when matched and reachable', function () {
    $f = makeMatchedMovieFixture();

    Cache::put("media-server-reachable:{$f['integration']->id}", true, 60);

    $resolved = app(MediaSourcePreferenceService::class)->resolveChannel($f['providerMovie']);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($f['mediaMovie']->id);
});

it('returns null for a local file that is missing or unreadable', function () {
    $user = User::factory()->create();
    $provider = Playlist::factory()->for($user)->create(['prefer_media_server_sources' => true]);
    $media = Playlist::factory()->for($user)->create();
    $integration = MediaServerIntegration::factory()->for($user)->create([
        'type' => 'local', 'enabled' => true, 'playlist_id' => $media->id,
    ]);

    $providerMovie = Channel::factory()->for($provider)->for($user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);
    $mediaMovie = Channel::factory()->for($media)->for($user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
        'info' => ['media_server_id' => base64_encode('/definitely/not/a/real/path/'.uniqid().'.mkv')],
    ]);

    app(MediaSourceMatchService::class)->rebuildForPlaylist($provider);

    $resolved = app(MediaSourcePreferenceService::class)->resolveChannel($providerMovie->refresh());

    expect($resolved)->toBeNull()
        ->and($mediaMovie->exists)->toBeTrue()
        ->and($integration->exists)->toBeTrue();
});

it('returns null when a cached emby reachability check failed', function () {
    $f = makeMatchedMovieFixture();

    // Prime the cache the way an earlier failed testConnection would have.
    Cache::put("media-server-reachable:{$f['integration']->id}", false, 60);

    $resolved = app(MediaSourcePreferenceService::class)->resolveChannel($f['providerMovie']);

    expect($resolved)->toBeNull();
});

it('returns null when there is no match row', function () {
    $user = User::factory()->create();
    $provider = Playlist::factory()->for($user)->create(['prefer_media_server_sources' => true]);
    $providerMovie = Channel::factory()->for($provider)->for($user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);

    $resolved = app(MediaSourcePreferenceService::class)->resolveChannel($providerMovie);

    expect($resolved)->toBeNull();
});

it('keeps the provider stream when the emby probe returns an error status', function () {
    $f = makeMatchedMovieFixture();

    // No cache primed: the resolver probes via isReachable(), which must not
    // retry and must treat any non-2xx as unreachable.
    Http::fake([
        '*.*/System/Info/Public' => Http::response('server error', 500),
    ]);

    $resolved = app(MediaSourcePreferenceService::class)->resolveChannel($f['providerMovie']);

    expect($resolved)->toBeNull();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/System/Info/Public'));
});

it('keeps the provider stream when the emby probe connection fails', function () {
    $f = makeMatchedMovieFixture();

    Http::fake([
        '*.*/System/Info/Public' => fn () => throw new ConnectionException('dead'),
    ]);

    $resolved = app(MediaSourcePreferenceService::class)->resolveChannel($f['providerMovie']);

    expect($resolved)->toBeNull();
});

it('swaps to a Plex item when the Plex server is reachable', function () {
    $f = makeMatchedMovieFixture(integrationType: 'plex');

    Http::fake([
        '*/identity' => Http::response(['MediaContainer' => ['machineIdentifier' => 'abc']]),
    ]);

    $resolved = app(MediaSourcePreferenceService::class)->resolveChannel($f['providerMovie']);

    expect($resolved?->id)->toBe($f['mediaMovie']->id);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/identity')
        && $request->hasHeader('X-Plex-Token'));
});

it('keeps the provider stream when the Plex probe fails', function () {
    $f = makeMatchedMovieFixture(integrationType: 'plex');

    Http::fake([
        '*/identity' => fn () => throw new ConnectionException('dead'),
    ]);

    $resolved = app(MediaSourcePreferenceService::class)->resolveChannel($f['providerMovie']);

    expect($resolved)->toBeNull();
});

// ── hasEligibleMatch() ──────────────────────────────────────────────────────

it('hasEligibleMatch is true for an eligible match even when the server is unreachable', function () {
    $f = makeMatchedMovieFixture();

    // Prime a failed reachability probe: eligibility deliberately ignores
    // reachability so a briefly-down server doesn't trigger provider
    // downloads during a group refresh.
    Cache::put("media-server-reachable:{$f['integration']->id}", false, 60);

    expect(app(MediaSourcePreferenceService::class)->hasEligibleMatch($f['providerMovie']))->toBeTrue();
});

it('hasEligibleMatch is false when the toggle is off', function () {
    $f = makeMatchedMovieFixture(providerToggle: false);

    expect(app(MediaSourcePreferenceService::class)->hasEligibleMatch($f['providerMovie']))->toBeFalse();
});

it('hasEligibleMatch is false when the media item is disabled', function () {
    $f = makeMatchedMovieFixture();
    $f['mediaMovie']->update(['enabled' => false]);

    expect(app(MediaSourcePreferenceService::class)->hasEligibleMatch($f['providerMovie']->refresh()))->toBeFalse();
});

it('hasEligibleMatch is false when the integration is disabled', function () {
    $f = makeMatchedMovieFixture();
    $f['integration']->update(['enabled' => false]);

    expect(app(MediaSourcePreferenceService::class)->hasEligibleMatch($f['providerMovie']))->toBeFalse();
});

it('hasEligibleMatch is false when there is no match row', function () {
    $user = User::factory()->create();
    $provider = Playlist::factory()->for($user)->create(['prefer_media_server_sources' => true]);
    $providerMovie = Channel::factory()->for($provider)->for($user)->create([
        'enabled' => true, 'is_vod' => true, 'tmdb_id' => 603,
    ]);

    expect(app(MediaSourcePreferenceService::class)->hasEligibleMatch($providerMovie))->toBeFalse();
});
