<?php

use App\Enums\DnsFailoverMode;
use App\Jobs\CheckPlaylistAliasDnsHealth;
use App\Jobs\UpdateXtreamStats;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use App\Services\XtreamHealthService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeDnsXtreamPlaylist(User $user, string $url = 'http://source.example.com:8080', array $overrides = []): Playlist
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

function makeDnsAlias(User $user, array $overrides = []): PlaylistAlias
{
    return PlaylistAlias::create(array_merge([
        'name' => 'DNS Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $user->id,
    ], $overrides));
}

function makeDnsChannel(Playlist $playlist, string $url): Channel
{
    return Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'group_id' => null,
        'url' => $url,
    ]);
}

// ── Inherit mode ──────────────────────────────────────────────────────────────

describe('inherit DNS failover mode', function () {
    it('injects the source playlist current URL into config entries', function () {
        $user = User::factory()->create();
        $playlist = makeDnsXtreamPlaylist($user);
        $alias = makeDnsAlias($user, [
            'playlist_id' => $playlist->id,
            'dns_failover_mode' => DnsFailoverMode::Inherit,
            'xtream_config' => [[
                'url' => 'http://stale.example.com:8080',
                'username' => 'aliasuser',
                'password' => 'aliaspass',
                'playlist_id' => $playlist->id,
            ]],
        ]);

        expect($alias->getPrimaryXtreamConfig()['url'])->toBe('http://source.example.com:8080');
    });

    it('follows the playlist DNS failover to the same secondary', function () {
        $user = User::factory()->create();
        $playlist = makeDnsXtreamPlaylist($user, 'http://source.example.com:8080', [
            'xtream_fallback_urls' => ['http://backup.example.com:8080'],
        ]);
        $alias = makeDnsAlias($user, [
            'playlist_id' => $playlist->id,
            'dns_failover_mode' => DnsFailoverMode::Inherit,
            'xtream_config' => [[
                'url' => 'http://source.example.com:8080',
                'username' => 'aliasuser',
                'password' => 'aliaspass',
                'playlist_id' => $playlist->id,
            ]],
        ]);
        $channel = makeDnsChannel($playlist, 'http://source.example.com:8080/live/srcuser/srcpass/42.ts');

        expect($alias->transformChannelUrl($channel))
            ->toBe('http://source.example.com:8080/live/aliasuser/aliaspass/42.ts');

        // Playlist fails over; the next sync rebuilds channel URLs onto the new base.
        $playlist->promoteXtreamUrl('http://backup.example.com:8080');
        $channel->update(['url' => 'http://backup.example.com:8080/live/srcuser/srcpass/42.ts']);

        $freshAlias = PlaylistAlias::find($alias->id);
        $freshChannel = Channel::find($channel->id);

        expect($freshAlias->getPrimaryXtreamConfig()['url'])->toBe('http://backup.example.com:8080')
            ->and($freshAlias->transformChannelUrl($freshChannel))
            ->toBe('http://backup.example.com:8080/live/aliasuser/aliaspass/42.ts');
    });

    it('keeps entries without a stored URL and injects the inherited URL', function () {
        $user = User::factory()->create();
        $playlist = makeDnsXtreamPlaylist($user);
        $alias = makeDnsAlias($user, [
            'playlist_id' => $playlist->id,
            'dns_failover_mode' => DnsFailoverMode::Inherit,
            'xtream_config' => [[
                'username' => 'aliasuser',
                'password' => 'aliaspass',
                'playlist_id' => $playlist->id,
            ]],
        ]);

        expect($alias->xtream_config)->toHaveCount(1)
            ->and($alias->getPrimaryXtreamConfig()['url'])->toBe('http://source.example.com:8080');
    });

    it('drops entries without a URL in static mode', function () {
        $user = User::factory()->create();
        $playlist = makeDnsXtreamPlaylist($user);
        $alias = makeDnsAlias($user, [
            'playlist_id' => $playlist->id,
            'dns_failover_mode' => DnsFailoverMode::Static,
            'xtream_config' => [[
                'username' => 'aliasuser',
                'password' => 'aliaspass',
            ]],
        ]);

        expect($alias->xtream_config)->toBe([]);
    });

    it('keeps the stored URL when the source playlist has no xtream config', function () {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create(['xtream_config' => null]);
        $alias = makeDnsAlias($user, [
            'playlist_id' => $playlist->id,
            'dns_failover_mode' => DnsFailoverMode::Inherit,
            'xtream_config' => [[
                'url' => 'http://stored.example.com:8080',
                'username' => 'aliasuser',
                'password' => 'aliaspass',
            ]],
        ]);

        expect($alias->getPrimaryXtreamConfig()['url'])->toBe('http://stored.example.com:8080');
    });

    it('inherits per entry from custom playlist sources by playlist_id', function () {
        $user = User::factory()->create();
        $sourceA = makeDnsXtreamPlaylist($user, 'http://a.example.com:8080', [
            'xtream_fallback_urls' => ['http://a-backup.example.com:8080'],
        ]);
        $sourceB = makeDnsXtreamPlaylist($user, 'http://b.example.com:8080');
        $custom = CustomPlaylist::factory()->create(['user_id' => $user->id]);
        $custom->channels()->attach(makeDnsChannel($sourceA, 'http://a.example.com:8080/live/srcuser/srcpass/1.ts')->id);
        $custom->channels()->attach(makeDnsChannel($sourceB, 'http://b.example.com:8080/live/srcuser/srcpass/2.ts')->id);

        $alias = makeDnsAlias($user, [
            'custom_playlist_id' => $custom->id,
            'dns_failover_mode' => DnsFailoverMode::Inherit,
            'xtream_config' => [
                [
                    'url' => 'http://a.example.com:8080',
                    'username' => 'userA',
                    'password' => 'passA',
                    'playlist_id' => $sourceA->id,
                ],
                [
                    'url' => 'http://b.example.com:8080',
                    'username' => 'userB',
                    'password' => 'passB',
                    'playlist_id' => $sourceB->id,
                ],
            ],
        ]);

        $sourceA->promoteXtreamUrl('http://a-backup.example.com:8080');

        $config = PlaylistAlias::find($alias->id)->xtream_config;

        expect($config[0]['url'])->toBe('http://a-backup.example.com:8080')
            ->and($config[1]['url'])->toBe('http://b.example.com:8080');
    });

    it('inherits for legacy entries by matching source URLs after a failover', function () {
        $user = User::factory()->create();
        $sourceA = makeDnsXtreamPlaylist($user, 'http://a.example.com:8080', [
            'xtream_fallback_urls' => ['http://a-backup.example.com:8080'],
        ]);
        $custom = CustomPlaylist::factory()->create(['user_id' => $user->id]);
        $custom->channels()->attach(makeDnsChannel($sourceA, 'http://a.example.com:8080/live/srcuser/srcpass/1.ts')->id);

        $alias = makeDnsAlias($user, [
            'custom_playlist_id' => $custom->id,
            'dns_failover_mode' => DnsFailoverMode::Inherit,
            'xtream_config' => [[
                'url' => 'http://a.example.com:8080',
                'username' => 'userA',
                'password' => 'passA',
            ]],
        ]);

        $sourceA->promoteXtreamUrl('http://a-backup.example.com:8080');

        // The old primary remains in the source's fallback list, so the legacy
        // entry still resolves to its source playlist and inherits the new URL.
        expect(PlaylistAlias::find($alias->id)->getPrimaryXtreamConfig()['url'])
            ->toBe('http://a-backup.example.com:8080');
    });
});

