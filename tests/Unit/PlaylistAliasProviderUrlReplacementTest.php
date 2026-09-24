<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

it('does not apply a credential-less entry to an unmatched xtream provider', function () {
    // Entries with credentials fall back to the first entry for Xtream sources (existing
    // behavior). A replacement-only entry must match explicitly instead.
    $playlist = makeReplacementXtreamPlaylist();
    $alias = makeReplacementAlias($playlist, [[
        'url' => 'http://other-provider.example.com:8080',
        'replace_url_enabled' => true,
        'replace_url' => 'http://vpn.example.com:8080',
    ]]);

    $url = 'http://source.example.com:8080/live/srcuser/srcpass/42.ts';

    expect($alias->transformChannelUrl(makeReplacementChannel($playlist, $url)))->toBe($url);
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

describe('migrating xtream alias url overrides', function () {
    beforeEach(function () {
        $this->migration = require database_path('migrations/2026_09_24_120000_move_xtream_alias_url_overrides_to_replacement_url.php');
    });

    it('moves an overridden url into the replacement url with identical stream urls', function () {
        $playlist = makeReplacementXtreamPlaylist();
        $alias = makeReplacementAlias($playlist, [[
            'url' => 'http://alias.example.com:8080',
            'username' => 'newuser',
            'password' => 'newpass',
        ]]);
        $channel = makeReplacementChannel($playlist, 'http://source.example.com:8080/live/srcuser/srcpass/42.ts');

        $before = $alias->transformChannelUrl($channel);

        $this->migration->up();
        $alias = $alias->fresh();

        expect($alias->xtream_config[0])->toMatchArray([
            'url' => 'http://source.example.com:8080',
            'username' => 'newuser',
            'password' => 'newpass',
            'replace_url_enabled' => true,
            'replace_url' => 'http://alias.example.com:8080',
        ])
            ->and($before)->toBe('http://alias.example.com:8080/live/newuser/newpass/42.ts')
            ->and($alias->transformChannelUrl($channel))->toBe($before);

        $this->migration->down();

        expect($alias->fresh()->xtream_config[0])->toBe([
            'url' => 'http://alias.example.com:8080',
            'username' => 'newuser',
            'password' => 'newpass',
        ]);
    });

    it('leaves entries using the playlist own or dns fallback urls alone', function () {
        $playlist = makeReplacementXtreamPlaylist();
        DB::table('playlists')->where('id', $playlist->id)->update([
            'xtream_fallback_urls' => json_encode(['http://fallback.example.com:8080']),
        ]);

        $sameUrl = makeReplacementAlias($playlist, [[
            'url' => 'http://source.example.com:8080/',
            'username' => 'newuser',
            'password' => 'newpass',
        ]]);
        $fallbackUrl = makeReplacementAlias($playlist, [[
            'url' => 'http://fallback.example.com:8080',
            'username' => 'newuser',
            'password' => 'newpass',
        ]]);
        $m3uAlias = makeReplacementAlias(makeReplacementM3uPlaylist(), [[
            'url' => 'http://provider.example.com:8080',
            'username' => 'newuser',
            'password' => 'newpass',
        ]]);

        $this->migration->up();

        expect($sameUrl->fresh()->xtream_config[0])->not->toHaveKey('replace_url')
            ->and($fallbackUrl->fresh()->xtream_config[0])->not->toHaveKey('replace_url')
            ->and($m3uAlias->fresh()->xtream_config[0])->not->toHaveKey('replace_url');
    });
});
