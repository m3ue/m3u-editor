<?php

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use App\Services\EpgCacheService;
use Illuminate\Support\Facades\Storage;

// GitHub issue #1294: Playlist Aliases did not have their cached EPG XMLTV
// file invalidated in two situations:
//
// 1. An alias pointing at a Custom Playlist was never resolved by
//    Epg::getPlaylistAliases(), so MapEpgToChannelsComplete never cleared
//    its cache after a mapping run.
// 2. Editing an existing alias to point at a different playlist/custom
//    playlist left its cache file untouched, still serving XMLTV generated
//    against the old target.

beforeEach(function () {
    Storage::fake('local');
});

it('resolves playlist aliases via both playlist_id and custom_playlist_id', function () {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create();

    $playlist = Playlist::factory()->for($user)->create();
    $customPlaylist = CustomPlaylist::factory()->for($user)->create();

    // Channel mapped directly on the source playlist.
    Channel::factory()->for($user)->for($playlist)->create([
        'epg_channel_id' => $epgChannel->id,
    ]);

    // Channel mapped via a custom playlist (through the pivot).
    $customChannel = Channel::factory()->for($user)->for($playlist)->create([
        'epg_channel_id' => $epgChannel->id,
    ]);
    $customPlaylist->channels()->attach($customChannel->id);

    $aliasOnPlaylist = PlaylistAlias::create([
        'name' => 'Alias on Playlist',
        'uuid' => fake()->uuid(),
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'xtream_config' => null,
    ]);

    $aliasOnCustomPlaylist = PlaylistAlias::create([
        'name' => 'Alias on Custom Playlist',
        'uuid' => fake()->uuid(),
        'user_id' => $user->id,
        'custom_playlist_id' => $customPlaylist->id,
        'xtream_config' => null,
    ]);

    $aliasIds = $epg->getPlaylistAliases()->pluck('id')->all();

    expect($aliasIds)
        ->toContain($aliasOnPlaylist->id)
        ->toContain($aliasOnCustomPlaylist->id);
});

it('clears the alias EPG cache when its playlist_id changes', function () {
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create();
    $playlistB = Playlist::factory()->for($user)->create();

    $alias = PlaylistAlias::create([
        'name' => 'Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $user->id,
        'playlist_id' => $playlistA->id,
        'xtream_config' => null,
    ]);

    $cachePath = EpgCacheService::getPlaylistEpgCachePath($alias);
    Storage::disk('local')->put($cachePath, '<tv></tv>');
    expect(Storage::disk('local')->exists($cachePath))->toBeTrue();

    $alias->update(['playlist_id' => $playlistB->id]);

    expect(Storage::disk('local')->exists($cachePath))->toBeFalse();
});

it('clears the alias EPG cache when its custom_playlist_id changes', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $customPlaylistA = CustomPlaylist::factory()->for($user)->create();
    $customPlaylistB = CustomPlaylist::factory()->for($user)->create();

    $alias = PlaylistAlias::create([
        'name' => 'Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'custom_playlist_id' => $customPlaylistA->id,
        'xtream_config' => null,
    ]);

    $cachePath = EpgCacheService::getPlaylistEpgCachePath($alias);
    Storage::disk('local')->put($cachePath, '<tv></tv>');
    expect(Storage::disk('local')->exists($cachePath))->toBeTrue();

    $alias->update(['custom_playlist_id' => $customPlaylistB->id]);

    expect(Storage::disk('local')->exists($cachePath))->toBeFalse();
});

it('does not clear the alias EPG cache for unrelated field updates', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $alias = PlaylistAlias::create([
        'name' => 'Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'xtream_config' => null,
    ]);

    $cachePath = EpgCacheService::getPlaylistEpgCachePath($alias);
    Storage::disk('local')->put($cachePath, '<tv></tv>');

    $alias->update(['name' => 'Renamed Alias']);

    expect(Storage::disk('local')->exists($cachePath))->toBeTrue();
});
