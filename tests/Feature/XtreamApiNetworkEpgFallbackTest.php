<?php

use App\Models\Channel;
use App\Models\Network;
use App\Models\NetworkProgramme;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function xtreamUrl(Playlist $playlist, string $username, string $password, string $action, array $params = []): string
{
    return route('xtream.api.player').'?'.http_build_query(array_merge([
        'username' => $username,
        'password' => $password,
        'action' => $action,
    ], $params));
}

it('falls back to contentable info plot when programme description is empty', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['is_network_playlist' => true]);
    $username = 'testuser_'.Str::random(5);
    $password = 'testpass';

    $auth = PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => $username,
        'password' => $password,
        'enabled' => true,
        'user_id' => $user->id,
    ]);
    $playlist->playlistAuths()->attach($auth);

    $network = Network::factory()->for($user)->create([
        'name' => 'Fallback Network',
        'enabled' => true,
        'network_playlist_id' => $playlist->id,
    ]);

    $channel = Channel::factory()->create([
        'user_id' => $user->id,
        'info' => ['plot' => 'A fallback plot from contentable.'],
    ]);

    NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'Empty Description Programme',
        'description' => null,
        'start_time' => Carbon::now()->subMinutes(30),
        'end_time' => Carbon::now()->addMinutes(30),
        'duration_seconds' => 3600,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
    ]);

    $response = $this->getJson(xtreamUrl($playlist, $username, $password, 'get_short_epg', [
        'stream_id' => $network->id,
        'limit' => 4,
    ]));

    $response->assertOk()
        ->assertJsonStructure(['epg_listings']);

    $listings = $response->json('epg_listings');
    expect($listings)->toHaveCount(1)
        ->and(base64_decode($listings[0]['description']))->toBe('A fallback plot from contentable.');
});

it('uses programme description when present instead of contentable fallback', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['is_network_playlist' => true]);
    $username = 'testuser_'.Str::random(5);
    $password = 'testpass';

    $auth = PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => $username,
        'password' => $password,
        'enabled' => true,
        'user_id' => $user->id,
    ]);
    $playlist->playlistAuths()->attach($auth);

    $network = Network::factory()->for($user)->create([
        'name' => 'Stored Description Network',
        'enabled' => true,
        'network_playlist_id' => $playlist->id,
    ]);

    $channel = Channel::factory()->create([
        'user_id' => $user->id,
        'info' => ['plot' => 'Should not be used.'],
    ]);

    NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'Has Description',
        'description' => 'Stored programme description.',
        'start_time' => Carbon::now()->subMinutes(30),
        'end_time' => Carbon::now()->addMinutes(30),
        'duration_seconds' => 3600,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
    ]);

    $response = $this->getJson(xtreamUrl($playlist, $username, $password, 'get_short_epg', [
        'stream_id' => $network->id,
        'limit' => 4,
    ]));

    $listings = $response->json('epg_listings');
    expect($listings)->toHaveCount(1)
        ->and(base64_decode($listings[0]['description']))->toBe('Stored programme description.');
});

it('falls back to contentable movie_data info plot when info plot is absent', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['is_network_playlist' => true]);
    $username = 'testuser_'.Str::random(5);
    $password = 'testpass';

    $auth = PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => $username,
        'password' => $password,
        'enabled' => true,
        'user_id' => $user->id,
    ]);
    $playlist->playlistAuths()->attach($auth);

    $network = Network::factory()->for($user)->create([
        'name' => 'Movie Data Network',
        'enabled' => true,
        'network_playlist_id' => $playlist->id,
    ]);

    $channel = Channel::factory()->create([
        'user_id' => $user->id,
        'info' => [],
        'movie_data' => ['info' => ['plot' => 'Plot from movie_data.']],
    ]);

    NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'No Info Plot',
        'description' => null,
        'start_time' => Carbon::now()->subMinutes(30),
        'end_time' => Carbon::now()->addMinutes(30),
        'duration_seconds' => 3600,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
    ]);

    $response = $this->getJson(xtreamUrl($playlist, $username, $password, 'get_short_epg', [
        'stream_id' => $network->id,
        'limit' => 4,
    ]));

    $listings = $response->json('epg_listings');
    expect($listings)->toHaveCount(1)
        ->and(base64_decode($listings[0]['description']))->toBe('Plot from movie_data.');
});

it('returns empty description when neither programme nor contentable has one', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['is_network_playlist' => true]);
    $username = 'testuser_'.Str::random(5);
    $password = 'testpass';

    $auth = PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => $username,
        'password' => $password,
        'enabled' => true,
        'user_id' => $user->id,
    ]);
    $playlist->playlistAuths()->attach($auth);

    $network = Network::factory()->for($user)->create([
        'name' => 'No Description Network',
        'enabled' => true,
        'network_playlist_id' => $playlist->id,
    ]);

    $channel = Channel::factory()->create([
        'user_id' => $user->id,
        'info' => [],
        'movie_data' => [],
    ]);

    NetworkProgramme::create([
        'network_id' => $network->id,
        'title' => 'Nothing To Fall Back To',
        'description' => null,
        'start_time' => Carbon::now()->subMinutes(30),
        'end_time' => Carbon::now()->addMinutes(30),
        'duration_seconds' => 3600,
        'contentable_type' => Channel::class,
        'contentable_id' => $channel->id,
    ]);

    $response = $this->getJson(xtreamUrl($playlist, $username, $password, 'get_short_epg', [
        'stream_id' => $network->id,
        'limit' => 4,
    ]));

    $listings = $response->json('epg_listings');
    expect($listings)->toHaveCount(1)
        ->and($listings[0]['description'])->toBe('');
});
