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

function vodInfoImdbResponse(object $test, array $attributes)
{
    $group = Group::factory()->for($test->user)->create();
    $channel = Channel::factory()->for($test->playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => true,
        'last_metadata_fetch' => now(),
        ...$attributes,
    ]);

    return $test->getJson('/player_api.php?'.http_build_query([
        'username' => $test->username,
        'password' => $test->password,
        'action' => 'get_vod_info',
        'vod_id' => $channel->id,
    ]));
}

function seriesInfoImdbResponse(object $test, array $attributes)
{
    $series = Series::factory()->for($test->playlist)->create([
        'user_id' => $test->user->id,
        'enabled' => true,
        'last_modified' => now(),
        ...$attributes,
    ]);
    Season::factory()->create([
        'series_id' => $series->id,
        'season_number' => 1,
        'episode_count' => 1,
    ]);

    return $test->getJson('/player_api.php?'.http_build_query([
        'username' => $test->username,
        'password' => $test->password,
        'action' => 'get_series_info',
        'series_id' => $series->id,
    ]));
}

it('returns the IMDb id in get_vod_info', function () {
    vodInfoImdbResponse($this, ['info' => ['imdb_id' => 'tt1375666']])
        ->assertOk()
        ->assertJsonPath('info.imdb_id', 'tt1375666');
});

it('returns an empty IMDb id in get_vod_info when unknown', function () {
    vodInfoImdbResponse($this, ['info' => []])
        ->assertOk()
        ->assertJsonPath('info.imdb_id', '');
});

it('returns the IMDb id in get_series_info', function () {
    seriesInfoImdbResponse($this, ['imdb_id' => 'tt0903747'])
        ->assertOk()
        ->assertJsonPath('info.imdb_id', 'tt0903747');
});

it('falls back to series metadata for the IMDb id', function () {
    seriesInfoImdbResponse($this, ['metadata' => ['imdb_id' => 'tt0903747']])
        ->assertOk()
        ->assertJsonPath('info.imdb_id', 'tt0903747');
});

it('returns an empty IMDb id in get_series_info when unknown', function () {
    seriesInfoImdbResponse($this, [])
        ->assertOk()
        ->assertJsonPath('info.imdb_id', '');
});
