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
function profileRedirectPlaylist(User $user, array $usernames = ['main', 'second'], array $attributes = [], int $maxStreams = 1): Playlist
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
        PlaylistProfile::factory()->for($playlist)->for($user)->withMaxStreams($maxStreams)->create([
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
        ProfileService::resetConnectionTracking($profile);
    }

    return $playlist->fresh();
}

function profileRedirectService(): ProviderProfileRedirectService
{
    return app(ProviderProfileRedirectService::class);
}

/**
 * Fake player_api.php, reporting active_cons per username.
 *
 * @param  array<string, int>  $activeByUsername
 */
function profileRedirectFakeActiveCons(array $activeByUsername): void
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
    $playlist = profileRedirectPlaylist($this->user);

    expect(profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice')?->username)->toBe('main')
        ->and(profileRedirectService()->assignProfile($playlist, '10.0.0.2:bob')?->username)->toBe('second')
        ->and(profileRedirectService()->assignProfile($playlist, '10.0.0.3:carol'))->toBeNull();
});

test('counting mode lets the same client change channels without using another slot', function () {
    $playlist = profileRedirectPlaylist($this->user);

    profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice');
    profileRedirectService()->assignProfile($playlist, '10.0.0.2:bob');

    // Both accounts are busy, but alice replacing her own stream keeps her account.
    expect(profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice')?->username)->toBe('main');
});

test('counting mode releases a slot after the hold period', function () {
    $playlist = profileRedirectPlaylist($this->user, ['main'], ['profiles_redirect_hold_seconds' => 60]);

    profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice');
    expect(profileRedirectService()->assignProfile($playlist, '10.0.0.2:bob'))->toBeNull();

    $this->travel(61)->seconds();

    expect(profileRedirectService()->assignProfile($playlist, '10.0.0.2:bob')?->username)->toBe('main');
});

test('polling mode skips accounts the provider reports as busy', function () {
    $playlist = profileRedirectPlaylist($this->user, ['main', 'second'], ['profiles_redirect_poll_provider' => true]);
    profileRedirectFakeActiveCons(['main' => 1, 'second' => 0]);

    expect(profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice')?->username)->toBe('second');
});

test('polling mode counts a fresh redirect before the provider reports it', function () {
    $playlist = profileRedirectPlaylist($this->user, ['main', 'second'], ['profiles_redirect_poll_provider' => true]);
    profileRedirectFakeActiveCons(['main' => 0, 'second' => 0]);

    expect(profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice')?->username)->toBe('main')
        ->and(profileRedirectService()->assignProfile($playlist, '10.0.0.2:bob')?->username)->toBe('second')
        ->and(profileRedirectService()->assignProfile($playlist, '10.0.0.3:carol'))->toBeNull();
});

test('polling mode lets the most recent client on an account replace its own stream', function () {
    $playlist = profileRedirectPlaylist($this->user, ['main'], ['profiles_redirect_poll_provider' => true]);
    profileRedirectFakeActiveCons(['main' => 1]);

    // Nobody was sent here by us yet: the provider's one connection belongs to someone else.
    expect(profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice'))->toBeNull();

    profileRedirectFakeActiveCons(['main' => 0]);
    Cache::flush();
    profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice');

    // Later the provider reports alice's stream; alice changing channel replaces it.
    $this->travel(5)->minutes();
    profileRedirectFakeActiveCons(['main' => 1]);
    Cache::flush();

    expect(profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice')?->username)->toBe('main')
        ->and(profileRedirectService()->assignProfile($playlist, '10.0.0.2:bob'))->toBeNull();
});

test('polling mode falls back to counting when the provider cannot be polled', function () {
    $playlist = profileRedirectPlaylist($this->user, ['main', 'second'], ['profiles_redirect_poll_provider' => true]);
    profileRedirectFakeActiveCons(['second' => 0]);

    expect(profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice')?->username)->toBe('main')
        ->and(profileRedirectService()->assignProfile($playlist, '10.0.0.2:bob')?->username)->toBe('second');
});

test('provider counts are cached for the configured window', function () {
    $playlist = profileRedirectPlaylist($this->user, ['main'], [
        'profiles_redirect_poll_provider' => true,
        'profiles_redirect_cache_seconds' => 30,
    ]);
    profileRedirectFakeActiveCons(['main' => 0]);
    $profile = $playlist->enabledProfiles()->first();

    profileRedirectService()->providerActiveConnections($profile, 30);
    profileRedirectService()->providerActiveConnections($profile, 30);

    Http::assertSentCount(1);
});

test('live requests redirect to the assigned account instead of the proxy', function () {
    $playlist = profileRedirectPlaylist($this->user);
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
    $playlist = profileRedirectPlaylist($this->user);
    profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice');

    [$profile] = ProfileService::selectAndReserveProfile($playlist);

    expect($profile?->username)->toBe('second');
});

test('pool status counts redirected streams', function () {
    $playlist = profileRedirectPlaylist($this->user);
    profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice');

    expect(ProfileService::getPoolStatus($playlist)['total_active'])->toBe(1);
});

test('polling mode does not treat an old assignment as the client\'s running stream', function () {
    $playlist = profileRedirectPlaylist($this->user, ['main'], ['profiles_redirect_poll_provider' => true]);
    profileRedirectFakeActiveCons(['main' => 0]);
    profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice');

    // Two hours later the account is busy from another source (DVR through the proxy,
    // or an app outside the editor). Alice's old redirect is not that stream.
    $this->travel(2)->hours();
    Cache::flush();
    profileRedirectFakeActiveCons(['main' => 1]);

    expect(profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice'))->toBeNull();
});

test('polling mode caches a failed poll instead of retrying it on every request', function () {
    $playlist = profileRedirectPlaylist($this->user, ['main'], ['profiles_redirect_poll_provider' => true]);
    profileRedirectFakeActiveCons([]);

    expect(profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice')?->username)->toBe('main')
        ->and(profileRedirectService()->assignProfile($playlist, '10.0.0.2:bob'))->toBeNull();

    // One provider poll; the m3u-proxy count for the failed account is a local call.
    expect(Http::recorded(fn (HttpRequest $request): bool => str_contains($request->url(), 'player_api.php')))->toHaveCount(1);
});

test('polling mode does not count a redirect twice once the provider reports it', function () {
    $playlist = profileRedirectPlaylist($this->user, ['main'], ['profiles_redirect_poll_provider' => true], maxStreams: 2);
    profileRedirectFakeActiveCons(['main' => 0]);
    profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice');

    // A minute later the provider's count includes alice's stream.
    $this->travel(60)->seconds();
    Cache::flush();
    profileRedirectFakeActiveCons(['main' => 1]);

    expect(profileRedirectService()->assignProfile($playlist, '10.0.0.2:bob')?->username)->toBe('main')
        ->and(profileRedirectService()->assignProfile($playlist, '10.0.0.3:carol'))->toBeNull();
});

test('Redirect mode does not force the proxy for pooled channels', function () {
    $playlist = profileRedirectPlaylist($this->user);

    expect(Channel::needsProxy(
        channelEnableProxy: false,
        playlistEnableProxy: false,
        requestProxyFlag: false,
        sourcePlaylistProfilesEnabled: $playlist->profiles_enabled && ! $playlist->usesProfileRedirect(),
        userCanUseProxy: true,
    ))->toBeFalse();
});

test('Proxy mode playlists are untouched by the redirect service', function () {
    $playlist = profileRedirectPlaylist($this->user, ['main'], ['profiles_redirect' => false, 'enable_proxy' => true]);

    expect($playlist->usesProfileRedirect())->toBeFalse()
        ->and(profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice'))->toBeNull()
        ->and(ProfileService::buildClientIdentifier('10.0.0.1', 'alice'))->toBe('10.0.0.1:alice');
});

test('polling mode does not replace a client\'s stream after the proxy started one on that account', function () {
    $playlist = profileRedirectPlaylist($this->user, ['main'], ['profiles_redirect_poll_provider' => true]);
    profileRedirectFakeActiveCons(['main' => 0]);
    profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice');

    // Alice stopped; a DVR recording then starts on the same account through the proxy.
    $this->travel(5)->minutes();
    Cache::flush();
    [$profile, $reservationId] = ProfileService::selectAndReserveProfile($playlist);
    expect($profile?->username)->toBe('main');
    ProfileService::finalizeReservation($profile, $reservationId, 'dvr-stream');

    // The provider now reports the recording. Alice's old redirect is not that stream.
    $this->travel(1)->minutes();
    Cache::flush();
    profileRedirectFakeActiveCons(['main' => 1]);

    expect(profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice'))->toBeNull();
});

test('pool status for an account not polled yet starts from the proxy count', function () {
    $playlist = profileRedirectPlaylist($this->user, ['main'], ['profiles_redirect_poll_provider' => true]);
    $profileId = (string) $playlist->enabledProfiles()->first()->id;

    Http::fake([
        '*/streams/counts-by-metadata*' => Http::response(['counts' => [$profileId => 1]]),
    ]);

    expect(ProfileService::getPoolStatus($playlist)['total_active'])->toBe(1);
    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'player_api.php'));
});

test('polling mode counts proxied streams on an account whose poll failed', function () {
    $playlist = profileRedirectPlaylist($this->user, ['main'], ['profiles_redirect_poll_provider' => true]);
    $profileId = (string) $playlist->enabledProfiles()->first()->id;

    // A DVR recording holds the account through the proxy, and the provider is down.
    Http::fake(fn (HttpRequest $request) => str_contains($request->url(), '/streams/counts-by-metadata')
        ? Http::response(['counts' => [$profileId => 1]])
        : Http::response('', 500));

    expect(profileRedirectService()->assignProfile($playlist, '10.0.0.1:alice'))->toBeNull();
});

test('recording proxied streams prunes expired markers', function () {
    $playlist = profileRedirectPlaylist($this->user, ['main']);
    $profile = $playlist->enabledProfiles()->first();

    profileRedirectService()->recordProxyStream($profile, 'old-stream');
    $this->travel(25)->hours();
    profileRedirectService()->recordProxyStream($profile, 'new-stream');

    expect(array_keys(Redis::hgetall(ProviderProfileRedirectService::assignmentsKey($profile))))
        ->toBe(['proxy:new-stream']);
});
