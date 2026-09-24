<?php

/**
 * A provider metadata refresh replaces a VOD channel's `info` / a series'
 * `metadata` wholesale with the Xtream payload, which never carries the
 * TMDB-only enrichment keys. Those keys must survive the refresh, otherwise
 * Xtream get_vod_info / get_series_info (which force a refresh on every call
 * when the playlist's auto-fetch metadata setting is off) silently drop them.
 */

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function tmdbEnrichmentFixture(): array
{
    return [
        'cast_list' => [
            ['id' => 6384, 'name' => 'Keanu Reeves', 'character' => 'Neo', 'photo' => 'https://image.tmdb.org/t/p/w185/keanu.jpg'],
        ],
        'clearlogo' => 'https://image.tmdb.org/t/p/original/logo.png',
        'related_tmdb' => [['tmdb_id' => 604, 'type' => 'movie']],
    ];
}

function xtreamPlaylistForEnrichment(User $user, array $overrides = []): Playlist
{
    return Playlist::withoutEvents(fn (): Playlist => Playlist::factory()->for($user)->create(array_merge([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://xtream.test',
            'username' => 'user',
            'password' => 'pass',
        ],
    ], $overrides)));
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('keeps TMDB enrichment on a VOD channel when the provider metadata is refreshed', function () {
    $playlist = xtreamPlaylistForEnrichment($this->user);
    $channel = Channel::factory()->for($playlist)->for($this->user)->create([
        'is_vod' => true,
        'source_id' => 'vod-1',
        'last_metadata_fetch' => now(),
        'info' => array_merge(['plot' => 'Old plot'], tmdbEnrichmentFixture()),
    ]);

    $xtream = new class
    {
        public function getVodInfo(string $vodId, int $timeout = 60): array
        {
            return [
                'info' => ['plot' => 'Provider plot', 'cover_big' => 'http://xtream.test/cover.jpg'],
                'movie_data' => ['stream_id' => 1],
            ];
        }
    };

    expect($channel->fetchMetadata($xtream, refresh: true, skipTmdb: true))->toBeTrue();

    $info = $channel->refresh()->info;
    expect($info['plot'])->toBe('Provider plot')
        ->and($info['cover_big'])->toBe('http://xtream.test/cover.jpg')
        ->and($info['cast_list'])->toEqual(tmdbEnrichmentFixture()['cast_list'])
        ->and($info['clearlogo'])->toBe(tmdbEnrichmentFixture()['clearlogo'])
        ->and($info['related_tmdb'])->toEqual(tmdbEnrichmentFixture()['related_tmdb']);
});

it('lets provider-sent values win over preserved TMDB enrichment keys', function () {
    $playlist = xtreamPlaylistForEnrichment($this->user);
    $channel = Channel::factory()->for($playlist)->for($this->user)->create([
        'is_vod' => true,
        'source_id' => 'vod-1',
        'last_metadata_fetch' => now(),
        'info' => tmdbEnrichmentFixture(),
    ]);

    $xtream = new class
    {
        public function getVodInfo(string $vodId, int $timeout = 60): array
        {
            return ['info' => ['clearlogo' => 'http://xtream.test/provider-logo.png']];
        }
    };

    $channel->fetchMetadata($xtream, refresh: true, skipTmdb: true);

    expect($channel->refresh()->info['clearlogo'])->toBe('http://xtream.test/provider-logo.png');
});

it('keeps TMDB enrichment on a series when the provider metadata is refreshed', function () {
    $playlist = xtreamPlaylistForEnrichment($this->user);
    $series = Series::factory()->for($playlist)->for($this->user)->create([
        'source_series_id' => '999',
        'is_custom' => false,
        'metadata' => array_merge(['plot' => 'Old plot'], tmdbEnrichmentFixture()),
    ]);

    Http::preventStrayRequests();
    Http::fake([
        '*action=get_series_info*' => Http::response([
            'info' => ['name' => $series->name, 'plot' => 'Provider plot'],
            'seasons' => [],
            'episodes' => [],
        ]),
    ]);

    expect($series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false))->toBeTrue();

    $metadata = $series->refresh()->metadata;
    expect($metadata['plot'])->toBe('Provider plot')
        ->and($metadata['cast_list'])->toEqual(tmdbEnrichmentFixture()['cast_list'])
        ->and($metadata['clearlogo'])->toBe(tmdbEnrichmentFixture()['clearlogo'])
        ->and($metadata['related_tmdb'])->toEqual(tmdbEnrichmentFixture()['related_tmdb']);
});

it('returns cast_list and clearlogo from get_vod_info when auto_fetch_vod_metadata is off', function () {
    $playlist = xtreamPlaylistForEnrichment($this->user, [
        'auto_fetch_vod_metadata' => false,
        'enable_logo_proxy' => false,
    ]);

    $playlistAuth = PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => 'enrichuser',
        'password' => 'enrichpass',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $playlist->playlistAuths()->attach($playlistAuth);

    $group = Group::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($playlist)->for($group)->for($this->user)->create([
        'enabled' => true,
        'is_vod' => true,
        'source_id' => 'vod-1',
        'tmdb_id' => 603,
        'last_metadata_fetch' => now(),
        'info' => array_merge(['plot' => 'Old plot', 'cover_big' => 'http://xtream.test/old.jpg'], tmdbEnrichmentFixture()),
    ]);

    Http::preventStrayRequests();
    Http::fake([
        '*action=get_vod_info*' => Http::response([
            'info' => ['plot' => 'Provider plot', 'cover_big' => 'http://xtream.test/cover.jpg'],
            'movie_data' => ['stream_id' => 1, 'container_extension' => 'mkv'],
        ]),
    ]);

    $response = $this->getJson('/player_api.php?'.http_build_query([
        'username' => 'enrichuser',
        'password' => 'enrichpass',
        'action' => 'get_vod_info',
        'vod_id' => $channel->id,
    ]));

    $response->assertOk()
        ->assertJsonPath('info.plot', 'Provider plot')
        ->assertJsonPath('info.cast_list.0.name', 'Keanu Reeves')
        ->assertJsonPath('info.clearlogo', tmdbEnrichmentFixture()['clearlogo']);

    expect($channel->refresh()->info['cast_list'])->toEqual(tmdbEnrichmentFixture()['cast_list']);
});

