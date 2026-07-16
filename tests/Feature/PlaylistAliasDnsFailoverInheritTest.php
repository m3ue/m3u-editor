<?php

use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;

function makeXtreamPlaylist(User $user, string $url, array $overrides = []): Playlist
{
    return Playlist::factory()->for($user)->create(array_merge([
        'xtream' => true,
        'xtream_config' => [
            'url' => $url,
            'username' => 'srcuser',
            'password' => 'srcpass',
        ],
        'xtream_fallback_urls' => [],
    ], $overrides));
}

function makeXtreamAlias(User $user, Playlist $playlist, array $overrides = []): PlaylistAlias
{
    return PlaylistAlias::create(array_merge([
        'name' => 'Test Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'inherit_dns_failover' => true,
    ], $overrides));
}

// ── inherit_dns_failover defaults ─────────────────────────────────────────────

describe('inherit_dns_failover default', function () {
    it('defaults to true for new aliases', function () {
        $user = User::factory()->create();
        $playlist = makeXtreamPlaylist($user, 'http://provider.example.com:8080');
        $alias = makeXtreamAlias($user, $playlist);

        expect($alias->inherit_dns_failover)->toBeTrue();
    });
});

// ── Playlist::promoteXtreamUrl alias propagation ───────────────────────────────

describe('Playlist::promoteXtreamUrl DNS failover propagation', function () {
    it('updates alias xtream_config URL when inherit_dns_failover is enabled', function () {
        $user = User::factory()->create();
        $playlist = makeXtreamPlaylist($user, 'http://primary.example.com:8080', [
            'xtream_fallback_urls' => ['http://backup.example.com:8080'],
        ]);
        $alias = makeXtreamAlias($user, $playlist, [
            'xtream_config' => [[
                'url' => 'http://primary.example.com:8080',
                'username' => 'aliasuser',
                'password' => 'aliaspass',
            ]],
        ]);

        $playlist->promoteXtreamUrl('http://backup.example.com:8080');

        $entry = PlaylistAlias::find($alias->id)->getPrimaryXtreamConfig();

        expect($entry['url'])->toBe('http://backup.example.com:8080')
            ->and($entry['username'])->toBe('aliasuser')
            ->and($entry['password'])->toBe('aliaspass');
    });

    it('does not update alias URL when inherit_dns_failover is disabled', function () {
        $user = User::factory()->create();
        $playlist = makeXtreamPlaylist($user, 'http://primary.example.com:8080', [
            'xtream_fallback_urls' => ['http://backup.example.com:8080'],
        ]);
        $alias = makeXtreamAlias($user, $playlist, [
            'inherit_dns_failover' => false,
            'xtream_config' => [[
                'url' => 'http://primary.example.com:8080',
                'username' => 'aliasuser',
                'password' => 'aliaspass',
            ]],
        ]);

        $playlist->promoteXtreamUrl('http://backup.example.com:8080');

        $entry = PlaylistAlias::find($alias->id)->getPrimaryXtreamConfig();

        expect($entry['url'])->toBe('http://primary.example.com:8080');
    });

    it('only updates alias entries whose URL matches the old primary', function () {
        $user = User::factory()->create();
        $playlistA = makeXtreamPlaylist($user, 'http://a.example.com:8080', [
            'xtream_fallback_urls' => ['http://a-backup.example.com:8080'],
        ]);
        $playlistB = makeXtreamPlaylist($user, 'http://b.example.com:8080');

        // Custom-playlist-style alias with two entries from different providers.
        // Only the entry matching playlist A's old URL should be updated.
        $alias = makeXtreamAlias($user, $playlistA, [
            'xtream_config' => [
                [
                    'url' => 'http://a.example.com:8080',
                    'username' => 'userA',
                    'password' => 'passA',
                ],
                [
                    'url' => 'http://b.example.com:8080',
                    'username' => 'userB',
                    'password' => 'passB',
                ],
            ],
        ]);

        $playlistA->promoteXtreamUrl('http://a-backup.example.com:8080');

        $config = PlaylistAlias::find($alias->id)->xtream_config;

        expect($config[0]['url'])->toBe('http://a-backup.example.com:8080')
            ->and($config[1]['url'])->toBe('http://b.example.com:8080');
    });

    it('handles trailing slashes when matching alias entries', function () {
        $user = User::factory()->create();
        $playlist = makeXtreamPlaylist($user, 'http://primary.example.com:8080', [
            'xtream_fallback_urls' => ['http://backup.example.com:8080'],
        ]);
        $alias = makeXtreamAlias($user, $playlist, [
            'xtream_config' => [[
                'url' => 'http://primary.example.com:8080/',
                'username' => 'aliasuser',
                'password' => 'aliaspass',
            ]],
        ]);

        $playlist->promoteXtreamUrl('http://backup.example.com:8080');

        $entry = PlaylistAlias::find($alias->id)->getPrimaryXtreamConfig();

        expect($entry['url'])->toBe('http://backup.example.com:8080');
    });

    it('updates alias entry that was already pointing to a fallback URL due to a prior promotion', function () {
        $user = User::factory()->create();
        $playlist = makeXtreamPlaylist($user, 'http://primary.example.com:8080', [
            'xtream_fallback_urls' => [
                'http://fallback1.example.com:8080',
                'http://fallback2.example.com:8080',
            ],
        ]);
        // Alias entry already points to fallback1 — simulating a prior partial DNS promotion
        $alias = makeXtreamAlias($user, $playlist, [
            'xtream_config' => [[
                'url' => 'http://fallback1.example.com:8080',
                'username' => 'aliasuser',
                'password' => 'aliaspass',
            ]],
        ]);

        $playlist->promoteXtreamUrl('http://fallback2.example.com:8080');

        expect(PlaylistAlias::find($alias->id)->getPrimaryXtreamConfig()['url'])
            ->toBe('http://fallback2.example.com:8080');
    });

    it('does not update aliases whose entry URL does not match the old primary', function () {
        $user = User::factory()->create();
        $playlist = makeXtreamPlaylist($user, 'http://primary.example.com:8080', [
            'xtream_fallback_urls' => ['http://backup.example.com:8080'],
        ]);
        $alias = makeXtreamAlias($user, $playlist, [
            'xtream_config' => [[
                'url' => 'http://different.example.com:8080',
                'username' => 'aliasuser',
                'password' => 'aliaspass',
            ]],
        ]);

        $playlist->promoteXtreamUrl('http://backup.example.com:8080');

        $entry = PlaylistAlias::find($alias->id)->getPrimaryXtreamConfig();

        expect($entry['url'])->toBe('http://different.example.com:8080');
    });

    it('propagates to multiple aliases that inherit from the same playlist', function () {
        $user = User::factory()->create();
        $playlist = makeXtreamPlaylist($user, 'http://primary.example.com:8080', [
            'xtream_fallback_urls' => ['http://backup.example.com:8080'],
        ]);

        $aliasA = makeXtreamAlias($user, $playlist, [
            'name' => 'Alias A',
            'xtream_config' => [[
                'url' => 'http://primary.example.com:8080',
                'username' => 'userA',
                'password' => 'passA',
            ]],
        ]);
        $aliasB = makeXtreamAlias($user, $playlist, [
            'name' => 'Alias B',
            'xtream_config' => [[
                'url' => 'http://primary.example.com:8080',
                'username' => 'userB',
                'password' => 'passB',
            ]],
        ]);

        $playlist->promoteXtreamUrl('http://backup.example.com:8080');

        expect(PlaylistAlias::find($aliasA->id)->getPrimaryXtreamConfig()['url'])
            ->toBe('http://backup.example.com:8080')
            ->and(PlaylistAlias::find($aliasB->id)->getPrimaryXtreamConfig()['url'])
            ->toBe('http://backup.example.com:8080');
    });
});
