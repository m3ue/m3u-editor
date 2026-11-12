<?php

/**
 * Provider profiles on M3U playlists: stream URL rewriting.
 *
 * M3U playlists have no xtream_config, so a profile swaps the primary account's
 * credentials (parsed from the playlist's M3U URL) - or, when those are unknown,
 * the credentials the stream URL itself carries - for its own, in the three URL
 * shapes providers commonly use.
 */

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistProfile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Playlist::factory() fires the sync pipeline; Bus::fake() keeps it off the queue.
    Bus::fake();
    Http::preventStrayRequests();
});

function m3uProfilePlaylist(array $attributes = []): Playlist
{
    return Playlist::factory()->create(array_merge([
        'xtream' => false,
        'xtream_config' => null,
        'profiles_enabled' => false,
        'url' => 'http://provider.test:8080/get.php?username=primaryuser&password=primarypass&type=m3u_plus',
    ], $attributes));
}

function m3uProfilePrimary(Playlist $playlist, string $username = 'primaryuser', string $password = 'primarypass'): PlaylistProfile
{
    return PlaylistProfile::factory()->forPlaylist($playlist)->primary()->create([
        'url' => 'http://provider.test:8080',
        'username' => $username,
        'password' => $password,
    ]);
}

function m3uProfileSecondary(Playlist $playlist, array $attributes = []): PlaylistProfile
{
    return PlaylistProfile::factory()->forPlaylist($playlist)->create(array_merge([
        'url' => null,
        'username' => 'backupuser',
        'password' => 'backuppass',
    ], $attributes));
}

it('rewrites an M3U channel URL to the profile credentials', function (string $original, string $expected) {
    $playlist = m3uProfilePlaylist();
    m3uProfilePrimary($playlist);
    $profile = m3uProfileSecondary($playlist);

    $channel = Channel::factory()->create(['playlist_id' => $playlist->id, 'url' => $original]);

    expect($profile->transformChannelUrl($channel))->toBe($expected);
})->with([
    'prefixed live form' => [
        'http://provider.test:8080/live/primaryuser/primarypass/123.ts',
        'http://provider.test:8080/live/backupuser/backuppass/123.ts',
    ],
    'prefixed movie form' => [
        'http://provider.test:8080/movie/primaryuser/primarypass/456.mkv',
        'http://provider.test:8080/movie/backupuser/backuppass/456.mkv',
    ],
    'prefix-less form' => [
        'http://provider.test:8080/primaryuser/primarypass/123',
        'http://provider.test:8080/backupuser/backuppass/123',
    ],
    'query string form' => [
        'http://provider.test:8080/stream?username=primaryuser&password=primarypass&id=7',
        'http://provider.test:8080/stream?username=backupuser&password=backuppass&id=7',
    ],
]);

it('rewrites an M3U episode URL to the profile credentials', function () {
    $playlist = m3uProfilePlaylist();
    m3uProfilePrimary($playlist);
    $profile = m3uProfileSecondary($playlist);

    $episode = Episode::factory()->create([
        'playlist_id' => $playlist->id,
        'url' => 'http://provider.test:8080/series/primaryuser/primarypass/55.mkv',
    ]);

    expect($profile->transformEpisodeUrl($episode))
        ->toBe('http://provider.test:8080/series/backupuser/backuppass/55.mkv');
});

it('replaces the provider host when the profile has its own URL', function () {
    $playlist = m3uProfilePlaylist();
    m3uProfilePrimary($playlist);
    $profile = m3uProfileSecondary($playlist, ['url' => 'http://backup.test:9000/']);

    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'url' => 'http://provider.test:8080/live/primaryuser/primarypass/123.ts',
    ]);

    expect($profile->transformChannelUrl($channel))
        ->toBe('http://backup.test:9000/live/backupuser/backuppass/123.ts');
});

it('keeps the host of a stream served off the primary provider even when the profile has its own URL', function () {
    $playlist = m3uProfilePlaylist();
    m3uProfilePrimary($playlist);
    $profile = m3uProfileSecondary($playlist, ['url' => 'http://backup.test:9000']);

    // Same account, but the stream lives on a CDN host rather than the provider base
    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'url' => 'http://cdn1.provider.test:8080/live/primaryuser/primarypass/1.ts',
    ]);

    expect($profile->transformChannelUrl($channel))
        ->toBe('http://cdn1.provider.test:8080/live/backupuser/backuppass/1.ts');
});

