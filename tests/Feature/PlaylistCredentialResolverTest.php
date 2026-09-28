<?php

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\PlaylistCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory() fires SyncPipelineService -> dispatch(ProcessM3uImport).
    // Bus::fake() catches it; nothing reaches Redis during tests.
    Bus::fake();
});

// --- resolve(): Method 1 (PlaylistAuth credentials) ---

it('resolves a Playlist via the PlaylistAuth credentials path (Method 1)', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $auth = PlaylistAuth::create([
        'name' => 'Guest',
        'username' => 'guest-1',
        'password' => 'guest-pass',
        'enabled' => true,
        'user_id' => $user->id,
    ]);
    $playlist->playlistAuths()->attach($auth);

    $resolved = (new PlaylistCredentialResolver)->resolve('guest-1', 'guest-pass');

    expect($resolved)->not->toBeNull();
    expect($resolved->is($playlist))->toBeTrue();
});

it('rejects a PlaylistAuth whose credentials are disabled', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $auth = PlaylistAuth::create([
        'name' => 'Disabled',
        'username' => 'guest-d',
        'password' => 'guest-pass-d',
        'enabled' => false,
        'user_id' => $user->id,
    ]);
    $playlist->playlistAuths()->attach($auth);

    $resolved = (new PlaylistCredentialResolver)->resolve('guest-d', 'guest-pass-d');

    // Resolver also requires the username to match the owner's name as
    // a fallback, so a disabled auth with no UUID match returns null.
    expect($resolved)->toBeNull();
});

it('rejects an expired PlaylistAuth', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $auth = PlaylistAuth::create([
        'name' => 'Expired',
        'username' => 'guest-e',
        'password' => 'guest-pass-e',
        'enabled' => true,
        'user_id' => $user->id,
        'expires_at' => Carbon::now()->subDay(),
    ]);
    $playlist->playlistAuths()->attach($auth);

    $resolved = (new PlaylistCredentialResolver)->resolve('guest-e', 'guest-pass-e');

    expect($resolved)->toBeNull();
});

// --- resolve(): Method 2 (UUID + owner name) ---

it('resolves a Playlist via UUID + owner name (Method 2)', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $resolved = (new PlaylistCredentialResolver)->resolve($user->name, $playlist->uuid);

    expect($resolved)->not->toBeNull();
    expect($resolved->is($playlist))->toBeTrue();
});

it('resolves a CustomPlaylist via UUID + owner name (Method 2)', function () {
    $user = User::factory()->create();
    $custom = CustomPlaylist::factory()->for($user)->create();

    $resolved = (new PlaylistCredentialResolver)->resolve($user->name, $custom->uuid);

    expect($resolved)->not->toBeNull();
    expect($resolved->is($custom))->toBeTrue();
});

it('resolves a MergedPlaylist via UUID + owner name (Method 2)', function () {
    $user = User::factory()->create();
    $merged = MergedPlaylist::factory()->for($user)->create();

    $resolved = (new PlaylistCredentialResolver)->resolve($user->name, $merged->uuid);

    expect($resolved)->not->toBeNull();
    expect($resolved->is($merged))->toBeTrue();
});

it('returns null when the UUID belongs to a different user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $playlistA = Playlist::factory()->for($userA)->create();

    // userB supplies their own name but the UUID belongs to userA.
    $resolved = (new PlaylistCredentialResolver)->resolve($userB->name, $playlistA->uuid);

    expect($resolved)->toBeNull();
});

it('returns null when the username does not match the playlist owner name', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $resolved = (new PlaylistCredentialResolver)->resolve('wrong-name', $playlist->uuid);

    expect($resolved)->toBeNull();
});

it('returns null when neither credentials nor UUID match', function () {
    $resolved = (new PlaylistCredentialResolver)->resolve('nobody', 'no-such-uuid');

    expect($resolved)->toBeNull();
});

// --- resolveAuth() direct path ---

it('resolveAuth() returns the matching PlaylistAuth record (Method 1 details)', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $auth = PlaylistAuth::create([
        'name' => 'Target',
        'username' => 'auth-username',
        'password' => 'auth-password',
        'enabled' => true,
        'user_id' => $user->id,
    ]);
    $playlist->playlistAuths()->attach($auth);

    $resolved = (new PlaylistCredentialResolver)->resolveAuth('auth-username', 'auth-password');

    expect($resolved)->not->toBeNull();
    expect($resolved->is($auth))->toBeTrue();
});

it('resolveAuth() returns null for a wrong password', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $auth = PlaylistAuth::create([
        'name' => 'Target',
        'username' => 'auth-username',
        'password' => 'auth-password',
        'enabled' => true,
        'user_id' => $user->id,
    ]);
    $playlist->playlistAuths()->attach($auth);

    $resolved = (new PlaylistCredentialResolver)->resolveAuth('auth-username', 'wrong-password');

    expect($resolved)->toBeNull();
});
