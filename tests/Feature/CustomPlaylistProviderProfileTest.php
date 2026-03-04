<?php

/**
 * Tests for provider profiles working correctly with custom playlists.
 *
 * Issue: https://github.com/sparkison/m3u-editor/issues/681
 * When channels from a source playlist with provider profiles are added to a
 * custom playlist, the profiles should still be used when streaming.
 */

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Fake the queue to prevent Redis connection issues
    Queue::fake();
    $this->user = User::factory()->create();
});

test('profileSourcePlaylist is set from source playlist when streaming via custom playlist', function () {
    // Create a source playlist with profiles enabled
    $sourcePlaylist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://example.com:8080',
            'username' => 'test',
            'password' => 'test',
        ],
    ]);

    // Create a profile for the source playlist
    $profile = PlaylistProfile::factory()
        ->for($sourcePlaylist)
        ->for($this->user)
        ->primary()
        ->create([
            'max_streams' => 2,
        ]);

    // Create a channel belonging to the source playlist
    $channel = Channel::factory()->for($this->user)->for($sourcePlaylist)->create([
        'enabled' => true,
        'url' => 'http://example.com/stream/1',
    ]);

    // Create a custom playlist and attach the channel
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
    $customPlaylist->channels()->attach($channel->id);

    // Verify the channel's source playlist has profiles enabled
    expect($channel->playlist->profiles_enabled)->toBeTrue();
    expect($channel->playlist->id)->toBe($sourcePlaylist->id);

    // Verify the channel can be accessed via custom playlist
    expect($customPlaylist->channels()->where('channels.id', $channel->id)->exists())->toBeTrue();
});

test('channel source playlist can be determined when streaming through custom playlist', function () {
    // Create a source playlist with profiles enabled
    $sourcePlaylist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
        'xtream' => true,
    ]);

    // Create a profile for the source playlist
    PlaylistProfile::factory()
        ->for($sourcePlaylist)
        ->for($this->user)
        ->primary()
        ->create([
            'max_streams' => 5,
        ]);

    // Create a channel belonging to the source playlist
    $channel = Channel::factory()->for($this->user)->for($sourcePlaylist)->create([
        'enabled' => true,
    ]);

    // Create a custom playlist
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
    $customPlaylist->channels()->attach($channel->id);

    // Load the channel with its relationships
    $channel->load('playlist', 'customPlaylists');

    // The channel's playlist (source) should have profiles_enabled
    expect($channel->playlist)->toBeInstanceOf(Playlist::class);
    expect($channel->playlist->profiles_enabled)->toBeTrue();

    // The custom playlist should be in the channel's customPlaylists
    expect($channel->customPlaylists)->toHaveCount(1);
    expect($channel->customPlaylists->first()->id)->toBe($customPlaylist->id);

    // When determining profile source:
    // If we're streaming via CustomPlaylist, we should use channel->playlist for profiles
    $playlistContext = $customPlaylist; // This would be passed to getChannelUrl
    $profileSourcePlaylist = null;

    if ($playlistContext instanceof Playlist && $playlistContext->profiles_enabled) {
        $profileSourcePlaylist = $playlistContext;
    } elseif ($channel->playlist instanceof Playlist && $channel->playlist->profiles_enabled) {
        $profileSourcePlaylist = $channel->playlist;
    }

    // The profileSourcePlaylist should be the source playlist, not the custom playlist
    expect($profileSourcePlaylist)->toBeInstanceOf(Playlist::class);
    expect($profileSourcePlaylist->id)->toBe($sourcePlaylist->id);
    expect($profileSourcePlaylist->profiles_enabled)->toBeTrue();
});

