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
    // Rich cast is now served straight from persisted columns
    // (channel->info['cast_list'] / series->metadata['cast_list']); these
    // endpoints must never reach out to TMDB, so any stray request is a bug.
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

// Helper to build URL for Xtream API actions. Inlined per-test to avoid a
// top-level function name clash with XtreamApiControllerTest which
// defines the same helper globally - Pest auto-loads every file under
// tests/Feature and PHP forbids redeclaring top-level functions.
function xtreamCastUrl(string $username, string $password, string $action, array $params = []): string
{
    $queryParams = array_merge([
        'username' => $username,
        'password' => $password,
        'action' => $action,
    ], $params);

    return '/player_api.php?'.http_build_query($queryParams);
}

$vodCastList = [
    ['id' => 6193, 'name' => 'Leonardo DiCaprio', 'character' => 'Cobb', 'photo' => 'https://image.tmdb.org/t/p/w185/leo.jpg'],
    ['id' => 24045, 'name' => 'Joseph Gordon-Levitt', 'character' => 'Arthur', 'photo' => null],
];

$seriesCastList = [
    ['id' => 17419, 'name' => 'Bryan Cranston', 'character' => 'Walter White', 'photo' => 'https://image.tmdb.org/t/p/w185/bc.jpg'],
    ['id' => 84433, 'name' => 'Aaron Paul', 'character' => 'Jesse Pinkman', 'photo' => null],
];

// ---- get_vod_info cast_list ----

it('emits cast_list in get_vod_info from channel info', function () use ($vodCastList) {
    $group = Group::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'name' => 'Inception',
        'last_metadata_fetch' => now(),
        'info' => [
            'cast' => 'Leonardo DiCaprio, Joseph Gordon-Levitt', // existing string
            'cast_list' => $vodCastList,
        ],
    ]);

    $response = $this->getJson(xtreamCastUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $channel->id]));

    $response->assertOk();
    // Existing string cast untouched (safe-degrade for old clients).
    $response->assertJsonPath('info.cast', 'Leonardo DiCaprio, Joseph Gordon-Levitt');
    // Rich array at root AND under info.
    $response->assertJsonPath('cast_list.0.name', 'Leonardo DiCaprio');
    $response->assertJsonPath('cast_list.0.character', 'Cobb');
    $response->assertJsonPath('cast_list.0.photo', 'https://image.tmdb.org/t/p/w185/leo.jpg');
    $response->assertJsonPath('cast_list.0.id', 6193);
    $response->assertJsonPath('cast_list.1.name', 'Joseph Gordon-Levitt');
    $response->assertJsonPath('cast_list.1.photo', null);
    $response->assertJsonPath('info.cast_list.0.name', 'Leonardo DiCaprio');
});

it('omits cast_list in get_vod_info when channel info has none', function () {
    $group = Group::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'last_metadata_fetch' => now(),
        'info' => ['cast' => 'Someone'],
    ]);

    $response = $this->getJson(xtreamCastUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $channel->id]));

    $response->assertOk();
    $response->assertJsonMissingPath('cast_list');
    $response->assertJsonMissingPath('info.cast_list');
});

it('omits cast_list in get_vod_info when persisted list is empty', function () {
    $group = Group::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'last_metadata_fetch' => now(),
        'info' => ['cast_list' => []],
    ]);

    $response = $this->getJson(xtreamCastUrl($this->username, $this->password, 'get_vod_info', ['vod_id' => $channel->id]));

    $response->assertOk();
    $response->assertJsonMissingPath('cast_list');
    $response->assertJsonMissingPath('info.cast_list');
});

// ---- get_series_info cast_list ----

it('emits cast_list in get_series_info from series metadata', function () use ($seriesCastList) {
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'name' => 'Breaking Bad',
        'cast' => 'Bryan Cranston, Aaron Paul', // existing string
        'tmdb_id' => 1396,
        'metadata' => ['cast_list' => $seriesCastList],
        'last_modified' => now(),
    ]);

    Season::factory()->create([
        'series_id' => $series->id,
        'season_number' => 1,
        'episode_count' => 7,
    ]);

    $response = $this->getJson(xtreamCastUrl($this->username, $this->password, 'get_series_info', ['series_id' => $series->id]));

    $response->assertOk();
    // Existing string cast untouched (safe-degrade for old clients).
    $response->assertJsonPath('info.cast', 'Bryan Cranston, Aaron Paul');
    // Rich array under info.
    $response->assertJsonPath('info.cast_list.0.name', 'Bryan Cranston');
    $response->assertJsonPath('info.cast_list.0.character', 'Walter White');
    $response->assertJsonPath('info.cast_list.0.photo', 'https://image.tmdb.org/t/p/w185/bc.jpg');
    $response->assertJsonPath('info.cast_list.0.id', 17419);
    $response->assertJsonPath('info.cast_list.1.name', 'Aaron Paul');
    $response->assertJsonPath('info.cast_list.1.photo', null);
});

it('omits cast_list in get_series_info when series metadata has none', function () {
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'name' => 'Old Series',
        'cast' => '',
        'tmdb_id' => null,
        'metadata' => [],
        'last_modified' => now(),
    ]);

    Season::factory()->create([
        'series_id' => $series->id,
        'season_number' => 1,
        'episode_count' => 1,
    ]);

    $response = $this->getJson(xtreamCastUrl($this->username, $this->password, 'get_series_info', ['series_id' => $series->id]));

    $response->assertOk();
    $response->assertJsonMissingPath('info.cast_list');
});

it('omits cast_list in get_series_info when persisted list is empty', function () {
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'enabled' => true,
        'name' => 'Breaking Bad',
        'tmdb_id' => 1396,
        'metadata' => ['cast_list' => []],
        'last_modified' => now(),
    ]);

    Season::factory()->create([
        'series_id' => $series->id,
        'season_number' => 1,
        'episode_count' => 1,
    ]);

    $response = $this->getJson(xtreamCastUrl($this->username, $this->password, 'get_series_info', ['series_id' => $series->id]));

    $response->assertOk();
    $response->assertJsonMissingPath('info.cast_list');
});
