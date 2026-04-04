<?php

use App\Jobs\ProbeChannelStreams;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

// ─────────────────────────────────────────────────────────────────────────────
// resolvePrimaryProfile
// ─────────────────────────────────────────────────────────────────────────────

it('resolvePrimaryProfile returns null when playlist profiles are disabled', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly(['profiles_enabled' => false]);
    PlaylistProfile::factory()->primary()->forPlaylist($playlist)->withProviderInfo(0, 3)->create();

    $channel = Channel::factory()->for($playlist)->create(['enabled' => true, 'is_vod' => false]);
    $channels = collect([$channel]);

    $job = new ProbeChannelStreams(playlistId: $playlist->id);
    $method = new ReflectionMethod($job, 'resolvePrimaryProfile');
    $result = $method->invoke($job, $channels);

    expect($result)->toBeNull();
});

it('resolvePrimaryProfile returns null when playlist has no primary profile', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly(['profiles_enabled' => true]);
    // No is_primary profile created

    $channels = collect([Channel::factory()->for($playlist)->create()]);

    $job = new ProbeChannelStreams(playlistId: $playlist->id);
    $method = new ReflectionMethod($job, 'resolvePrimaryProfile');
    $result = $method->invoke($job, $channels);

    expect($result)->toBeNull();
});

it('resolvePrimaryProfile returns primary profile when profiles are enabled and configured', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly(['profiles_enabled' => true]);
    $profile = PlaylistProfile::factory()->primary()->forPlaylist($playlist)->withProviderInfo(0, 3)->create();

    $channels = collect([Channel::factory()->for($playlist)->create()]);

    $job = new ProbeChannelStreams(playlistId: $playlist->id);
    $method = new ReflectionMethod($job, 'resolvePrimaryProfile');
    $result = $method->invoke($job, $channels);

    expect($result)->toBeInstanceOf(PlaylistProfile::class)
        ->and($result->id)->toBe($profile->id);
});

it('resolvePrimaryProfile resolves profile from channel playlist when probing by channel IDs', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly(['profiles_enabled' => true]);
    $profile = PlaylistProfile::factory()->primary()->forPlaylist($playlist)->withProviderInfo(0, 3)->create();
    $channel = Channel::factory()->for($playlist)->create(['enabled' => true, 'is_vod' => false]);

    $job = new ProbeChannelStreams(channelIds: [$channel->id]);
    $channels = collect([$channel->load('playlist')]);

    $method = new ReflectionMethod($job, 'resolvePrimaryProfile');
    $result = $method->invoke($job, $channels);

    expect($result)->toBeInstanceOf(PlaylistProfile::class)
        ->and($result->id)->toBe($profile->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// flushProviderInfoCache
// ─────────────────────────────────────────────────────────────────────────────

it('flushProviderInfoCache clears the cached provider_info entry', function () {
    $profile = PlaylistProfile::factory()
        ->forPlaylist($this->playlist)
        ->withProviderInfo(2, 5)
        ->create(['user_id' => $this->user->id]);

    $cacheKey = "playlist_profile:{$profile->id}:provider_info";
    Cache::put($cacheKey, ['user_info' => ['active_cons' => 2]], 60);

    expect(Cache::has($cacheKey))->toBeTrue();

    $job = new ProbeChannelStreams;
    $method = new ReflectionMethod($job, 'flushProviderInfoCache');
    $method->invoke($job, $profile);

    expect(Cache::has($cacheKey))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// waitForAvailableConnectionSlot
// ─────────────────────────────────────────────────────────────────────────────

it('waitForAvailableConnectionSlot returns immediately when provider_info is empty', function () {
    $profile = PlaylistProfile::factory()
        ->primary()
        ->forPlaylist($this->playlist)
        ->create(['user_id' => $this->user->id, 'provider_info' => null]);

    $job = new ProbeChannelStreams(playlistId: $this->playlist->id);
    $method = new ReflectionMethod($job, 'waitForAvailableConnectionSlot');

    $start = microtime(true);
    $method->invoke($job, $profile);
    $elapsed = microtime(true) - $start;

    // No waiting should happen when provider_info is missing
    expect($elapsed)->toBeLessThan(1.0);
});

it('waitForAvailableConnectionSlot returns immediately when connection slots are available', function () {
    $profile = PlaylistProfile::factory()
        ->primary()
        ->forPlaylist($this->playlist)
        ->withProviderInfo(activeConnections: 1, maxConnections: 3)
        ->create(['user_id' => $this->user->id, 'max_streams' => 3]);

    $job = new ProbeChannelStreams(playlistId: $this->playlist->id);
    $method = new ReflectionMethod($job, 'waitForAvailableConnectionSlot');

    $start = microtime(true);
    $method->invoke($job, $profile->fresh());
    $elapsed = microtime(true) - $start;

    // Should return without any polling delay (slot is available)
    expect($elapsed)->toBeLessThan(1.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Request delay integration
// ─────────────────────────────────────────────────────────────────────────────

it('applies no delay when request delay setting is disabled', function () {
    $settings = app(GeneralSettings::class);
    $settings->enable_provider_request_delay = false;
    $settings->save();

    // Zero-channel playlist → job exits early, no delay path is hit
    $emptyPlaylist = Playlist::factory()->for($this->user)->createQuietly();
    $job = new ProbeChannelStreams(playlistId: $emptyPlaylist->id);
    $job->handle();

    Notification::assertNothingSent();
});

it('the job skips connection-aware waiting when playlist does not have profiles enabled', function () {
    $playlist = Playlist::factory()->for($this->user)->createQuietly(['profiles_enabled' => false]);

    // Even with a primary profile, it should not be resolved for connection checks
    PlaylistProfile::factory()->primary()->forPlaylist($playlist)->withProviderInfo(5, 5)->create();

    $job = new ProbeChannelStreams(playlistId: $playlist->id);
    $channels = collect(); // empty to hit early return

    $method = new ReflectionMethod($job, 'resolvePrimaryProfile');
    $result = $method->invoke($job, $channels);

    expect($result)->toBeNull();
});

it('available_streams calculation is correct when all slots are in use', function () {
    $profile = PlaylistProfile::factory()
        ->primary()
        ->forPlaylist($this->playlist)
        ->withProviderInfo(activeConnections: 3, maxConnections: 3)
        ->create(['user_id' => $this->user->id, 'max_streams' => 3]);

    expect($profile->fresh()->available_streams)->toBe(0)
        ->and($profile->fresh()->current_connections)->toBe(3)
        ->and($profile->fresh()->effective_max_streams)->toBe(3);
});

it('available_streams reflects free slots when connections are below the limit', function () {
    $profile = PlaylistProfile::factory()
        ->primary()
        ->forPlaylist($this->playlist)
        ->withProviderInfo(activeConnections: 1, maxConnections: 4)
        ->create(['user_id' => $this->user->id, 'max_streams' => 4]);

    expect($profile->fresh()->available_streams)->toBe(3);
});
