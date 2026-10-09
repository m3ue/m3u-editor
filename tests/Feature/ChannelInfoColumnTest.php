<?php

use App\Events\PlaylistCreated;
use App\Events\PlaylistUpdated;
use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    Event::fake([PlaylistCreated::class, PlaylistUpdated::class]);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

it('escapes provider supplied title and description in the Info column', function () {
    Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => false,
        'title' => '<img src=x onerror=alert(1)>News',
        'info' => ['description' => '<script>alert(2)</script>Daily news'],
    ]);

    Livewire::test(ListChannels::class)
        ->loadTable()
        ->assertDontSeeHtml('<img src=x onerror=alert(1)>')
        ->assertDontSeeHtml('<script>alert(2)</script>')
        ->assertSeeHtml('&lt;img src=x onerror=alert(1)&gt;News');
});

it('only reserves a wide Info column for rows with a description', function () {
    Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => false,
        'title' => 'Title Only Channel',
        'info' => null,
    ]);

    Livewire::test(ListChannels::class)
        ->loadTable()
        ->assertSee('Title Only Channel')
        ->assertDontSeeHtml('min-width: 350px;');

    Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => false,
        'title' => 'Described Channel',
        'info' => ['plot' => 'A channel with a plot'],
    ]);

    Livewire::test(ListChannels::class)
        ->loadTable()
        ->assertSee('A channel with a plot')
        ->assertSeeHtml('min-width: 350px;');
});