// ── getOrderedEntryUrls ───────────────────────────────────────────────────────

describe('PlaylistAlias::getOrderedEntryUrls', function () {
    it('returns the entry URL first, then fallbacks, normalized and deduplicated', function () {
        $user = User::factory()->create();
        $alias = makeDnsAlias($user, [
            'playlist_id' => makeDnsXtreamPlaylist($user)->id,
        ]);

        $urls = $alias->getOrderedEntryUrls([
            'url' => 'http://primary.example.com:8080/',
            'fallback_urls' => [
                'http://fallback.example.com:8080/',
                'http://primary.example.com:8080',
                '',
                'http://fallback.example.com:8080',
            ],
        ]);

        expect($urls)->toBe([
            'http://primary.example.com:8080',
            'http://fallback.example.com:8080',
        ]);
    });
});

// ── Independent mode: promoteXtreamUrl ────────────────────────────────────────

describe('PlaylistAlias::promoteXtreamUrl', function () {
    it('promotes a fallback URL and demotes the current URL, preserving other entries and keys', function () {
        $user = User::factory()->create();
        $alias = makeDnsAlias($user, [
            'playlist_id' => makeDnsXtreamPlaylist($user)->id,
            'dns_failover_mode' => DnsFailoverMode::Independent,
            'xtream_config' => [
                [
                    'url' => 'http://other.example.com:8080',
                    'username' => 'other',
                    'password' => 'other',
                    'fallback_urls' => [],
                ],
                [
                    'url' => 'http://dead.example.com:8080',
                    'username' => 'user',
                    'password' => 'pass',
                    'playlist_id' => 123,
                    'fallback_urls' => [
                        'http://live1.example.com:8080',
                        'http://live2.example.com:8080',
                    ],
                ],
            ],
        ]);

        $alias->promoteXtreamUrl('http://live1.example.com:8080');

        $config = PlaylistAlias::find($alias->id)->xtream_config;

        expect($config[0]['url'])->toBe('http://other.example.com:8080')
            ->and($config[1]['url'])->toBe('http://live1.example.com:8080')
            ->and($config[1]['fallback_urls'])->toBe([
                'http://dead.example.com:8080',
                'http://live2.example.com:8080',
            ])
            ->and($config[1]['playlist_id'])->toBe(123)
            ->and($config[1]['username'])->toBe('user');
    });

    it('is a no-op for unknown URLs', function () {
        $user = User::factory()->create();
        $alias = makeDnsAlias($user, [
            'playlist_id' => makeDnsXtreamPlaylist($user)->id,
            'dns_failover_mode' => DnsFailoverMode::Independent,
            'xtream_config' => [[
                'url' => 'http://dead.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
                'fallback_urls' => ['http://live.example.com:8080'],
            ]],
        ]);

        $alias->promoteXtreamUrl('http://unknown.example.com:8080');

        expect(PlaylistAlias::find($alias->id)->getPrimaryXtreamConfig()['url'])
            ->toBe('http://dead.example.com:8080');
    });

    it('is a no-op unless the alias is in independent mode', function () {
        $user = User::factory()->create();
        $alias = makeDnsAlias($user, [
            'playlist_id' => makeDnsXtreamPlaylist($user)->id,
            'dns_failover_mode' => DnsFailoverMode::Static,
            'xtream_config' => [[
                'url' => 'http://dead.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
                'fallback_urls' => ['http://live.example.com:8080'],
            ]],
        ]);

        $alias->promoteXtreamUrl('http://live.example.com:8080');

        expect(PlaylistAlias::find($alias->id)->getPrimaryXtreamConfig()['url'])
            ->toBe('http://dead.example.com:8080');
    });

    it('converts a legacy single-object config to a list when promoting', function () {
        $user = User::factory()->create();
        $alias = makeDnsAlias($user, [
            'playlist_id' => makeDnsXtreamPlaylist($user)->id,
            'dns_failover_mode' => DnsFailoverMode::Independent,
            'xtream_config' => [
                'url' => 'http://dead.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
                'fallback_urls' => ['http://live.example.com:8080'],
            ],
        ]);

        $alias->promoteXtreamUrl('http://live.example.com:8080');

        $fresh = PlaylistAlias::find($alias->id);

        expect($fresh->xtream_config)->toHaveCount(1)
            ->and($fresh->getPrimaryXtreamConfig()['url'])->toBe('http://live.example.com:8080')
            ->and($fresh->getPrimaryXtreamConfig()['fallback_urls'])->toBe(['http://dead.example.com:8080']);
    });
});

