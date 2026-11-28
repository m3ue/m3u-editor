<?php

use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('sorts channels by resolved title when custom and original titles are mixed', function () {
    $this->actingAs($this->user);

    // No title_custom override — sorts on raw `title`.
    $apple = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'title' => 'Apple', 'title_custom' => null,
    ]);
    $cherry = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'title' => 'Cherry', 'title_custom' => null,
    ]);

    // Has title_custom override — sorts on the resolved (custom) value.
    $banana = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'title' => 'Zzz', 'title_custom' => 'Banana',
    ]);
    $date = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'title' => 'Aaa', 'title_custom' => 'Date',
    ]);

    Livewire::test(ListChannels::class)
        ->loadTable()
        ->toggleAllTableColumns()
        ->sortTable('title_custom')
        ->assertCanSeeTableRecords([$apple, $banana, $cherry, $date], inOrder: true);
});