it('applies the profile URL when the primary provider base is unknown', function () {
    $playlist = m3uProfilePlaylist(['url' => 'http://provider.test/list.m3u']);
    PlaylistProfile::factory()->forPlaylist($playlist)->primary()->create(['url' => null, 'username' => '', 'password' => '']);
    $profile = m3uProfileSecondary($playlist, ['url' => 'http://backup.test:9000']);

    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'url' => 'http://provider.test:8080/live/someuser/somepass/123.ts',
    ]);

    expect($profile->transformChannelUrl($channel))
        ->toBe('http://backup.test:9000/live/backupuser/backuppass/123.ts');
});

it('parses the credentials from the stream URL when the primary account has none', function () {
    $playlist = m3uProfilePlaylist(['url' => 'http://provider.test/list.m3u']);
    m3uProfilePrimary($playlist, '', '');
    $profile = m3uProfileSecondary($playlist);

    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'url' => 'http://provider.test:8080/live/someuser/somepass/123.ts',
    ]);

    expect($profile->transformChannelUrl($channel))
        ->toBe('http://provider.test:8080/live/backupuser/backuppass/123.ts');
});

it('leaves a URL that does not carry the known primary credentials untouched', function () {
    $playlist = m3uProfilePlaylist();
    m3uProfilePrimary($playlist);
    $profile = m3uProfileSecondary($playlist);

    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'url' => 'http://cdn.test/live/otheruser/otherpass/1.ts',
    ]);

    expect($profile->transformChannelUrl($channel))->toBe('http://cdn.test/live/otheruser/otherpass/1.ts');
});

it('leaves a URL without recognisable credentials untouched', function () {
    $playlist = m3uProfilePlaylist(['url' => 'http://provider.test/list.m3u']);
    m3uProfilePrimary($playlist, '', '');
    $profile = m3uProfileSecondary($playlist);

    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'url' => 'http://provider.test/some/path/stream.m3u8',
    ]);

    expect($profile->transformChannelUrl($channel))->toBe('http://provider.test/some/path/stream.m3u8');
});

it('never rewrites URLs for the primary profile itself', function () {
    $playlist = m3uProfilePlaylist();
    $primary = m3uProfilePrimary($playlist);

    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'url' => 'http://provider.test:8080/live/primaryuser/primarypass/123.ts',
    ]);

    expect($primary->transformChannelUrl($channel))
        ->toBe('http://provider.test:8080/live/primaryuser/primarypass/123.ts');
});

it('parses credentials out of an M3U URL', function (?string $url, ?array $expected) {
    expect(PlaylistProfile::parseM3uUrlCredentials($url))->toBe($expected);
})->with([
    'get.php query form' => [
        'http://provider.test:8080/get.php?username=puser&password=ppass&type=m3u_plus&output=ts',
        ['url' => 'http://provider.test:8080', 'username' => 'puser', 'password' => 'ppass'],
    ],
    'url-encoded query credentials' => [
        'https://provider.test/get.php?username=p%40user&password=p%26pass',
        ['url' => 'https://provider.test', 'username' => 'p@user', 'password' => 'p&pass'],
    ],
    'path form' => [
        'http://provider.test/puser/ppass/playlist.m3u8',
        ['url' => 'http://provider.test', 'username' => 'puser', 'password' => 'ppass'],
    ],
    'playlist-prefixed path form' => [
        'http://provider.test/playlist/puser/ppass/m3u_plus',
        ['url' => 'http://provider.test', 'username' => 'puser', 'password' => 'ppass'],
    ],
    'live-prefixed path form' => [
        'http://provider.test/live/puser/ppass/tv.m3u8',
        ['url' => 'http://provider.test', 'username' => 'puser', 'password' => 'ppass'],
    ],
    'bare m3u_plus path form' => [
        'http://provider.test/puser/ppass/m3u_plus',
        ['url' => 'http://provider.test', 'username' => 'puser', 'password' => 'ppass'],
    ],
    'no credentials' => ['http://provider.test/list.m3u', null],
    'three-segment path that is not credentials' => ['https://iptv-org.github.io/iptv/countries/us.m3u', null],
    'api path with a playlist file' => ['http://provider.test/api/v1/list.m3u8', null],
    'local file path' => ['/data/playlists/list.m3u', null],
    'blank' => [null, null],
]);

