<?php

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

function actorFilmographyUrl(string $username, string $password, array $params = []): string
{
    $queryParams = array_merge([
        'username' => $username,
        'password' => $password,
        'action' => 'get_actor_filmography',
    ], $params);

    return '/player_api.php?'.http_build_query($queryParams);
}

beforeEach(function () {
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = 'fake-api-key';
    $settings->tmdb_language = 'en-US';
    $settings->tmdb_rate_limit = 40;
    app()->instance(GeneralSettings::class, $settings);

    RateLimiter::shouldReceive('tooManyAttempts')->andReturnFalse();
    RateLimiter::shouldReceive('hit')->andReturn(1);

    Cache::flush();
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

it('requires playlist auth', function () {
    $response = $this->getJson('/player_api.php?'.http_build_query([
        'username' => 'nope',
        'password' => 'nope',
        'action' => 'get_actor_filmography',
        'person_id' => 123,
    ]));

    $response->assertStatus(401);
});

it('requires person_id or name', function () {
    $response = $this->getJson(actorFilmographyUrl($this->username, $this->password));

    $response->assertStatus(400);
});

it('returns person details and credits for a person_id', function () {
    Http::fake([
        'api.themoviedb.org/3/person/*' => Http::sequence()
            ->push(['name' => 'Test Actor', 'profile_path' => '/abc.jpg', 'biography' => 'A famous test actor.'], 200)
            ->push(['cast' => [
                [
                    'id' => 550,
                    'media_type' => 'movie',
                    'title' => 'Fight Club',
                    'character' => 'Tyler Durden',
                    'release_date' => '1999-10-15',
                    'poster_path' => '/fightclub.jpg',
                ],
                [
                    'id' => 1399,
                    'media_type' => 'tv',
                    'name' => 'Game of Thrones',
                    'character' => 'Tyrion Lannister',
                    'first_air_date' => '2011-04-17',
                    'poster_path' => '/got.jpg',
                ],
            ]], 200),
    ]);

    $response = $this->getJson(actorFilmographyUrl($this->username, $this->password, ['person_id' => 123]));

    $response->assertOk();
    $response->assertJsonPath('person.name', 'Test Actor');
    $response->assertJsonPath('person.photo', fn ($photo) => str_contains($photo, 'abc.jpg'));
    $response->assertJsonCount(2, 'credits');
    $response->assertJsonPath('credits.0.title', 'Game of Thrones');
    $response->assertJsonPath('credits.0.in_library', false);
    $response->assertJsonPath('credits.0.local_id', null);
});

it('resolves person_id from name when not provided', function () {
    Http::fake([
        'api.themoviedb.org/3/search/person*' => Http::response([
            'results' => [
                ['id' => 7777, 'name' => 'Sam Worthington'],
            ],
        ], 200),
        'api.themoviedb.org/3/person/*' => Http::sequence()
            ->push(['name' => 'Sam Worthington', 'profile_path' => null, 'biography' => null], 200)
            ->push(['cast' => []], 200),
    ]);

    $response = $this->getJson(actorFilmographyUrl($this->username, $this->password, ['name' => 'Sam Worthington']));

    $response->assertOk();
    $response->assertJsonPath('person.name', 'Sam Worthington');
    $response->assertJsonCount(0, 'credits');
});

it('returns 404 when the actor cannot be resolved', function () {
    Http::fake([
        'api.themoviedb.org/3/search/person*' => Http::response(['results' => []], 200),
    ]);

    $response = $this->getJson(actorFilmographyUrl($this->username, $this->password, ['name' => 'Nobody']));

    $response->assertStatus(404);
});

it('marks credits present in the caller playlist as in_library with a local_id, scoped per playlist', function () {
    Http::fake([
        'api.themoviedb.org/3/person/*' => Http::sequence()
            ->push(['name' => 'Test Actor', 'profile_path' => null, 'biography' => null], 200)
            ->push(['cast' => [
                ['id' => 1399, 'media_type' => 'tv', 'name' => 'GoT', 'character' => 'X', 'first_air_date' => '2011-04-17', 'poster_path' => null],
                ['id' => 550, 'media_type' => 'movie', 'title' => 'Fight Club', 'character' => 'Y', 'release_date' => '1999-10-15', 'poster_path' => null],
                ['id' => 42, 'media_type' => 'movie', 'title' => 'Not in any playlist', 'character' => 'Z', 'release_date' => '2000-01-01', 'poster_path' => null],
            ]], 200),
    ]);

    $group = Group::factory()->for($this->user)->create();
    $series = Series::factory()->for($this->user)->for($this->playlist, 'playlist')->create(['enabled' => true, 'tmdb_id' => 1399]);
    $channel = Channel::factory()->for($this->playlist)->for($group)->create(['is_vod' => true, 'enabled' => true, 'tmdb_id' => 550]);

    // A different playlist also has a matching tmdb_id - must not leak into this response.
    $otherUser = User::factory()->create();
    $otherPlaylist = Playlist::factory()->for($otherUser)->create();
    Channel::factory()->for($otherPlaylist)->for($group)->create(['is_vod' => true, 'enabled' => true, 'tmdb_id' => 42]);

    $response = $this->getJson(actorFilmographyUrl($this->username, $this->password, ['person_id' => 123]));

    $response->assertOk();
    $credits = collect($response->json('credits'))->keyBy('tmdb_id');

    expect($credits[1399]['in_library'])->toBeTrue()
        ->and($credits[1399]['local_id'])->toBe($series->id)
        ->and($credits[550]['in_library'])->toBeTrue()
        ->and($credits[550]['local_id'])->toBe($channel->id)
        ->and($credits[42]['in_library'])->toBeFalse()
        ->and($credits[42]['local_id'])->toBeNull();
});
