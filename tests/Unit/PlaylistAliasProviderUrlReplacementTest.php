<?php

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @param  array<int, array<string, mixed>>  $entries
 */
function makeReplacementAlias(Playlist $playlist, array $entries): PlaylistAlias
{
    return PlaylistAlias::create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'name' => 'Test Alias',
        'uuid' => Str::uuid()->toString(),
        'xtream_config' => $entries,
    ]);
}

function makeReplacementChannel(Playlist $playlist, string $url): Channel
{
    return Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'url' => $url,
    ]);
}

function makeReplacementM3uPlaylist(): Playlist
{
    return Playlist::factory()->for(User::factory()->create())->createQuietly(['xtream_config' => null]);
}

function makeReplacementXtreamPlaylist(): Playlist
{
    return Playlist::factory()->for(User::factory()->create())->createQuietly([
        'xtream_config' => [
            'url' => 'http://source.example.com:8080',
            'username' => 'srcuser',
            'password' => 'srcpass',
        ],
    ]);
}

it('replaces the provider url of m3u streams without credentials', function (string $original, string $expected) {
    $playlist = makeReplacementM3uPlaylist();
    $alias = makeReplacementAlias($playlist, [[
        'url' => 'http://provider.example.com:8080',
        'replace_url_enabled' => true,
        'replace_url' => 'http://vpn.provider.example.com:8080',
    ]]);

    expect($alias->transformChannelUrl(makeReplacementChannel($playlist, $original)))->toBe($expected);
})->with([
    'prefixed xtream url' => [
        'http://provider.example.com:8080/live/user/pass/1234.ts',
        'http://vpn.provider.example.com:8080/live/user/pass/1234.ts',
    ],
    'prefix-less xtream url' => [
        'http://provider.example.com:8080/user/pass/1234',
        'http://vpn.provider.example.com:8080/user/pass/1234',
    ],
    'non-xtream hls url on the provider host' => [
        'http://provider.example.com:8080/hls/channel/index.m3u8',
        'http://vpn.provider.example.com:8080/hls/channel/index.m3u8',
    ],
    'provider url matched case-insensitively' => [
        'http://Provider.Example.com:8080/live/user/pass/1234.ts',
        'http://vpn.provider.example.com:8080/live/user/pass/1234.ts',
    ],
]);

it('leaves m3u streams from other providers untouched', function () {
    $playlist = makeReplacementM3uPlaylist();
    $alias = makeReplacementAlias($playlist, [[
        'url' => 'http://provider.example.com:8080',
        'replace_url_enabled' => true,
        'replace_url' => 'http://vpn.provider.example.com:8080',
    ]]);

    foreach ([
        'http://other-provider.example.com:8080/live/user/pass/1234.ts',
        'http://provider.example.com:9090/live/user/pass/1234.ts',
        'http://provider.example.com:80801/live/user/pass/1234.ts',
        'https://cdn.example.com/hls/stream/42.ts',
    ] as $url) {
        expect($alias->transformChannelUrl(makeReplacementChannel($playlist, $url)))->toBe($url);
    }
});

it('swaps credentials and replaces the provider url together', function () {
    $playlist = makeReplacementM3uPlaylist();
    $alias = makeReplacementAlias($playlist, [[
        'url' => 'http://provider.example.com:8080',
        'username' => 'newuser',
        'password' => 'newpass',
        'replace_url_enabled' => true,
        'replace_url' => 'http://vpn.provider.example.com:8080/',
    ]]);

    expect($alias->transformChannelUrl(makeReplacementChannel($playlist, 'http://provider.example.com:8080/live/olduser/oldpass/1234.ts')))
        ->toBe('http://vpn.provider.example.com:8080/live/newuser/newpass/1234.ts')
        ->and($alias->transformChannelUrl(makeReplacementChannel($playlist, 'http://provider.example.com:8080/olduser/oldpass/1234.ts')))
        ->toBe('http://vpn.provider.example.com:8080/newuser/newpass/1234.ts')
        // Not Xtream-shaped, so only the provider URL can be replaced.
        ->and($alias->transformChannelUrl(makeReplacementChannel($playlist, 'http://provider.example.com:8080/hls/channel/index.m3u8')))
        ->toBe('http://vpn.provider.example.com:8080/hls/channel/index.m3u8');
});

it('ignores the replacement url while replacement is disabled', function () {
    $playlist = makeReplacementM3uPlaylist();
    $alias = makeReplacementAlias($playlist, [[
        'url' => 'http://provider.example.com:8080',
        'username' => 'newuser',
        'password' => 'newpass',
        'replace_url_enabled' => false,
        'replace_url' => 'http://vpn.provider.example.com:8080',
    ]]);

    expect($alias->transformChannelUrl(makeReplacementChannel($playlist, 'http://provider.example.com:8080/live/olduser/oldpass/1234.ts')))
        ->toBe('http://provider.example.com:8080/live/newuser/newpass/1234.ts')
        ->and($alias->transformChannelUrl(makeReplacementChannel($playlist, 'http://provider.example.com:8080/hls/channel/index.m3u8')))
        ->toBe('http://provider.example.com:8080/hls/channel/index.m3u8');
});