// ── Independent mode: matching after promotion ────────────────────────────────

describe('findXtreamConfigByUrl with fallback URLs', function () {
    it('still swaps credentials for M3U channels after a promotion', function () {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create(['xtream_config' => null]);
        $alias = makeDnsAlias($user, [
            'playlist_id' => $playlist->id,
            'dns_failover_mode' => DnsFailoverMode::Independent,
            'xtream_config' => [[
                'url' => 'http://new-dns.example.com:8080',
                'username' => 'newuser',
                'password' => 'newpass',
                'fallback_urls' => ['http://provider.example.com:8080'],
            ]],
        ]);
        $channel = makeDnsChannel($playlist, 'http://provider.example.com:8080/live/olduser/oldpass/1234.ts');

        // The stream URL's provider matches the entry via its fallback list, and the
        // rewrite lands on the entry's current (promoted) primary URL.
        expect($alias->transformChannelUrl($channel))
            ->toBe('http://new-dns.example.com:8080/live/newuser/newpass/1234.ts');
    });
});

// ── Independent mode: heal paths ──────────────────────────────────────────────

describe('XtreamHealthService::resolveWorkingAliasUrls', function () {
    it('promotes the first reachable fallback when the current URL is down', function () {
        Http::fake([
            'http://dead.example.com:8080/*' => Http::response('', 500),
            'http://live.example.com:8080/*' => Http::response(['user_info' => []], 200),
        ]);

        $user = User::factory()->create();
        $alias = makeDnsAlias($user, [
            'playlist_id' => makeDnsXtreamPlaylist($user)->id,
            'dns_failover_mode' => DnsFailoverMode::Independent,
            'xtream_config' => [[
                'url' => 'http://dead.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
                'fallback_urls' => ['http://live.example.com:8080'],
            ]],
        ]);

        expect(XtreamHealthService::resolveWorkingAliasUrls($alias))->toBeTrue();

        $entry = PlaylistAlias::find($alias->id)->getPrimaryXtreamConfig();

        expect($entry['url'])->toBe('http://live.example.com:8080')
            ->and($entry['fallback_urls'])->toBe(['http://dead.example.com:8080']);
    });

    it('does not promote when the current URL is healthy', function () {
        Http::fake([
            '*' => Http::response(['user_info' => []], 200),
        ]);

        $user = User::factory()->create();
        $alias = makeDnsAlias($user, [
            'playlist_id' => makeDnsXtreamPlaylist($user)->id,
            'dns_failover_mode' => DnsFailoverMode::Independent,
            'xtream_config' => [[
                'url' => 'http://primary.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
                'fallback_urls' => ['http://fallback.example.com:8080'],
            ]],
        ]);

        expect(XtreamHealthService::resolveWorkingAliasUrls($alias))->toBeFalse()
            ->and(PlaylistAlias::find($alias->id)->getPrimaryXtreamConfig()['url'])
            ->toBe('http://primary.example.com:8080');
    });
});

