<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistProfile;

test('it resolves directly to the target playlist channel url when the profile points at a local playlist', function () {
    $poolPlaylist = Playlist::factory()->create([
        'xtream_config' => [
            'url' => 'http://primary-provider.test',
            'username' => 'pooluser',
            'password' => 'poolpass',
        ],
    ]);

    $targetPlaylist = Playlist::factory()->create();

    $sourceChannel = Channel::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'source_id' => 'shared-source-id',
    ]);

    $targetChannel = Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'source_id' => 'shared-source-id',
        'enabled' => true,
        'url' => 'http://real-upstream-provider.test/live/realuser/realpass/999.ts',
    ]);

    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'url' => url('/'),
        'password' => $targetPlaylist->uuid,
    ]);

    expect($profile->transformChannelUrl($sourceChannel))
        ->toBe($targetChannel->url_custom ?? $targetChannel->url);
});

test('it resolves to the target live channel when a movie shares its source_id', function () {
    $poolPlaylist = Playlist::factory()->create();
    $targetPlaylist = Playlist::factory()->create();

    $sourceChannel = Channel::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'source_id' => '100',
        'is_vod' => false,
    ]);

    Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'source_id' => '100',
        'is_vod' => true,
        'enabled' => true,
        'url' => 'http://real-upstream-provider.test/movie/realuser/realpass/100.mkv',
    ]);
    $targetChannel = Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'source_id' => '100',
        'is_vod' => false,
        'enabled' => true,
        'url' => 'http://real-upstream-provider.test/live/realuser/realpass/100.ts',
    ]);

    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'url' => url('/'),
        'password' => $targetPlaylist->uuid,
    ]);

    expect($profile->transformChannelUrl($sourceChannel))->toBe($targetChannel->url);
});

test('it resolves directly to the target playlist episode url when the profile points at a local playlist', function () {
    $poolPlaylist = Playlist::factory()->create();
    $targetPlaylist = Playlist::factory()->create();

    $sourceEpisode = Episode::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'source_episode_id' => 555555,
    ]);

    $targetEpisode = Episode::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'source_episode_id' => 555555,
        'enabled' => true,
        'url' => 'http://real-upstream-provider.test/series/realuser/realpass/42.mkv',
    ]);

    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'url' => url('/'),
        'password' => $targetPlaylist->uuid,
    ]);

    expect($profile->transformEpisodeUrl($sourceEpisode))->toBe($targetEpisode->url);
});

test('it leaves the credential swap path untouched for direct-to-provider profiles', function () {
    $playlist = Playlist::factory()->create([
        'xtream_config' => [
            'url' => 'http://primary-provider.test',
            'username' => 'primaryuser',
            'password' => 'primarypass',
        ],
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'url' => 'http://primary-provider.test/live/primaryuser/primarypass/123.ts',
    ]);

    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $playlist->id,
        'url' => 'http://secondary-provider.test',
        'username' => 'secondaryuser',
        'password' => 'secondarypass',
    ]);

    expect($profile->transformChannelUrl($channel))
        ->toBe('http://secondary-provider.test/live/secondaryuser/secondarypass/123.ts');
});

test('it resolves via exact name match when source_id lookup fails (2-step setup)', function () {
    $poolPlaylist = Playlist::factory()->create([
        'xtream_config' => [
            'url' => 'http://primary-provider.test',
            'username' => 'pooluser',
            'password' => 'poolpass',
        ],
    ]);

    $targetPlaylist = Playlist::factory()->create();

    // Xtream-imported source: numeric source_id.
    $sourceChannel = Channel::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'source_id' => '1918533',
        'name' => 'TSN 1',
    ]);

    // M3U-imported target: hash-based source_id that will never match, same name.
    $targetChannel = Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'source_id' => 'a1b2c3d4e5',
        'name' => 'TSN 1',
        'enabled' => true,
        'url' => 'http://real-upstream-provider.test/live/realuser/realpass/999.ts',
    ]);

    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'url' => url('/'),
        'password' => $targetPlaylist->uuid,
    ]);

    expect($profile->transformChannelUrl($sourceChannel))->toBe($targetChannel->url);
});

test('it does not name-match when multiple target channels share the name and groups differ', function () {
    $poolPlaylist = Playlist::factory()->create([
        'xtream_config' => [
            'url' => 'http://primary-provider.test',
            'username' => 'pooluser',
            'password' => 'poolpass',
        ],
    ]);

    $targetPlaylist = Playlist::factory()->create();

    $sourceChannel = Channel::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'source_id' => '1918533',
        'name' => 'AMC',
        'group' => 'USA',
        'url' => 'http://primary-provider.test/live/pooluser/poolpass/123.ts',
    ]);

    Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'source_id' => 'hash-1',
        'name' => 'AMC',
        'group' => 'Movies',
        'enabled' => true,
    ]);
    Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'source_id' => 'hash-2',
        'name' => 'AMC',
        'group' => 'Entertainment',
        'enabled' => true,
    ]);

    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'url' => url('/'),
        'username' => 'fallbackuser',
        'password' => $targetPlaylist->uuid,
    ]);

    // Ambiguous, no group tie-break - falls through to the plain string swap.
    expect($profile->transformChannelUrl($sourceChannel))
        ->toBe(rtrim(url('/'), '/').'/live/fallbackuser/'.$targetPlaylist->uuid.'/123.ts');
});

test('it name-matches with the group string as a tie-breaker when the name is not unique', function () {
    $poolPlaylist = Playlist::factory()->create([
        'xtream_config' => [
            'url' => 'http://primary-provider.test',
            'username' => 'pooluser',
            'password' => 'poolpass',
        ],
    ]);

    $targetPlaylist = Playlist::factory()->create();

    $sourceChannel = Channel::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'source_id' => '1918533',
        'name' => 'AMC',
        'group' => 'USA',
    ]);

    $wrongGroup = Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'source_id' => 'hash-1',
        'name' => 'AMC',
        'group' => 'Canada',
        'enabled' => true,
        'url' => 'http://real-upstream-provider.test/live/realuser/realpass/111.ts',
    ]);
    $rightGroup = Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'source_id' => 'hash-2',
        'name' => 'AMC',
        'group' => 'USA',
        'enabled' => true,
        'url' => 'http://real-upstream-provider.test/live/realuser/realpass/222.ts',
    ]);

    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'url' => url('/'),
        'password' => $targetPlaylist->uuid,
    ]);

    expect($profile->transformChannelUrl($sourceChannel))->toBe($rightGroup->url);
});

test('it falls back to the plain credential swap when an internal profile has no matching target channel', function () {
    $poolPlaylist = Playlist::factory()->create([
        'xtream_config' => [
            'url' => 'http://primary-provider.test',
            'username' => 'pooluser',
            'password' => 'poolpass',
        ],
    ]);

    $targetPlaylist = Playlist::factory()->create();

    $sourceChannel = Channel::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'source_id' => 'unmatched-source-id',
        'url' => 'http://primary-provider.test/live/pooluser/poolpass/123.ts',
    ]);

    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'url' => url('/'),
        'username' => 'fallbackuser',
        'password' => $targetPlaylist->uuid,
    ]);

    // resolveInternalUrl() finds no matching channel via source_id, so this falls
    // through to the plain string swap - still internally consistent (stream ID
    // untouched), just not the direct-resolution shortcut.
    expect($profile->transformChannelUrl($sourceChannel))
        ->toBe(rtrim(url('/'), '/').'/live/fallbackuser/'.$targetPlaylist->uuid.'/123.ts');
});
