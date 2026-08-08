<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeCredentialSwapAlias(Playlist $playlist, array $config): PlaylistAlias
{
    return PlaylistAlias::create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'Test Alias',
        'uuid' => Str::uuid()->toString(),
        'xtream_config' => [$config],
    ]);
}

function makeM3uPlaylist(): Playlist
{
    $user = User::factory()->create();

    // M3U-imported playlists have no stored xtream config
    return Playlist::factory()->for($user)->createQuietly(['xtream_config' => null]);
}

it('swaps credentials for m3u playlist channel with prefixed xtream url', function () {
    // For M3U playlists the alias config URL must match the provider URL embedded in
    // the stream. The user enters their provider URL + new credentials in the alias.
    $playlist = makeM3uPlaylist();
    $alias = makeCredentialSwapAlias($playlist, [
        'url' => 'http://provider.example.com:8080',
        'username' => 'newuser',
        'password' => 'newpass',
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'url' => 'http://provider.example.com:8080/live/olduser/oldpass/1234.ts',
    ]);

    $this->assertSame(
        'http://provider.example.com:8080/live/newuser/newpass/1234.ts',
        $alias->transformChannelUrl($channel)
    );
});

it('swaps credentials for m3u playlist channel with prefixless xtream url', function () {
    $playlist = makeM3uPlaylist();
    $alias = makeCredentialSwapAlias($playlist, [
        'url' => 'http://provider.example.com:8080',
        'username' => 'newuser',
        'password' => 'newpass',
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'url' => 'http://provider.example.com:8080/olduser/oldpass/1234',
    ]);

    $this->assertSame(
        'http://provider.example.com:8080/newuser/newpass/1234',
        $alias->transformChannelUrl($channel)
    );
});

it('leaves non xtream m3u channel urls untouched', function () {
    $playlist = makeM3uPlaylist();
    $alias = makeCredentialSwapAlias($playlist, [
        'url' => 'http://provider.example.com:8080',
        'username' => 'newuser',
        'password' => 'newpass',
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'url' => 'https://cdn.example.com/hls/stream/playlist.m3u8',
    ]);

    $this->assertSame(
        'https://cdn.example.com/hls/stream/playlist.m3u8',
        $alias->transformChannelUrl($channel)
    );
});

it('leaves urls untouched when provider is not registered in alias', function () {
    // Even if a URL looks Xtream-shaped (numeric stream ID in prefix-less form),
    // it must NOT be rewritten unless its base URL is in the alias's provider list.
    // This prevents CDN/HLS URLs from being accidentally rewritten.
    $playlist = makeM3uPlaylist();
    $alias = makeCredentialSwapAlias($playlist, [
        'url' => 'http://provider.example.com:8080',
        'username' => 'newuser',
        'password' => 'newpass',
    ]);

    foreach ([
        'https://cdn.example.com/hls/stream/42.ts',
        'https://cache.akamai.net/segments/live/99',
        'https://cdn.example.com/movies/action/1234.mp4',
        'http://unknown-provider.com:9000/user/pass/5678.ts',
    ] as $url) {
        $channel = Channel::factory()->create([
            'playlist_id' => $playlist->id,
            'user_id' => $playlist->user_id,
            'group_id' => null,
            'url' => $url,
        ]);

        $this->assertSame($url, $alias->transformChannelUrl($channel), "Expected URL to be untouched: {$url}");
    }
});

it('swaps credentials for m3u playlist episode url', function () {
    $playlist = makeM3uPlaylist();
    $alias = makeCredentialSwapAlias($playlist, [
        'url' => 'http://provider.example.com:8080',
        'username' => 'newuser',
        'password' => 'newpass',
    ]);

    $episode = Episode::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'url' => 'http://provider.example.com:8080/series/olduser/oldpass/999.mkv',
    ]);

    $this->assertSame(
        'http://provider.example.com:8080/series/newuser/newpass/999.mkv',
        $alias->transformEpisodeUrl($episode)
    );
});

it('still swaps credentials using the playlist xtream config when present', function () {
    // Xtream playlists have a stored xtream_config and support cross-server redirect
    // (source URL differs from alias URL) via the primaryAliasConfig fallback.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly([
        'xtream_config' => [
            'url' => 'http://source.example.com:8080',
            'username' => 'srcuser',
            'password' => 'srcpass',
        ],
    ]);

    $alias = makeCredentialSwapAlias($playlist, [
        'url' => 'http://alias.example.com:8080',
        'username' => 'newuser',
        'password' => 'newpass',
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'url' => 'http://source.example.com:8080/live/srcuser/srcpass/42.ts',
    ]);

    $this->assertSame(
        'http://alias.example.com:8080/live/newuser/newpass/42.ts',
        $alias->transformChannelUrl($channel)
    );
});
