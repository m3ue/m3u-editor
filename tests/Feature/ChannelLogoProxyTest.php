<?php

use App\Events\PlaylistCreated;
use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    Event::fake([PlaylistCreated::class]);
});

it('uses the logo proxy for channel table images when enabled on the playlist', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'enable_logo_proxy' => true,
    ]);
    Channel::factory()->for($user)->for($playlist)->create([
        'is_vod' => false,
        'logo' => 'https://provider.example/images/channel.png',
    ]);

    $this->actingAs($user);

    Livewire::test(ListChannels::class)
        ->loadTable()
        ->assertSee('/logo-proxy/', escape: false)
        ->assertDontSee('https://provider.example/images/channel.png', escape: false);
});

it('uses the logo proxy for channel table images when enabled on the custom playlist', function () {
    $user = User::factory()->create();
    $playlist = CustomPlaylist::factory()->for($user)->create([
        'enable_logo_proxy' => true,
    ]);
    Channel::factory()->for($user)->create([
        'playlist_id' => null,
        'custom_playlist_id' => $playlist->id,
        'is_vod' => false,
        'logo' => 'https://provider.example/images/custom-channel.png',
    ]);

    $this->actingAs($user);

    Livewire::test(ListChannels::class)
        ->loadTable()
        ->assertSee('/logo-proxy/', escape: false)
        ->assertDontSee('https://provider.example/images/custom-channel.png', escape: false);
});
