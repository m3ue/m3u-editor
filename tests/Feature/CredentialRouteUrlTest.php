<?php

/**
 * URLs with username / password path segments stay valid whatever the
 * credentials contain. route() alone throws for a password like `a{b}c`
 * (it reads `{b}` as an unfilled placeholder), which crashed the playlist
 * edit page whenever a Playlist Auth password contained braces.
 */

use App\Facades\PlaylistFacade;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\PlaylistUrlService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('builds the same URL as route() for plain credentials', function () {
    $parameters = ['uuid' => 'abc', 'username' => 'viewer', 'password' => 'secret123'];

    expect(PlaylistUrlService::credentialRoute('playlist.hdhr.overview.auth', $parameters))
        ->toBe(route('playlist.hdhr.overview.auth', $parameters));
});

it('encodes credentials route() cannot handle', function (string $password, string $encoded) {
    $url = PlaylistUrlService::credentialRoute('playlist.hdhr.overview.auth', [
        'uuid' => 'abc',
        'username' => 'view er',
        'password' => $password,
    ]);

    expect($url)->toEndWith("/abc/hdhr/view%20er/{$encoded}");
})->with([
    'braces' => ['a{b}c', 'a%7Bb%7Dc'],
    'slash' => ['p/w', 'p%2Fw'],
    'query characters' => ['a?b#c', 'a%3Fb%23c'],
]);

it('lists the HDHR URL for a Playlist Auth whose password has braces', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create(['enabled' => true, 'username' => 'viewer', 'password' => 'p{w}d']);
    $auth->assignTo($this->playlist);

    expect(PlaylistFacade::getUrls($this->playlist->fresh())['hdhr'])
        ->toEndWith("/{$this->playlist->uuid}/hdhr/viewer/p%7Bw%7Dd");
});

it('renders the playlist edit page for a Playlist Auth whose password has braces', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create(['enabled' => true, 'password' => '{1bSe_<}']);
    $auth->assignTo($this->playlist);

    $this->actingAs($this->user)
        ->get(PlaylistResource::getUrl('edit', ['record' => $this->playlist]))
        ->assertOk();
});
