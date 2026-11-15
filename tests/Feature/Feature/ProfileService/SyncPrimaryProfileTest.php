<?php

/**
 * Tests for ProfileService::syncPrimaryProfile()
 *
 * Covers:
 * - Creates the primary profile when none exists
 * - Updates url/username/password when the playlist's xtream_config changes (stale provider URL bug)
 * - Does not issue an unnecessary DB write when credentials are already current
 */

use App\Jobs\RefreshPlaylistProfiles;
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
    // Prevent PlaylistListener from auto-creating a primary profile during factory setup.
    Event::fake();
    Http::fake();

    $this->user = User::factory()->create();

    config(['cache.default' => 'array']);
});

it('creates the primary profile when none exists', function () {
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'profiles_enabled' => true,
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://new.provider.cc:8080',
            'username' => 'newuser',
            'password' => 'newpass',
        ],
    ]);

    expect($playlist->profiles()->where('is_primary', true)->exists())->toBeFalse();

    ProfileService::syncPrimaryProfile($playlist);

    expect($playlist->profiles()->where('is_primary', true)->count())->toBe(1);

    $profile = $playlist->profiles()->where('is_primary', true)->first();
    expect($profile->url)->toBe('http://new.provider.cc:8080');
    expect($profile->username)->toBe('newuser');
    expect($profile->password)->toBe('newpass');
});

it('updates stale url, username and password when xtream_config changes', function () {
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'profiles_enabled' => true,
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://new.provider.cc:8080',
            'username' => 'newuser',
            'password' => 'newpass',
        ],
    ]);

    // Simulate a primary profile that still has the OLD provider's credentials
    $primaryProfile = PlaylistProfile::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Primary Account',
        'url' => 'http://old.provider.cc:80',
        'username' => 'olduser',
        'password' => 'oldpass',
        'max_streams' => 1,
        'priority' => 0,
        'enabled' => true,
        'is_primary' => true,
    ]);

    ProfileService::syncPrimaryProfile($playlist);

    $primaryProfile->refresh();

    expect($primaryProfile->url)->toBe('http://new.provider.cc:8080');
    expect($primaryProfile->username)->toBe('newuser');
    expect($primaryProfile->password)->toBe('newpass');
});

it('does not update the primary profile when credentials already match', function () {
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'profiles_enabled' => true,
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://provider.cc:8080',
            'username' => 'user',
            'password' => 'pass',
        ],
    ]);

    $primaryProfile = PlaylistProfile::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Primary Account',
        'url' => 'http://provider.cc:8080',
        'username' => 'user',
        'password' => 'pass',
        'max_streams' => 1,
        'priority' => 0,
        'enabled' => true,
        'is_primary' => true,
    ]);

    $updatedAt = $primaryProfile->updated_at;

    // Travel forward a second so an unnecessary update would show a different timestamp
    $this->travel(1)->seconds();

    ProfileService::syncPrimaryProfile($playlist);

    $primaryProfile->refresh();

    expect($primaryProfile->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('does nothing when xtream_config is not set on the playlist', function () {
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'profiles_enabled' => true,
        'xtream_config' => null,
    ]);

    ProfileService::syncPrimaryProfile($playlist);

    expect($playlist->profiles()->where('is_primary', true)->exists())->toBeFalse();
});

it('re-enables an auto-disabled primary and clears the old account info when credentials change', function () {
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'profiles_enabled' => true,
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://new.provider.cc:8080',
            'username' => 'newuser',
            'password' => 'newpass',
        ],
    ]);

    // The old account expired, so the refresh job auto-disabled the primary.
    $primaryProfile = PlaylistProfile::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Primary Account',
        'url' => 'http://old.provider.cc:80',
        'username' => 'olduser',
        'password' => 'oldpass',
        'max_streams' => 1,
        'priority' => 0,
        'enabled' => false,
        'is_primary' => true,
        'provider_info' => ['user_info' => ['exp_date' => (string) now()->subDay()->timestamp, 'max_connections' => '1']],
        'provider_info_updated_at' => now(),
    ]);

    ProfileService::syncPrimaryProfile($playlist);

    $primaryProfile->refresh();

    expect($primaryProfile->enabled)->toBeTrue()
        ->and($primaryProfile->provider_info)->toBe([])
        ->and($primaryProfile->provider_info_updated_at)->toBeNull();

    Queue::assertPushed(RefreshPlaylistProfiles::class, fn (RefreshPlaylistProfiles $job) => $job->profileId === $primaryProfile->id);
});

it('leaves a disabled primary alone when credentials have not changed', function () {
    $playlist = Playlist::factory()->create([
        'user_id' => $this->user->id,
        'profiles_enabled' => true,
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://provider.cc:8080',
            'username' => 'user',
            'password' => 'pass',
        ],
    ]);

    $primaryProfile = PlaylistProfile::create([
        'playlist_id' => $playlist->id,
        'user_id' => $this->user->id,
        'name' => 'Primary Account',
        'url' => 'http://provider.cc:8080',
        'username' => 'user',
        'password' => 'pass',
        'max_streams' => 1,
        'priority' => 0,
        'enabled' => false,
        'is_primary' => true,
    ]);

    ProfileService::syncPrimaryProfile($playlist);

    expect($primaryProfile->refresh()->enabled)->toBeFalse();
    Queue::assertNotPushed(RefreshPlaylistProfiles::class);
});
