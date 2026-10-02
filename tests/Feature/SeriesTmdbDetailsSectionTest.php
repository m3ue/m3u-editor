<?php

use App\Filament\Resources\Series\Pages\EditSeries;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

it('shows the TMDB content rating and networks read-only on the series edit page', function () {
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'metadata' => [
            'content_rating' => 'TV-MA',
            'networks' => [
                ['id' => 174, 'name' => 'AMC', 'logo' => null],
                ['id' => 213, 'name' => 'Netflix', 'logo' => null],
            ],
        ],
    ]);

    Livewire::test(EditSeries::class, ['record' => $series->getRouteKey()])
        ->assertOk()
        ->assertSee('TMDB Details')
        ->assertSee('TV-MA')
        ->assertSee('AMC')
        ->assertSee('Netflix');
});

it('falls back to the media server rating like get_series_info does', function () {
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'metadata' => ['official_rating' => 'TV-14'],
    ]);

    Livewire::test(EditSeries::class, ['record' => $series->getRouteKey()])
        ->assertOk()
        ->assertSee('TV-14');
});

it('hides the TMDB section when there is nothing to show', function () {
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'metadata' => ['content_rating' => null, 'networks' => []],
    ]);

    Livewire::test(EditSeries::class, ['record' => $series->getRouteKey()])
        ->assertOk()
        ->assertDontSee('TMDB Details');
});
