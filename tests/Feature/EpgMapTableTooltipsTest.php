<?php

use App\Filament\Resources\EpgMaps\Pages\ListEpgMaps;
use App\Models\Epg;
use App\Models\EpgMap;
use App\Models\Playlist;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->epg = Epg::withoutEvents(fn () => Epg::factory()->for($this->user)->create());
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
});

it('gives every EPG Map metric column a tooltip', function () {
    $map = EpgMap::factory()->create([
        'epg_id' => $this->epg->id,
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
    ]);

    $table = Livewire::test(ListEpgMaps::class)->instance()->getTable();

    $missing = collect($table->getColumns())
        ->except(['name', 'status'])
        ->filter(fn ($column) => blank($column->record($map)->getTooltip()))
        ->keys()
        ->all();

    expect($missing)->toBe([]);
});
