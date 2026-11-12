<?php

/**
 * Provider profiles on M3U playlists: primary profile lifecycle in ProfileService.
 *
 * Covers:
 * - ensureM3uPrimaryProfile() derives the account from the M3U URL without any provider call
 * - ensureM3uPrimaryProfile() follows M3U URL changes, never blanks credentials, and sets max_streams
 * - createPrimaryProfile()/syncPrimaryProfile() keep their Xtream-only behaviour for M3U playlists
 * - refreshProfile()/refreshAllProfiles() skip M3U profiles (there is no account API to query)
 */

use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Models\User;
use App\Services\ProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    // Keep PlaylistListener out of factory setup so only the calls under test create profiles.
    Event::fake();
    Http::fake();

    $this->user = User::factory()->create();

    config(['cache.default' => 'array']);
});

function m3uPrimaryPlaylist(User $user, string $url = 'http://provider.test:8080/get.php?username=puser&password=ppass&type=m3u_plus'): Playlist
{
    return Playlist::factory()->create([
        'user_id' => $user->id,
        'profiles_enabled' => true,
        'xtream' => false,
        'xtream_config' => null,
        'url' => $url,
    ]);
}

it('creates the primary profile from the credentials in the M3U URL without contacting the provider', function () {
    $playlist = m3uPrimaryPlaylist($this->user);

    $profile = ProfileService::ensureM3uPrimaryProfile($playlist);

    expect($profile)->toBeInstanceOf(PlaylistProfile::class)
        ->and($profile->is_primary)->toBeTrue()
        ->and($profile->url)->toBe('http://provider.test:8080')
        ->and($profile->username)->toBe('puser')
        ->and($profile->password)->toBe('ppass')
        ->and($profile->max_streams)->toBe(1)
        ->and($profile->enabled)->toBeTrue()
        ->and($profile->user_id)->toBe($this->user->id);

    Http::assertNothingSent();
});

it('creates the primary profile from a path-style M3U URL', function () {
    $playlist = m3uPrimaryPlaylist($this->user, 'http://provider.test/puser/ppass/playlist.m3u8');

    $profile = ProfileService::ensureM3uPrimaryProfile($playlist);

    expect($profile->url)->toBe('http://provider.test')
        ->and($profile->username)->toBe('puser')
        ->and($profile->password)->toBe('ppass');
});

it('creates a primary profile with blank credentials when the M3U URL carries none', function () {
    $playlist = m3uPrimaryPlaylist($this->user, 'http://provider.test/list.m3u');

    $profile = ProfileService::ensureM3uPrimaryProfile($playlist);

    expect($profile->is_primary)->toBeTrue()
        ->and($profile->url)->toBeNull()
        ->and($profile->username)->toBe('')
        ->and($profile->password)->toBe('')
        ->and($profile->max_streams)->toBe(1);
});

it('is idempotent and keeps a single primary profile', function () {
    $playlist = m3uPrimaryPlaylist($this->user);

    $first = ProfileService::ensureM3uPrimaryProfile($playlist);
    $second = ProfileService::ensureM3uPrimaryProfile($playlist);

    expect($second->id)->toBe($first->id)
        ->and($playlist->profiles()->where('is_primary', true)->count())->toBe(1);
});

it('updates the primary profile when the M3U URL credentials change', function () {
    $playlist = m3uPrimaryPlaylist($this->user);
    $profile = ProfileService::ensureM3uPrimaryProfile($playlist);

    $playlist->update(['url' => 'http://new-provider.test/get.php?username=newuser&password=newpass']);
    ProfileService::ensureM3uPrimaryProfile($playlist->fresh());

    expect($profile->fresh())
        ->url->toBe('http://new-provider.test')
        ->username->toBe('newuser')
        ->password->toBe('newpass');
});

