<?php

use App\Filament\Resources\Series\Pages\EditSeries;
use App\Filament\Resources\Vods\Pages\ListVod;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

it('shows TMDB keywords read-only in the VOD edit form', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => true,
        'info' => ['tmdb_keywords' => ['christmas', 'heist']],
    ]);

    Livewire::test(ListVod::class)
        ->mountAction(TestAction::make('edit')->table($channel))
        ->assertActionMounted(TestAction::make('edit')->table($channel))
        ->assertSchemaComponentExists(
            'tmdb_keywords',
            'mountedActionSchema0',
            fn (TextEntry $entry): bool => $entry->isVisible() && $entry->getState() === ['christmas', 'heist'],
        );
});

it('hides the VOD keywords entry when a title has none', function () {
    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => true,
        'info' => ['tmdb_keywords' => []],
    ]);

    Livewire::test(ListVod::class)
        ->mountAction(TestAction::make('edit')->table($channel))
        ->assertActionMounted(TestAction::make('edit')->table($channel))
        ->assertSchemaComponentExists(
            'tmdb_keywords',
            'mountedActionSchema0',
            fn (TextEntry $entry): bool => $entry->isHidden(),
        );
});

it('shows TMDB keywords in the series TMDB Keywords section', function () {
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'metadata' => ['tmdb_keywords' => ['halloween', 'haunted house']],
    ]);

    Livewire::test(EditSeries::class, ['record' => $series->getRouteKey()])
        ->assertSee('TMDB Keywords')
        ->assertSee('halloween')
        ->assertSee('haunted house');
});
