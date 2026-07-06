<?php

use App\Filament\GuestPanel\Resources\Series\Pages\ListSeries as GuestListSeries;
use App\Filament\GuestPanel\Resources\Vods\Pages\ListVod as GuestListVod;
use App\Http\Middleware\GuestPlaylistAuth;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Series;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('playlist'));

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

    $prefix = base64_encode($this->playlist->uuid).'_';
    session([
        "{$prefix}guest_auth_username" => $this->username,
        "{$prefix}guest_auth_password" => $this->password,
    ]);

    $this->withCookie('playlist_uuid', $this->playlist->uuid);
    $this->withoutMiddleware(GuestPlaylistAuth::class);
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

    Livewire::withHeaders(['Referer' => url("/playlist/v/{$this->playlist->uuid}/series")])
        ->test(GuestListSeries::class)
        ->assertOk()
        ->loadTable()
        ->sortTable('release_date')
        ->assertCanSeeTableRecords([$olderSeries, $newerSeries], inOrder: true);
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

    Livewire::withHeaders(['Referer' => url("/playlist/v/{$this->playlist->uuid}/vod")])
        ->test(GuestListVod::class)
        ->assertOk()
        ->loadTable()
        ->assertTableColumnExists('release_date')
        ->sortTable('release_date')
        ->assertCanSeeTableRecords([$olderVod, $newerVod], inOrder: true);
});