it('leaves the credentials alone when the new M3U URL carries none', function () {
    $playlist = m3uPrimaryPlaylist($this->user);
    $profile = ProfileService::ensureM3uPrimaryProfile($playlist);

    $playlist->update(['url' => 'http://provider.test/list.m3u']);
    ProfileService::ensureM3uPrimaryProfile($playlist->fresh());

    expect($profile->fresh())
        ->url->toBe('http://provider.test:8080')
        ->username->toBe('puser')
        ->password->toBe('ppass');
});

it('stores the given stream limit on the primary profile only', function () {
    $playlist = m3uPrimaryPlaylist($this->user);
    $primary = ProfileService::ensureM3uPrimaryProfile($playlist);
    $secondary = PlaylistProfile::factory()->forPlaylist($playlist)->withMaxStreams(2)->create();

    ProfileService::ensureM3uPrimaryProfile($playlist, 4);

    expect($primary->fresh()->max_streams)->toBe(4)
        ->and($primary->fresh()->effective_max_streams)->toBe(4)
        ->and($secondary->fresh()->max_streams)->toBe(2);
});

it('ignores a stream limit below one', function () {
    $playlist = m3uPrimaryPlaylist($this->user);
    $primary = ProfileService::ensureM3uPrimaryProfile($playlist, 3);

    ProfileService::ensureM3uPrimaryProfile($playlist, 0);

    expect($primary->fresh()->max_streams)->toBe(3);
});

it('does nothing for Xtream playlists or when profiles are disabled', function () {
    $xtream = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'profiles_enabled' => true,
        'xtream' => true,
        'xtream_config' => ['url' => 'http://xtream.test', 'username' => 'u', 'password' => 'p'],
    ]);
    $disabled = m3uPrimaryPlaylist($this->user);
    $disabled->update(['profiles_enabled' => false]);

    expect(ProfileService::ensureM3uPrimaryProfile($xtream))->toBeNull()
        ->and(ProfileService::ensureM3uPrimaryProfile($disabled))->toBeNull()
        ->and(PlaylistProfile::count())->toBe(0);
});

it('keeps createPrimaryProfile and syncPrimaryProfile Xtream-only', function () {
    $playlist = m3uPrimaryPlaylist($this->user);

    expect(ProfileService::createPrimaryProfile($playlist))->toBeNull();

    ProfileService::syncPrimaryProfile($playlist);

    expect($playlist->profiles()->count())->toBe(0);
});

it('refreshProfile skips M3U profiles without contacting a provider', function () {
    $playlist = m3uPrimaryPlaylist($this->user);
    $profile = ProfileService::ensureM3uPrimaryProfile($playlist);

    expect(ProfileService::refreshProfile($profile))->toBeFalse()
        ->and($profile->fresh()->provider_info_updated_at)->toBeNull();

    Http::assertNothingSent();
});

it('refreshAllProfiles returns nothing for an M3U playlist', function () {
    $playlist = m3uPrimaryPlaylist($this->user);
    ProfileService::ensureM3uPrimaryProfile($playlist);

    expect(ProfileService::refreshAllProfiles($playlist))->toBe([]);

    Http::assertNothingSent();
});

it('manages the primary of a playlist switched from Xtream to M3U from its M3U URL, not its stale xtream_config', function () {
    $playlist = m3uPrimaryPlaylist($this->user);
    $playlist->update(['xtream_config' => ['url' => 'http://old-xtream.test', 'username' => 'xu', 'password' => 'xp']]);

    $primary = ProfileService::ensureM3uPrimaryProfile($playlist, 3);

    // The Xtream-only sync (run by PlaylistListener on every update) must not overwrite it
    ProfileService::syncPrimaryProfile($playlist);

    expect($primary)->not->toBeNull()
        ->and($primary->fresh()->username)->toBe('puser')
        ->and($primary->fresh()->url)->toBe('http://provider.test:8080')
        ->and($primary->fresh()->max_streams)->toBe(3)
        ->and(ProfileService::refreshAllProfiles($playlist))->toBe([]);

    Http::assertNothingSent();
});
