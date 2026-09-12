<?php

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // "related" is resolved straight from persisted related_tmdb + the
    // library's own tmdb_id columns; these endpoints must never reach out to
    // TMDB, so any stray request is a bug.
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->username = 'testuser_'.str()->random(5);
    $this->password = 'testpass';

    $playlistAuth = PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => $this->username,
        'password' => $this->password,
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($playlistAuth);
});

function xtreamRelatedUrl(string $username, string $password, string $action, array $params = []): string
{
    $queryParams = array_merge([
        'username' => $username,
        'password' => $password,
        'action' => $action,
    ], $params);

    return '/player_api.php?'.http_build_query($queryParams);
}

// ---- get_vod_info related ----

it('emits related in get_vod_info for recommendations matching the library', function () {
    $group = Group::factory()->for($this->user)->create();

    $matchedMovie = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'name' => 'Pulp Fiction',
        'title_custom' => 'Pulp Fiction',
        'tmdb_id' => 680,
        'logo' => 'https://image.tmdb.org/t/p/w500/pulp.jpg',
    ]);

    $matchedSeries = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'name' => 'Breaking Bad',
        'tmdb_id' => 1396,
        'cover' => 'https://image.tmdb.org/t/p/w500/bb.jpg',
    ]);

    $channel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'name' => 'Inception',
        'last_metadata_fetch' => now(),
        'info' => [
            'related_tmdb' => [
                ['tmdb_id' => 680, 'title' => 'Pulp Fiction', 'poster_url' => null, 'media_type' => 'movie'],
                ['tmdb_id' => 1396, 'title' => 'Breaking Bad', 'poster_url' => null, 'media_type' => 'tv'],
                // Not in the library - dropped, not shown as a dead-end card.
                ['tmdb_id' => 999999, 'title' => 'Nowhere', 'poster_url' => null, 'media_type' => 'movie'],
            ],
        ],
    ]);

    $response = $this->getJson(xtreamRelatedUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $channel->id]));

    $response->assertOk();
    $response->assertJsonCount(2, 'info.related');
    $response->assertJsonPath('info.related.0', [
        'type' => 'movie',
        'id' => $matchedMovie->id,
        'name' => 'Pulp Fiction',
        'cover' => 'https://image.tmdb.org/t/p/w500/pulp.jpg',
        'tmdb_id' => 680,
    ]);
    $response->assertJsonPath('info.related.1', [
        'type' => 'series',
        'id' => $matchedSeries->id,
        'name' => 'Breaking Bad',
        'cover' => 'https://image.tmdb.org/t/p/w500/bb.jpg',
        'tmdb_id' => 1396,
    ]);
    // Also lifted to root, matching cast_list/clearlogo convention.
    $response->assertJsonPath('related.0.name', 'Pulp Fiction');
});

it('omits related in get_vod_info when no recommendation matches the library', function () {
    $group = Group::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'last_metadata_fetch' => now(),
        'info' => [
            'related_tmdb' => [
                ['tmdb_id' => 999999, 'title' => 'Nowhere', 'poster_url' => null, 'media_type' => 'movie'],
            ],
        ],
    ]);

    $response = $this->getJson(xtreamRelatedUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $channel->id]));

    $response->assertOk();
    $response->assertJsonMissingPath('info.related');
    $response->assertJsonMissingPath('related');
});

it('omits related in get_vod_info when channel info has no related_tmdb', function () {
    $group = Group::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'last_metadata_fetch' => now(),
        'info' => ['cast' => 'Someone'],
    ]);

    $response = $this->getJson(xtreamRelatedUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $channel->id]));

    $response->assertOk();
    $response->assertJsonMissingPath('info.related');
});

it('excludes the item itself from related in get_vod_info', function () {
    $group = Group::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'name' => 'Inception',
        'tmdb_id' => 27205,
        'last_metadata_fetch' => now(),
        'info' => [
            'related_tmdb' => [
                ['tmdb_id' => 27205, 'title' => 'Inception', 'poster_url' => null, 'media_type' => 'movie'],
            ],
        ],
    ]);

    $response = $this->getJson(xtreamRelatedUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $channel->id]));

    $response->assertOk();
    $response->assertJsonMissingPath('info.related');
});

it('proxies related covers in get_vod_info when logo proxy is enabled', function () {
    $this->playlist->update(['enable_logo_proxy' => true]);

    $group = Group::factory()->for($this->user)->create();
    Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'name' => 'Pulp Fiction',
        'tmdb_id' => 680,
        'logo' => 'https://image.tmdb.org/t/p/w500/pulp.jpg',
    ]);

    $channel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'last_metadata_fetch' => now(),
        'info' => [
            'related_tmdb' => [
                ['tmdb_id' => 680, 'title' => 'Pulp Fiction', 'poster_url' => null, 'media_type' => 'movie'],
            ],
        ],
    ]);

    $response = $this->getJson(xtreamRelatedUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $channel->id]));

    $response->assertOk();
    $response->assertJsonPath('info.related.0.cover', fn ($cover) => str_contains($cover, '/logo-proxy/'));
});

// ---- get_series_info related ----

it('emits related in get_series_info for recommendations matching the library', function () {
    $matchedSeries = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'name' => 'Better Call Saul',
        'tmdb_id' => 60059,
        'cover' => 'https://image.tmdb.org/t/p/w500/bcs.jpg',
    ]);

    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'name' => 'Breaking Bad',
        'tmdb_id' => 1396,
        'metadata' => [
            'related_tmdb' => [
                ['tmdb_id' => 60059, 'title' => 'Better Call Saul', 'poster_url' => null, 'media_type' => 'tv'],
                ['tmdb_id' => 999999, 'title' => 'Nowhere', 'poster_url' => null, 'media_type' => 'tv'],
            ],
        ],
        'last_modified' => now(),
    ]);

    Season::factory()->create([
        'series_id' => $series->id,
        'season_number' => 1,
        'episode_count' => 7,
    ]);

    $response = $this->getJson(xtreamRelatedUrl($this->username, $this->password, 'get_series_info', ['series_id' => $series->id]));

    $response->assertOk();
    $response->assertJsonCount(1, 'info.related');
    $response->assertJsonPath('info.related.0', [
        'type' => 'series',
        'id' => $matchedSeries->id,
        'name' => 'Better Call Saul',
        'cover' => 'https://image.tmdb.org/t/p/w500/bcs.jpg',
        'tmdb_id' => 60059,
    ]);
});

it('omits related in get_series_info when series metadata has no related_tmdb', function () {
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'name' => 'Old Series',
        'tmdb_id' => null,
        'metadata' => [],
        'last_modified' => now(),
    ]);

    Season::factory()->create([
        'series_id' => $series->id,
        'season_number' => 1,
        'episode_count' => 1,
    ]);

    $response = $this->getJson(xtreamRelatedUrl($this->username, $this->password, 'get_series_info', ['series_id' => $series->id]));

    $response->assertOk();
    $response->assertJsonMissingPath('info.related');
});
