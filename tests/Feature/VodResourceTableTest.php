<?php

use App\Events\PlaylistCreated;
use App\Filament\Resources\Vods\Pages\ListVod;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    Event::fake([PlaylistCreated::class]);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('renders VOD table with channels that have no info', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'is_vod' => true,
        'title' => 'No Info Movie',
        'info' => null,
        'movie_data' => null,
    ]);

    Livewire::test(ListVod::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$channel]);
});

it('renders VOD table with channels that have partial info without description or plot', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'is_vod' => true,
        'title' => 'Partial Info Movie',
        'info' => [
            'tmdb_id' => 12345,
            'cover_big' => 'https://example.com/cover.jpg',
        ],
        'movie_data' => null,
    ]);

    Livewire::test(ListVod::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$channel]);
});

it('renders VOD table with channels that have description in info', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'is_vod' => true,
        'title' => 'Full Info Movie',
        'info' => [
            'tmdb_id' => 67890,
            'description' => 'A great movie about testing.',
        ],
        'movie_data' => null,
    ]);

    Livewire::test(ListVod::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$channel]);
});

it('renders VOD table with channels that have plot but no description', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'is_vod' => true,
        'title' => 'Plot Only Movie',
        'info' => [
            'plot' => 'An interesting plot summary.',
        ],
        'movie_data' => null,
    ]);

    Livewire::test(ListVod::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$channel]);
});