it('applies each entry only to its own provider on multi-entry aliases', function () {
    $playlist = makeReplacementM3uPlaylist();
    $alias = makeReplacementAlias($playlist, [
        [
            'url' => 'http://provider-a.example.com:8080',
            'replace_url_enabled' => true,
            'replace_url' => 'http://vpn-a.example.com:8080',
        ],
        [
            'url' => 'http://provider-b.example.com:8080',
            'username' => 'userB',
            'password' => 'passB',
        ],
    ]);

    expect($alias->transformChannelUrl(makeReplacementChannel($playlist, 'http://provider-a.example.com:8080/live/a/a/1.ts')))
        ->toBe('http://vpn-a.example.com:8080/live/a/a/1.ts')
        ->and($alias->transformChannelUrl(makeReplacementChannel($playlist, 'http://provider-b.example.com:8080/live/b/b/2.ts')))
        ->toBe('http://provider-b.example.com:8080/live/userB/passB/2.ts')
        ->and($alias->transformChannelUrl(makeReplacementChannel($playlist, 'http://provider-c.example.com:8080/live/c/c/3.ts')))
        ->toBe('http://provider-c.example.com:8080/live/c/c/3.ts');
});

it('replaces the provider url of xtream streams and keeps the source credentials', function () {
    $playlist = makeReplacementXtreamPlaylist();
    $alias = makeReplacementAlias($playlist, [[
        'url' => 'http://source.example.com:8080',
        'replace_url_enabled' => true,
        'replace_url' => 'http://vpn.example.com:8080',
    ]]);

    expect($alias->transformChannelUrl(makeReplacementChannel($playlist, 'http://source.example.com:8080/live/srcuser/srcpass/42.ts')))
        ->toBe('http://vpn.example.com:8080/live/srcuser/srcpass/42.ts');
});

it('falls back to the only entry of a standard alias for an unmatched xtream provider', function () {
    // e.g. the source playlist failed over to another DNS URL the alias does not follow.
    $playlist = makeReplacementXtreamPlaylist();
    $alias = makeReplacementAlias($playlist, [[
        'url' => 'http://old-source.example.com:8080',
        'replace_url_enabled' => true,
        'replace_url' => 'http://vpn.example.com:8080',
    ]]);

    expect($alias->transformChannelUrl(makeReplacementChannel($playlist, 'http://source.example.com:8080/live/srcuser/srcpass/42.ts')))
        ->toBe('http://vpn.example.com:8080/live/srcuser/srcpass/42.ts');
});

it('does not apply another provider entry to an unmatched xtream provider on multi-entry aliases', function () {
    // A custom playlist mixing providers: the source with no entry of its own must not
    // get the first entry's credentials or replacement URL.
    $playlist = makeReplacementXtreamPlaylist();
    $customPlaylist = CustomPlaylist::factory()->for(User::find($playlist->user_id))->create();
    $alias = PlaylistAlias::create([
        'custom_playlist_id' => $customPlaylist->id,
        'user_id' => $playlist->user_id,
        'name' => 'Custom Alias',
        'uuid' => Str::uuid()->toString(),
        'xtream_config' => [
            [
                'url' => 'http://provider-a.example.com:8080',
                'username' => 'userA',
                'password' => 'passA',
                'replace_url_enabled' => true,
                'replace_url' => 'http://vpn-a.example.com:8080',
            ],
            [
                'url' => 'http://provider-b.example.com:8080',
                'username' => 'userB',
                'password' => 'passB',
            ],
        ],
    ]);

    $url = 'http://source.example.com:8080/live/srcuser/srcpass/42.ts';

    expect($alias->transformChannelUrl(makeReplacementChannel($playlist, $url)))->toBe($url)
        ->and($alias->replaceProviderUrl('http://source.example.com:8080/live/profileuser/profilepass/42.ts', $playlist->xtream_config))
        ->toBe('http://source.example.com:8080/live/profileuser/profilepass/42.ts');
});

it('replaces the provider url of episodes', function () {
    $playlist = makeReplacementM3uPlaylist();
    $alias = makeReplacementAlias($playlist, [[
        'url' => 'http://provider.example.com:8080',
        'replace_url_enabled' => true,
        'replace_url' => 'http://vpn.provider.example.com:8080',
    ]]);

    $episode = Episode::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'url' => 'http://provider.example.com:8080/series/user/pass/999.mkv',
    ]);

    expect($alias->transformEpisodeUrl($episode))->toBe('http://vpn.provider.example.com:8080/series/user/pass/999.mkv');
});

it('replaces only the provider url of a url already resolved by a provider profile', function () {
    $playlist = makeReplacementXtreamPlaylist();
    $alias = makeReplacementAlias($playlist, [[
        'url' => 'http://source.example.com:8080',
        'username' => 'aliasuser',
        'password' => 'aliaspass',
        'replace_url_enabled' => true,
        'replace_url' => 'http://vpn.example.com:8080',
    ]]);

    expect($alias->replaceProviderUrl('http://source.example.com:8080/live/profileuser/profilepass/42.ts', $playlist->xtream_config))
        ->toBe('http://vpn.example.com:8080/live/profileuser/profilepass/42.ts')
        ->and($alias->replaceProviderUrl('http://profile-host.example.com:8080/live/profileuser/profilepass/42.ts', $playlist->xtream_config))
        ->toBe('http://profile-host.example.com:8080/live/profileuser/profilepass/42.ts');
});

it('uses only entries with credentials for provider api calls', function () {
    $playlist = makeReplacementM3uPlaylist();
    $alias = makeReplacementAlias($playlist, [
        [
            'url' => 'http://provider-a.example.com:8080',
            'replace_url_enabled' => true,
            'replace_url' => 'http://vpn-a.example.com:8080',
        ],
        [
            'url' => 'http://provider-b.example.com:8080',
            'username' => 'userB',
            'password' => 'passB',
        ],
    ]);

    expect($alias->getPrimaryCredentialConfig()['url'])->toBe('http://provider-b.example.com:8080');

    $replacementOnly = makeReplacementAlias($playlist, [[
        'url' => 'http://provider-a.example.com:8080',
        'replace_url_enabled' => true,
        'replace_url' => 'http://vpn-a.example.com:8080',
    ]]);

    expect($replacementOnly->getPrimaryCredentialConfig())->toBeNull();
});
