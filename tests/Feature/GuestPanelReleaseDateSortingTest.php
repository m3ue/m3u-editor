<?php

use App\Filament\GuestPanel\Resources\Series\SeriesResource;
use App\Filament\GuestPanel\Resources\Vods\VodResource;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Set up request attributes and session so HasPlaylist resolves the correct context.
 * Direct static method calls work with this approach; Livewire::test() cannot carry
 * request attributes across synthetic requests (see GuestBrowseShowsTest for precedent).
 */
function setupGuestReleaseDateContext(Playlist $playlist, string $username, string $password): void
{
    request()->attributes->set('playlist_uuid', $playlist->uuid);

    $prefix = base64_encode($playlist->uuid).'_';
    session()->put("{$prefix}guest_auth_username", $username);
    session()->put("{$prefix}guest_auth_password", $password);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->username = 'guest-sort-user';
    $this->password = 'guest-sort-pass';

    $playlistAuth = PlaylistAuth::create([
        'name' => 'Guest Sort Auth',
        'username' => $this->username,
        'password' => $this->password,
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $playlistAuth->assignTo($this->playlist);

    setupGuestReleaseDateContext($this->playlist, $this->username, $this->password);
});

it('allows guest series lists to sort by release date', function () {
    $newerSeries = Series::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true,
        'name' => 'Newer Series',
        'release_date' => '2024-01-10',
    ]);
    $olderSeries = Series::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true,
        'name' => 'Older Series',
        'release_date' => '1999-03-31',
    ]);

    $results = SeriesResource::getEloquentQuery()
        ->orderBy('release_date', 'asc')
        ->get();

    expect($results->first()->id)->toBe($olderSeries->id)
        ->and($results->last()->id)->toBe($newerSeries->id);
});

it('allows guest VOD lists to show and sort by release date', function () {
    $newerVod = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Newer Movie',
        'year' => 2024,
        'info' => ['release_date' => '2024-01-10'],
    ]);
    $olderVod = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Older Movie',
        'year' => 1999,
        'info' => ['release_date' => '1999-03-31'],
    ]);

    $sortMethod = new ReflectionMethod(VodResource::class, 'sortByVodReleaseDate');
    $sortMethod->setAccessible(true);

    $results = $sortMethod->invoke(null, VodResource::getEloquentQuery(), 'asc')->get();

    expect($results->first()->id)->toBe($olderVod->id)
        ->and($results->last()->id)->toBe($newerVod->id);
});
