<?php

/**
 * Covers the expiry auto-disable in RefreshPlaylistProfiles: the primary profile has
 * no enable toggle in the UI, so the job must undo its own auto-disable once the
 * provider stops reporting the account as expired (issue #1831).
 */

use App\Jobs\RefreshPlaylistProfiles;
use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Event::fake();
    config(['cache.default' => 'array']);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://provider.test',
            'username' => 'user',
            'password' => 'pass',
        ],
    ]);
});

function fakeProviderExpiry(int $expiresAtTimestamp): void
{
    Http::preventStrayRequests();
    Http::fake([
        'provider.test/player_api.php*' => Http::response([
            'user_info' => [
                'auth' => 1,
                'status' => 'Active',
                'exp_date' => (string) $expiresAtTimestamp,
                'max_connections' => '2',
                'active_cons' => '0',
            ],
            'server_info' => [],
        ]),
    ]);
}

it('re-enables an auto-disabled primary once the account is no longer expired', function () {
    fakeProviderExpiry(now()->addMonth()->timestamp);

    $primary = PlaylistProfile::factory()->for($this->playlist)->for($this->user)->primary()->disabled()->create([
        'url' => 'http://provider.test',
        'username' => 'user',
        'password' => 'pass',
    ]);

    (new RefreshPlaylistProfiles(profileId: $primary->id))->handle();

    expect($primary->refresh()->enabled)->toBeTrue();
});

it('keeps the primary disabled while the account is still expired', function () {
    fakeProviderExpiry(now()->subDay()->timestamp);

    $primary = PlaylistProfile::factory()->for($this->playlist)->for($this->user)->primary()->create([
        'url' => 'http://provider.test',
        'username' => 'user',
        'password' => 'pass',
    ]);

    (new RefreshPlaylistProfiles(profileId: $primary->id))->handle();

    expect($primary->refresh()->enabled)->toBeFalse();
});

it('does not re-enable an additional profile the user disabled', function () {
    fakeProviderExpiry(now()->addMonth()->timestamp);

    $additional = PlaylistProfile::factory()->for($this->playlist)->for($this->user)->disabled()->create([
        'url' => 'http://provider.test',
        'username' => 'user',
        'password' => 'pass',
    ]);

    (new RefreshPlaylistProfiles(profileId: $additional->id))->handle();

    expect($additional->refresh()->enabled)->toBeFalse();
});

it('re-enables a primary that older releases disabled for an unlimited (exp_date 0) account', function () {
    fakeProviderExpiry(0);

    $primary = PlaylistProfile::factory()->for($this->playlist)->for($this->user)->primary()->disabled()->create([
        'url' => 'http://provider.test',
        'username' => 'user',
        'password' => 'pass',
    ]);

    (new RefreshPlaylistProfiles(profileId: $primary->id))->handle();

    expect($primary->refresh()->enabled)->toBeTrue();
});
