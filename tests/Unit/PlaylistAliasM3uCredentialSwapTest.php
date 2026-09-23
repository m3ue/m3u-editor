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

function makeMultiEntryCredentialSwapAlias(Playlist $playlist, array $configs): PlaylistAlias
{
    return PlaylistAlias::create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'Test Alias',
        'uuid' => Str::uuid()->toString(),
        'xtream_config' => $configs,
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

it('rewrites xtream shaped urls for single entry aliases even without a provider match', function () {
    // Single-entry aliases mirror Xtream-playlist behavior: the one URL and
    // credential set the user entered is what clients receive, whatever provider
    // URL the streams carry. Note this includes numeric CDN-shaped URLs — with a
    // single entry the user has said "rewrite this playlist's streams", and
    // non-numeric CDN URLs are still left untouched by the parser.
    $playlist = makeM3uPlaylist();
    $alias = makeCredentialSwapAlias($playlist, [
        'url' => 'http://alias.example.com:8080',
        'username' => 'newuser',
        'password' => 'newpass',
    ]);

    $cases = [
        'http://unknown-provider.com:9000/user/pass/5678.ts' => 'http://alias.example.com:8080/newuser/newpass/5678.ts',
        'http://other-provider.com:8080/live/olduser/oldpass/42.ts' => 'http://alias.example.com:8080/live/newuser/newpass/42.ts',
    ];

    foreach ($cases as $original => $expected) {
        $channel = Channel::factory()->create([
            'playlist_id' => $playlist->id,
            'user_id' => $playlist->user_id,
            'group_id' => null,
            'url' => $original,
        ]);

        $this->assertSame($expected, $alias->transformChannelUrl($channel), "Failed rewriting: {$original}");
    }
});

it('leaves urls untouched for multi entry aliases when no entry matches', function () {
    // Multi-entry aliases (custom/merged) keep strict per-provider matching: a
    // stream is only rewritten when its provider URL matches one of the entries,
    // so provider B's streams are never rewritten with provider A's credentials.
    $playlist = makeM3uPlaylist();
    $alias = makeMultiEntryCredentialSwapAlias($playlist, [
        [
            'url' => 'http://provider-a.example.com:8080',
            'username' => 'userA',
            'password' => 'passA',
        ],
        [
            'url' => 'http://provider-b.example.com:8080',
            'username' => 'userB',
            'password' => 'passB',
        ],
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
