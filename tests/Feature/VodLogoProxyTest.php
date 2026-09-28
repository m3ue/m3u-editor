<?php

use App\Events\PlaylistCreated;
use App\Filament\Resources\Vods\Pages\ListVod;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    Event::fake([PlaylistCreated::class]);
});

it('uses the logo proxy for VOD table images when enabled on the playlist', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'enable_logo_proxy' => true,
    ]);
    Channel::factory()->for($user)->for($playlist)->create([
        'is_vod' => true,
        'logo' => 'https://provider.example/images/vod.png',
    ]);

    $this->actingAs($user);

    Livewire::test(ListVod::class)
        ->loadTable()
        ->assertSee('/logo-proxy/', escape: false)
        ->assertDontSee('https://provider.example/images/vod.png', escape: false);
});

it('uses the logo proxy for VOD table images when enabled on the custom playlist', function () {
    $user = User::factory()->create();
    $playlist = CustomPlaylist::factory()->for($user)->create([
        'enable_logo_proxy' => true,
    ]);
    Channel::factory()->for($user)->create([
        'playlist_id' => null,
        'custom_playlist_id' => $playlist->id,
        'is_vod' => true,
        'logo' => 'https://provider.example/images/custom-vod.png',
    ]);

    $this->actingAs($user);

    Livewire::test(ListVod::class)
        ->loadTable()
        ->assertSee('/logo-proxy/', escape: false)
        ->assertDontSee('https://provider.example/images/custom-vod.png', escape: false);
});
