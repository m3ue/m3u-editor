<?php

/**
 * Provider Profiles "Redirect" mode (#1851): instead of proxying, players get a
 * redirect to the provider URL of an account with a free slot.
 */

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Models\User;
use App\Services\ProfileService;
use App\Services\ProviderProfileRedirectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    config(['cache.default' => 'array']);

    $this->user = User::factory()->create([
        'name' => 'redirectuser'.uniqid(),
        'permissions' => ['use_proxy'],
    ]);
});

/**
 * An Xtream playlist in Redirect mode with one single-stream profile per username.
 *
 * @param  array<int, string>  $usernames
 */
function makeRedirectPlaylist(User $user, array $usernames = ['main', 'second'], array $attributes = []): Playlist
{
    // Quietly: the created listener would auto-create a primary profile and poll the provider.
    $playlist = Playlist::factory()->for($user)->createQuietly(array_merge([
        'xtream' => true,
        'xtream_config' => ['url' => 'http://provider.test', 'username' => 'main', 'password' => 'mainpass'],
        'enable_proxy' => false,
        'profiles_enabled' => true,
        'profiles_redirect' => true,
        'profiles_redirect_poll_provider' => false,
        'profiles_redirect_hold_seconds' => 600,
        'available_streams' => 0,
    ], $attributes));

    foreach ($usernames as $index => $username) {
        PlaylistProfile::factory()->for($playlist)->for($user)->withMaxStreams(1)->create([
            'is_primary' => $index === 0,
            'priority' => $index,
            'enabled' => true,
            'username' => $username,
            'password' => "{$username}pass",
            'url' => null,
            'provider_info' => null,
        ]);
    }

    // Redis outlives the test database, and profile ids restart with it.
    foreach ($playlist->profiles as $profile) {
        Redis::del(ProviderProfileRedirectService::assignmentsKey($profile));
    }

    return $playlist->fresh();
}

function redirectService(): ProviderProfileRedirectService
{
    return app(ProviderProfileRedirectService::class);
}

/**
 * Fake player_api.php, reporting active_cons per username.
 *
 * @param  array<string, int>  $activeByUsername
 */
function fakeProviderActiveConnections(array $activeByUsername): void
{
    // Later Http::fake() stubs queue behind earlier ones, so every stub reads the
    // latest counts from the container instead of capturing them.
    app()->instance('test.provider_active_cons', $activeByUsername);

    Http::fake(function (HttpRequest $request) {
        $activeByUsername = app('test.provider_active_cons');
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $username = $query['username'] ?? '';

        if (! array_key_exists($username, $activeByUsername)) {
            return Http::response('', 500);
        }

        return Http::response([
            'user_info' => ['active_cons' => $activeByUsername[$username], 'max_connections' => 1, 'status' => 'Active'],
        ]);
    });
}

test('counting mode hands out accounts in priority order and refuses when all are busy', function () {
    $playlist = makeRedirectPlaylist($this->user);

    expect(redirectService()->assignProfile($playlist, '10.0.0.1:alice')?->username)->toBe('main')
        ->and(redirectService()->assignProfile($playlist, '10.0.0.2:bob')?->username)->toBe('second')
        ->and(redirectService()->assignProfile($playlist, '10.0.0.3:carol'))->toBeNull();
});

test('counting mode lets the same client change channels without using another slot', function () {
    $playlist = makeRedirectPlaylist($this->user);

    redirectService()->assignProfile($playlist, '10.0.0.1:alice');
    redirectService()->assignProfile($playlist, '10.0.0.2:bob');

    // Both accounts are busy, but alice replacing her own stream keeps her account.
    expect(redirectService()->assignProfile($playlist, '10.0.0.1:alice')?->username)->toBe('main');
});

test('counting mode releases a slot after the hold period', function () {
    $playlist = makeRedirectPlaylist($this->user, ['main'], ['profiles_redirect_hold_seconds' => 60]);

    redirectService()->assignProfile($playlist, '10.0.0.1:alice');
    expect(redirectService()->assignProfile($playlist, '10.0.0.2:bob'))->toBeNull();

    $this->travel(61)->seconds();

    expect(redirectService()->assignProfile($playlist, '10.0.0.2:bob')?->username)->toBe('main');
});

test('polling mode skips accounts the provider reports as busy', function () {
    $playlist = makeRedirectPlaylist($this->user, ['main', 'second'], ['profiles_redirect_poll_provider' => true]);
    fakeProviderActiveConnections(['main' => 1, 'second' => 0]);

    expect(redirectService()->assignProfile($playlist, '10.0.0.1:alice')?->username)->toBe('second');
});

