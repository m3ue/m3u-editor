<?php

use App\Filament\CopilotTools\VodContentSearchTool;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

function makeVodTool(): VodContentSearchTool
{
    return new VodContentSearchTool;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeVodChannel(User $user, Playlist $playlist, array $overrides = []): Channel
{
    return Channel::factory()->create(array_merge([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'group' => 'Animation',
        'year' => 2022,
        'rating' => 7.5,
        'info' => ['genre' => 'Animation', 'plot' => 'A test animated movie.'],
    ], $overrides));
}

beforeEach(function () {
    Event::fake();
    Queue::fake();
});

it('returns matching VOD content by group genre', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    makeVodChannel($user, $playlist, ['name' => 'Toy Story', 'group' => 'Animation']);
    makeVodChannel($user, $playlist, ['name' => 'Die Hard', 'group' => 'Action', 'info' => ['genre' => 'Action']]);

    $this->actingAs($user);

    $result = (string) makeVodTool()->handle(new Request(['genres' => ['Animation']]));

    expect($result)->toContain('Toy Story')
        ->not->toContain('Die Hard');
});

it('matches against info->genre as well as group column', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    // group says "Uncategorized" but TMDB info.genre says "Animation"
    makeVodChannel($user, $playlist, [
        'name' => 'Hidden Gem',
        'group' => 'Uncategorized',
        'info' => ['genre' => 'Animation', 'plot' => 'Rare animated film.'],
    ]);

    $this->actingAs($user);

    $result = (string) makeVodTool()->handle(new Request(['genres' => ['Animation']]));

    expect($result)->toContain('Hidden Gem');
});

it('applies year_min filter correctly', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    makeVodChannel($user, $playlist, ['name' => 'New Movie', 'year' => 2024]);
    makeVodChannel($user, $playlist, ['name' => 'Old Movie', 'year' => 2015]);

    $this->actingAs($user);

    $result = (string) makeVodTool()->handle(new Request(['year_min' => 2020]));

    expect($result)->toContain('New Movie')
        ->not->toContain('Old Movie');
});

it('applies min_rating filter correctly', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    makeVodChannel($user, $playlist, ['name' => 'Great Film', 'rating' => 8.5]);
    makeVodChannel($user, $playlist, ['name' => 'Bad Film', 'rating' => 3.0]);

    $this->actingAs($user);

    $result = (string) makeVodTool()->handle(new Request(['min_rating' => 7.0]));

    expect($result)->toContain('Great Film')
        ->not->toContain('Bad Film');
});

it('applies keyword filter across name, plot, and cast', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    makeVodChannel($user, $playlist, [
        'name' => 'Space Adventure',
        'info' => ['genre' => 'Adventure', 'plot' => 'Heroes travel to distant galaxies.', 'cast' => 'John Hero'],
    ]);
    makeVodChannel($user, $playlist, [
        'name' => 'Kitchen Chaos',
        'info' => ['genre' => 'Comedy', 'plot' => 'A chef makes mistakes.'],
    ]);

    $this->actingAs($user);

    $result = (string) makeVodTool()->handle(new Request(['keyword' => 'galaxies']));

    expect($result)->toContain('Space Adventure')
        ->not->toContain('Kitchen Chaos');
});

it('excludes genres from results', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    makeVodChannel($user, $playlist, ['name' => 'Family Fun', 'group' => 'Comedy', 'info' => ['genre' => 'Comedy']]);
    makeVodChannel($user, $playlist, ['name' => 'Scary Night', 'group' => 'Horror', 'info' => ['genre' => 'Horror']]);

    $this->actingAs($user);

    $result = (string) makeVodTool()->handle(new Request([
        'exclude_genres' => ['Horror'],
    ]));

    expect($result)->toContain('Family Fun')
        ->not->toContain('Scary Night');
});

it('scopes results to the authenticated user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $playlistA = Playlist::factory()->for($userA)->create();
    $playlistB = Playlist::factory()->for($userB)->create();

    makeVodChannel($userA, $playlistA, ['name' => 'My Movie']);
    makeVodChannel($userB, $playlistB, ['name' => 'Their Movie']);

    $this->actingAs($userA);

    $result = (string) makeVodTool()->handle(new Request([]));

    expect($result)->toContain('My Movie')
        ->not->toContain('Their Movie');
});

it('returns available genres when no results match', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    makeVodChannel($user, $playlist, ['group' => 'Documentary', 'info' => ['genre' => 'Documentary']]);

    $this->actingAs($user);

    $result = (string) makeVodTool()->handle(new Request(['genres' => ['NonExistentGenre12345']]));

    expect($result)->toContain('No VOD content found')
        ->toContain('Documentary');
});

it('respects the limit parameter', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    Channel::factory()->count(10)->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
        'group' => 'Comedy',
        'year' => 2022,
        'rating' => 7.0,
        'info' => ['genre' => 'Comedy'],
    ]);

    $this->actingAs($user);

    $result = (string) makeVodTool()->handle(new Request(['limit' => 3]));

    // 10 channels created but only 3 should appear — count "ID:" lines
    $idCount = substr_count($result, 'ID:');
    expect($idCount)->toBe(3);
});