describe('UpdateXtreamStats alias DNS failover', function () {
    it('promotes a working fallback and refetches stats when the primary fails', function () {
        Http::fake([
            'http://dead.example.com:8080/*' => Http::response('', 500),
            'http://live.example.com:8080/*' => Http::response([
                'user_info' => ['status' => 'Active'],
                'server_info' => [],
            ], 200),
        ]);

        $user = User::factory()->create();
        $alias = makeDnsAlias($user, [
            'playlist_id' => makeDnsXtreamPlaylist($user)->id,
            'dns_failover_mode' => DnsFailoverMode::Independent,
            'xtream_config' => [[
                'url' => 'http://dead.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
                'fallback_urls' => ['http://live.example.com:8080'],
            ]],
        ]);

        (new UpdateXtreamStats($alias))->handle();

        $fresh = PlaylistAlias::find($alias->id);

        expect($fresh->getPrimaryXtreamConfig()['url'])->toBe('http://live.example.com:8080')
            ->and($fresh->getAttributes()['xtream_status'])->toContain('Active');
    });

    it('does not attempt failover for static aliases', function () {
        Http::fake([
            'http://dead.example.com:8080/*' => Http::response('', 500),
            'http://live.example.com:8080/*' => Http::response(['user_info' => []], 200),
        ]);

        $user = User::factory()->create();
        $alias = makeDnsAlias($user, [
            'playlist_id' => makeDnsXtreamPlaylist($user)->id,
            'dns_failover_mode' => DnsFailoverMode::Static,
            'xtream_config' => [[
                'url' => 'http://dead.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
                'fallback_urls' => ['http://live.example.com:8080'],
            ]],
        ]);

        (new UpdateXtreamStats($alias))->handle();

        expect(PlaylistAlias::find($alias->id)->getPrimaryXtreamConfig()['url'])
            ->toBe('http://dead.example.com:8080');
    });
});

describe('CheckPlaylistAliasDnsHealth', function () {
    it('heals every entry of an independent multi-provider alias', function () {
        Http::fake([
            'http://dead-a.example.com:8080/*' => Http::response('', 500),
            'http://dead-b.example.com:8080/*' => Http::response('', 500),
            'http://live-a.example.com:8080/*' => Http::response(['user_info' => []], 200),
            'http://live-b.example.com:8080/*' => Http::response(['user_info' => []], 200),
        ]);

        $user = User::factory()->create();
        $alias = makeDnsAlias($user, [
            'custom_playlist_id' => CustomPlaylist::factory()->create(['user_id' => $user->id])->id,
            'dns_failover_mode' => DnsFailoverMode::Independent,
            'xtream_config' => [
                [
                    'url' => 'http://dead-a.example.com:8080',
                    'username' => 'userA',
                    'password' => 'passA',
                    'fallback_urls' => ['http://live-a.example.com:8080'],
                ],
                [
                    'url' => 'http://dead-b.example.com:8080',
                    'username' => 'userB',
                    'password' => 'passB',
                    'fallback_urls' => ['http://live-b.example.com:8080'],
                ],
            ],
        ]);

        (new CheckPlaylistAliasDnsHealth($alias))->handle();

        $config = PlaylistAlias::find($alias->id)->xtream_config;

        expect($config[0]['url'])->toBe('http://live-a.example.com:8080')
            ->and($config[1]['url'])->toBe('http://live-b.example.com:8080');
    });

    it('does nothing for aliases that are not in independent mode', function () {
        Http::fake();

        $user = User::factory()->create();
        $alias = makeDnsAlias($user, [
            'playlist_id' => makeDnsXtreamPlaylist($user)->id,
            'dns_failover_mode' => DnsFailoverMode::Inherit,
            'xtream_config' => [[
                'url' => 'http://dead.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
                'fallback_urls' => ['http://live.example.com:8080'],
            ]],
        ]);

        (new CheckPlaylistAliasDnsHealth($alias))->handle();

        Http::assertNothingSent();
    });
});