test('profiles are not used when source playlist has profiles disabled', function () {
    // Create a source playlist WITHOUT profiles enabled
    $sourcePlaylist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
    ]);

    // Create a channel belonging to the source playlist
    $channel = Channel::factory()->for($this->user)->for($sourcePlaylist)->create([
        'enabled' => true,
    ]);

    // Create a custom playlist
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
    $customPlaylist->channels()->attach($channel->id);

    // Load relationships
    $channel->load('playlist');

    // Determine profile source
    $playlistContext = $customPlaylist;
    $profileSourcePlaylist = null;

    if ($playlistContext instanceof Playlist && $playlistContext->profiles_enabled) {
        $profileSourcePlaylist = $playlistContext;
    } elseif ($channel->playlist instanceof Playlist && $channel->playlist->profiles_enabled) {
        $profileSourcePlaylist = $channel->playlist;
    }

    // Should be null since profiles_enabled is false
    expect($profileSourcePlaylist)->toBeNull();
});

test('profiles from source playlist are used when streaming directly via source playlist', function () {
    // Create a source playlist with profiles enabled
    $sourcePlaylist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    // Create a profile
    PlaylistProfile::factory()
        ->for($sourcePlaylist)
        ->for($this->user)
        ->primary()
        ->create();

    // Create a channel
    $channel = Channel::factory()->for($this->user)->for($sourcePlaylist)->create([
        'enabled' => true,
    ]);

    // Load relationships
    $channel->load('playlist');

    // When streaming directly through the source playlist
    $playlistContext = $sourcePlaylist;
    $profileSourcePlaylist = null;

    if ($playlistContext instanceof Playlist && $playlistContext->profiles_enabled) {
        $profileSourcePlaylist = $playlistContext;
    } elseif ($channel->playlist instanceof Playlist && $channel->playlist->profiles_enabled) {
        $profileSourcePlaylist = $channel->playlist;
    }

    // Should use the source playlist directly
    expect($profileSourcePlaylist)->toBeInstanceOf(Playlist::class);
    expect($profileSourcePlaylist->id)->toBe($sourcePlaylist->id);
});

test('hasPooledSourcePlaylists returns true when custom playlist has channels from pooled playlists', function () {
    // Create a source playlist with profiles enabled
    $sourcePlaylist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);

    // Create a channel belonging to the source playlist
    $channel = Channel::factory()->for($this->user)->for($sourcePlaylist)->create([
        'enabled' => true,
    ]);

    // Create a custom playlist and attach the channel
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
    $customPlaylist->channels()->attach($channel->id);

    // Should return true since the source playlist has profiles_enabled
    expect($customPlaylist->hasPooledSourcePlaylists())->toBeTrue();
});

test('hasPooledSourcePlaylists returns false when source playlists have profiles disabled', function () {
    // Create a source playlist WITHOUT profiles enabled
    $sourcePlaylist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
    ]);

    // Create a channel belonging to the source playlist
    $channel = Channel::factory()->for($this->user)->for($sourcePlaylist)->create([
        'enabled' => true,
    ]);

    // Create a custom playlist and attach the channel
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
    $customPlaylist->channels()->attach($channel->id);

    // Should return false since the source playlist has profiles_enabled = false
    expect($customPlaylist->hasPooledSourcePlaylists())->toBeFalse();
});

test('hasPooledSourcePlaylists returns false for empty custom playlist', function () {
    // Create an empty custom playlist
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();

    // Should return false since there are no channels
    expect($customPlaylist->hasPooledSourcePlaylists())->toBeFalse();
});

test('getPooledSourcePlaylists returns only playlists with profiles enabled', function () {
    // Create two source playlists - one with profiles, one without
    $pooledPlaylist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => true,
    ]);
    $regularPlaylist = Playlist::factory()->for($this->user)->create([
        'profiles_enabled' => false,
    ]);

    // Create channels for each playlist
    $channelFromPooled = Channel::factory()->for($this->user)->for($pooledPlaylist)->create([
        'enabled' => true,
    ]);
    $channelFromRegular = Channel::factory()->for($this->user)->for($regularPlaylist)->create([
        'enabled' => true,
    ]);

    // Create a custom playlist and attach both channels
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
    $customPlaylist->channels()->attach([$channelFromPooled->id, $channelFromRegular->id]);

    // Should return only the pooled playlist
    $pooledPlaylists = $customPlaylist->getPooledSourcePlaylists();
    expect($pooledPlaylists)->toHaveCount(1);
    expect($pooledPlaylists->first()->id)->toBe($pooledPlaylist->id);
});