it('parses credentials out of a stream URL', function (string $url, ?array $expected) {
    expect(PlaylistProfile::parseStreamCredentials($url))->toBe($expected);
})->with([
    'prefixed form' => [
        'http://provider.test:8080/live/suser/spass/123.ts',
        ['url' => 'http://provider.test:8080', 'username' => 'suser', 'password' => 'spass'],
    ],
    'prefix-less numeric form' => [
        'http://provider.test/suser/spass/123',
        ['url' => 'http://provider.test', 'username' => 'suser', 'password' => 'spass'],
    ],
    'stream-type prefix is not a username' => ['http://provider.test/live/suser/123.ts', null],
    'non-numeric last segment' => ['http://provider.test/some/path/file.ts', null],
]);

it('swaps URL-encoded query credentials and encodes the replacement', function () {
    expect(PlaylistProfile::swapStreamCredentials(
        'http://provider.test/x?username=u&password=p%40ss&id=1',
        'u',
        'p@ss',
        'n',
        'x y',
    ))->toBe('http://provider.test/x?username=n&password=x%20y&id=1');
});

it('normalises profile input entered as a full M3U URL', function () {
    $data = PlaylistProfile::normalizeM3uProfileInput([
        'url' => 'http://backup.test:9000/get.php?username=bu&password=bp&type=m3u_plus',
        'username' => '',
        'password' => null,
    ]);

    expect($data)->toMatchArray(['url' => 'http://backup.test:9000', 'username' => 'bu', 'password' => 'bp']);
});

it('keeps typed credentials over those in a pasted M3U URL', function () {
    $data = PlaylistProfile::normalizeM3uProfileInput([
        'url' => 'http://backup.test/get.php?username=bu&password=bp',
        'username' => 'typed',
        'password' => 'typedpass',
    ]);

    expect($data)->toMatchArray(['url' => 'http://backup.test', 'username' => 'typed', 'password' => 'typedpass']);
});

it('replaces stored credentials with those of an M3U URL pasted into an existing profile', function () {
    $existing = m3uProfileSecondary(m3uProfilePlaylist(), ['username' => 'olduser', 'password' => 'oldpass']);

    // The edit form loads the stored username and password, so they arrive unchanged
    $data = PlaylistProfile::normalizeM3uProfileInput([
        'url' => 'http://backup.test/get.php?username=newuser&password=newpass',
        'username' => 'olduser',
        'password' => 'oldpass',
    ], $existing);

    expect($data)->toMatchArray(['url' => 'http://backup.test', 'username' => 'newuser', 'password' => 'newpass']);
});

it('keeps credentials edited by hand on an existing profile over a pasted M3U URL', function () {
    $existing = m3uProfileSecondary(m3uProfilePlaylist(), ['username' => 'olduser', 'password' => 'oldpass']);

    $data = PlaylistProfile::normalizeM3uProfileInput([
        'url' => 'http://backup.test/get.php?username=newuser&password=newpass',
        'username' => 'typed',
        'password' => 'oldpass',
    ], $existing);

    expect($data)->toMatchArray(['username' => 'typed', 'password' => 'newpass']);
});

it('reduces a provider URL to its base', function (string $url, string $expected) {
    expect(PlaylistProfile::providerBaseUrl($url))->toBe($expected);
})->with([
    'trailing slash' => ['http://backup.test:9000/', 'http://backup.test:9000'],
    'file segment and query' => ['http://backup.test/iptv/list.m3u8?x=1', 'http://backup.test/iptv'],
    'host with a dot and port only' => ['http://backup.test:9000', 'http://backup.test:9000'],
    'path prefix kept' => ['http://backup.test/iptv', 'http://backup.test/iptv'],
]);

it('reports provider info support only for Xtream-backed playlists', function () {
    $m3uProfile = m3uProfileSecondary(m3uProfilePlaylist());
    $xtreamProfile = PlaylistProfile::factory()->forPlaylist(Playlist::factory()->create([
        'xtream' => true,
        'xtream_config' => ['url' => 'http://xtream.test', 'username' => 'u', 'password' => 'p'],
    ]))->create();

    expect($m3uProfile->supportsProviderInfo())->toBeFalse()
        ->and($xtreamProfile->supportsProviderInfo())->toBeTrue();
});

it('treats a playlist switched from Xtream to M3U as M3U despite its stale xtream_config', function () {
    $playlist = m3uProfilePlaylist([
        'xtream_config' => ['url' => 'http://old-xtream.test', 'username' => 'xu', 'password' => 'xp'],
    ]);
    m3uProfilePrimary($playlist);
    $profile = m3uProfileSecondary($playlist);

    expect($profile->transformUrl('http://provider.test:8080/live/primaryuser/primarypass/123.ts'))
        ->toBe('http://provider.test:8080/live/backupuser/backuppass/123.ts')
        ->and($profile->supportsProviderInfo())->toBeFalse();
});