test('polling mode counts a fresh redirect before the provider reports it', function () {
    $playlist = makeRedirectPlaylist($this->user, ['main', 'second'], ['profiles_redirect_poll_provider' => true]);
    fakeProviderActiveConnections(['main' => 0, 'second' => 0]);

    expect(redirectService()->assignProfile($playlist, '10.0.0.1:alice')?->username)->toBe('main')
        ->and(redirectService()->assignProfile($playlist, '10.0.0.2:bob')?->username)->toBe('second')
        ->and(redirectService()->assignProfile($playlist, '10.0.0.3:carol'))->toBeNull();
});

test('polling mode lets the most recent client on an account replace its own stream', function () {
    $playlist = makeRedirectPlaylist($this->user, ['main'], ['profiles_redirect_poll_provider' => true]);
    fakeProviderActiveConnections(['main' => 1]);

    // Nobody was sent here by us yet: the provider's one connection belongs to someone else.
    expect(redirectService()->assignProfile($playlist, '10.0.0.1:alice'))->toBeNull();

    fakeProviderActiveConnections(['main' => 0]);
    Cache::flush();
    redirectService()->assignProfile($playlist, '10.0.0.1:alice');

    // Later the provider reports alice's stream; alice changing channel replaces it.
    $this->travel(5)->minutes();
    fakeProviderActiveConnections(['main' => 1]);
    Cache::flush();

    expect(redirectService()->assignProfile($playlist, '10.0.0.1:alice')?->username)->toBe('main')
        ->and(redirectService()->assignProfile($playlist, '10.0.0.2:bob'))->toBeNull();
});

test('polling mode falls back to counting when the provider cannot be polled', function () {
    $playlist = makeRedirectPlaylist($this->user, ['main', 'second'], ['profiles_redirect_poll_provider' => true]);
    fakeProviderActiveConnections(['second' => 0]);

    expect(redirectService()->assignProfile($playlist, '10.0.0.1:alice')?->username)->toBe('main')
        ->and(redirectService()->assignProfile($playlist, '10.0.0.2:bob')?->username)->toBe('second');
});

test('provider counts are cached for the configured window', function () {
    $playlist = makeRedirectPlaylist($this->user, ['main'], [
        'profiles_redirect_poll_provider' => true,
        'profiles_redirect_cache_seconds' => 30,
    ]);
    fakeProviderActiveConnections(['main' => 0]);
    $profile = $playlist->enabledProfiles()->first();

    redirectService()->providerActiveConnections($profile, 30);
    redirectService()->providerActiveConnections($profile, 30);

    Http::assertSentCount(1);
});

test('live requests redirect to the assigned account instead of the proxy', function () {
    $playlist = makeRedirectPlaylist($this->user);
    $channel = Channel::factory()->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'url' => 'http://provider.test/live/main/mainpass/123.ts',
    ]);
    $path = "/live/{$this->user->name}/{$playlist->uuid}/{$channel->id}.ts";

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->get($path)
        ->assertRedirect('http://provider.test/live/main/mainpass/123.ts');
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->get($path)
        ->assertRedirect('http://provider.test/live/second/secondpass/123.ts');
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])->get($path)
        ->assertStatus(503);
});

test('proxy selection on a Redirect-mode playlist counts redirected streams', function () {
    $playlist = makeRedirectPlaylist($this->user);
    redirectService()->assignProfile($playlist, '10.0.0.1:alice');

    $profiles = $playlist->enabledProfiles()->get();
    $counts = redirectService()->countsForProxySelection($playlist, $profiles);

    expect($counts[(string) $profiles[0]->id])->toBe(1)
        ->and($counts[(string) $profiles[1]->id])->toBe(0);
});

test('Redirect mode does not force the proxy for pooled channels', function () {
    $playlist = makeRedirectPlaylist($this->user);

    expect(Channel::needsProxy(
        channelEnableProxy: false,
        playlistEnableProxy: false,
        requestProxyFlag: false,
        sourcePlaylistProfilesEnabled: $playlist->profiles_enabled && ! $playlist->usesProfileRedirect(),
        userCanUseProxy: true,
    ))->toBeFalse();
});

test('Proxy mode playlists are untouched by the redirect service', function () {
    $playlist = makeRedirectPlaylist($this->user, ['main'], ['profiles_redirect' => false, 'enable_proxy' => true]);

    expect($playlist->usesProfileRedirect())->toBeFalse()
        ->and(redirectService()->assignProfile($playlist, '10.0.0.1:alice'))->toBeNull()
        ->and(ProfileService::buildClientIdentifier('10.0.0.1', 'alice'))->toBe('10.0.0.1:alice');
});
