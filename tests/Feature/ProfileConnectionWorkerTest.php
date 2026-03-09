<?php

use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    config(['proxy.m3u_proxy_host' => 'http://localhost:8765']);
    config(['proxy.m3u_proxy_port' => null]);
    config(['proxy.m3u_proxy_token' => 'test-token']);
    config(['cache.default' => 'array']);
});

it('corrects a drifted profile connection count on --once', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['profiles_enabled' => true]);
    $profile = PlaylistProfile::factory()->forPlaylist($playlist)->create();

    Redis::set("playlist_profile:{$profile->id}:connections", 2);

    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [
                ['metadata' => ['provider_profile_id' => $profile->id], 'client_count' => 1],
            ],
        ], 200),
    ]);

    $this->artisan('profiles:worker --once')
        ->assertExitCode(0);

    expect((int) Redis::get("playlist_profile:{$profile->id}:connections"))->toBe(1);
});

it('resets to zero when proxy reports no active streams', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['profiles_enabled' => true]);
    $profile = PlaylistProfile::factory()->forPlaylist($playlist)->create();

    Redis::set("playlist_profile:{$profile->id}:connections", 3);

    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => []], 200),
    ]);

    $this->artisan('profiles:worker --once')
        ->assertExitCode(0);

    expect((int) Redis::get("playlist_profile:{$profile->id}:connections"))->toBe(0);
});

it('skips correction when proxy API is unavailable', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['profiles_enabled' => true]);
    $profile = PlaylistProfile::factory()->forPlaylist($playlist)->create();

    Redis::set("playlist_profile:{$profile->id}:connections", 5);

    Http::fake([
        '*/streams/by-metadata*' => Http::response(null, 503),
    ]);

    $this->artisan('profiles:worker --once')
        ->assertExitCode(0);

    // Count must remain untouched — no zeroing on API failure
    expect((int) Redis::get("playlist_profile:{$profile->id}:connections"))->toBe(5);
});

it('does nothing when no playlists have profiles enabled', function () {
    Playlist::factory()->for($this->user)->create(['profiles_enabled' => false]);

    Http::fake();

    $this->artisan('profiles:worker --once')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('leaves counts untouched when already in sync', function () {
    $playlist = Playlist::factory()->for($this->user)->create(['profiles_enabled' => true]);
    $profile = PlaylistProfile::factory()->forPlaylist($playlist)->create();

    Redis::set("playlist_profile:{$profile->id}:connections", 2);

    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [
                ['metadata' => ['provider_profile_id' => $profile->id], 'client_count' => 2],
            ],
        ], 200),
    ]);

    $this->artisan('profiles:worker --once')
        ->assertExitCode(0);

    expect((int) Redis::get("playlist_profile:{$profile->id}:connections"))->toBe(2);
});