function providerVodInfoXtream(): object
{
    return new class
    {
        public function getVodInfo(string $vodId, int $timeout = 60): array
        {
            return [
                'info' => [
                    'plot' => 'Provider plot',
                    'rating' => '5.1',
                    'cast' => 'Provider Cast',
                    'backdrop_path' => ['http://xtream.test/backdrop.jpg'],
                ],
            ];
        }
    };
}

it('keeps TMDB-owned VOD fields over the provider refresh when TMDB is preferred', function () {
    $playlist = xtreamPlaylistForEnrichment($this->user);
    $channel = Channel::factory()->for($playlist)->for($this->user)->create([
        'is_vod' => true,
        'source_id' => 'vod-1',
        'last_metadata_fetch' => now(),
        'info' => array_merge([
            'plot' => 'Old plot',
            'rating' => 8.7,
            'vote_count' => 25000,
            'cast' => 'Keanu Reeves',
            'backdrop_path' => ['https://image.tmdb.org/t/p/original/backdrop.jpg'],
        ], tmdbEnrichmentFixture()),
    ]);

    $channel->fetchMetadata(providerVodInfoXtream(), refresh: true, skipTmdb: true, preferTmdb: true);

    $info = $channel->refresh()->info;
    expect($info['rating'])->toBe(8.7)
        ->and($info['vote_count'])->toBe(25000)
        ->and($info['cast'])->toBe('Keanu Reeves')
        ->and($info['backdrop_path'])->toBe(['https://image.tmdb.org/t/p/original/backdrop.jpg'])
        // Fields TMDB only fills when empty stay provider-owned.
        ->and($info['plot'])->toBe('Provider plot');
});

it('lets the provider refresh win on TMDB-owned VOD fields when TMDB is not preferred', function () {
    $playlist = xtreamPlaylistForEnrichment($this->user);
    $channel = Channel::factory()->for($playlist)->for($this->user)->create([
        'is_vod' => true,
        'source_id' => 'vod-1',
        'last_metadata_fetch' => now(),
        'info' => array_merge(['rating' => 8.7, 'cast' => 'Keanu Reeves'], tmdbEnrichmentFixture()),
    ]);

    $channel->fetchMetadata(providerVodInfoXtream(), refresh: true, skipTmdb: true);

    $info = $channel->refresh()->info;
    expect($info['rating'])->toBe('5.1')
        ->and($info['cast'])->toBe('Provider Cast')
        ->and($info['clearlogo'])->toBe(tmdbEnrichmentFixture()['clearlogo']);
});

it('does not prefer persisted values on a VOD row that was never TMDB-enriched', function () {
    $playlist = xtreamPlaylistForEnrichment($this->user);
    $channel = Channel::factory()->for($playlist)->for($this->user)->create([
        'is_vod' => true,
        'source_id' => 'vod-1',
        'last_metadata_fetch' => now(),
        'info' => ['rating' => '4.0', 'cast' => 'Old Provider Cast'],
    ]);

    $channel->fetchMetadata(providerVodInfoXtream(), refresh: true, skipTmdb: true, preferTmdb: true);

    $info = $channel->refresh()->info;
    expect($info['rating'])->toBe('5.1')
        ->and($info['cast'])->toBe('Provider Cast');
});

it('keeps TMDB-owned series columns over the provider refresh when TMDB is preferred', function () {
    $playlist = xtreamPlaylistForEnrichment($this->user);
    $series = Series::factory()->for($playlist)->for($this->user)->create([
        'source_series_id' => '999',
        'is_custom' => false,
        'cast' => 'Bryan Cranston',
        'rating' => '9.5',
        'metadata' => array_merge(['vote_count' => 15000], tmdbEnrichmentFixture()),
    ]);

    Http::preventStrayRequests();
    Http::fake([
        '*action=get_series_info*' => Http::response([
            'info' => [
                'name' => $series->name,
                'plot' => 'Provider plot',
                'cast' => 'Provider Cast',
                'rating' => '6.0',
                'vote_count' => 3,
            ],
            'seasons' => [],
            'episodes' => [],
        ]),
    ]);

    $series->fetchMetadata(refresh: true, sync: false, dispatchTmdb: false, preferTmdb: true);

    $series->refresh();
    expect($series->cast)->toBe('Bryan Cranston')
        ->and((string) $series->rating)->toBe('9.5')
        ->and($series->plot)->toBe('Provider plot')
        ->and($series->metadata['vote_count'])->toBe(15000)
        ->and($series->metadata['cast_list'])->toEqual(tmdbEnrichmentFixture()['cast_list']);
});