// ── Playlist failover dispatches alias health checks ──────────────────────────

describe('Playlist::promoteXtreamUrl alias dispatch', function () {
    it('dispatches health checks only for independent-mode aliases', function () {
        $user = User::factory()->create();
        $playlist = makeDnsXtreamPlaylist($user, 'http://source.example.com:8080', [
            'xtream_fallback_urls' => ['http://backup.example.com:8080'],
        ]);
        $independent = makeDnsAlias($user, [
            'playlist_id' => $playlist->id,
            'dns_failover_mode' => DnsFailoverMode::Independent,
            'xtream_config' => [[
                'url' => 'http://alias.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
                'fallback_urls' => ['http://alias-backup.example.com:8080'],
            ]],
        ]);
        makeDnsAlias($user, [
            'name' => 'Static Alias',
            'playlist_id' => $playlist->id,
            'dns_failover_mode' => DnsFailoverMode::Static,
            'xtream_config' => [[
                'url' => 'http://static.example.com:8080',
                'username' => 'user',
                'password' => 'pass',
            ]],
        ]);

        $playlist->promoteXtreamUrl('http://backup.example.com:8080');

        Queue::assertPushed(CheckPlaylistAliasDnsHealth::class, 1);
        Queue::assertPushed(
            CheckPlaylistAliasDnsHealth::class,
            fn (CheckPlaylistAliasDnsHealth $job): bool => $job->playlistAlias->id === $independent->id
        );
    });
});

// ── Static mode regression: entry matching by playlist_id ────────────────────

describe('static multi-provider entry matching', function () {
    it('picks the correct entry by playlist_id after a source playlist failover', function () {
        $user = User::factory()->create();
        $sourceA = makeDnsXtreamPlaylist($user, 'http://a.example.com:8080', [
            'xtream_fallback_urls' => ['http://a-backup.example.com:8080'],
        ]);
        $sourceB = makeDnsXtreamPlaylist($user, 'http://b.example.com:8080');
        $custom = CustomPlaylist::factory()->create(['user_id' => $user->id]);
        $channelA = makeDnsChannel($sourceA, 'http://a.example.com:8080/live/srcuser/srcpass/1.ts');
        $custom->channels()->attach($channelA->id);
        $custom->channels()->attach(makeDnsChannel($sourceB, 'http://b.example.com:8080/live/srcuser/srcpass/2.ts')->id);

        // Entry B first: without playlist_id matching, a failed URL lookup would
        // fall back to this (wrong) primary entry.
        $alias = makeDnsAlias($user, [
            'custom_playlist_id' => $custom->id,
            'dns_failover_mode' => DnsFailoverMode::Static,
            'xtream_config' => [
                [
                    'url' => 'http://alias-b.example.com:8080',
                    'username' => 'userB',
                    'password' => 'passB',
                    'playlist_id' => $sourceB->id,
                ],
                [
                    'url' => 'http://alias-a.example.com:8080',
                    'username' => 'userA',
                    'password' => 'passA',
                    'playlist_id' => $sourceA->id,
                ],
            ],
        ]);

        // Source A fails over; its channels get rebuilt onto the new base at next sync.
        $sourceA->promoteXtreamUrl('http://a-backup.example.com:8080');
        $channelA->update(['url' => 'http://a-backup.example.com:8080/live/srcuser/srcpass/1.ts']);

        expect($alias->transformChannelUrl(Channel::find($channelA->id)))
            ->toBe('http://alias-a.example.com:8080/live/userA/passA/1.ts');
    });
});
