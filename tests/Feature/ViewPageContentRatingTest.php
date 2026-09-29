<?php

use App\Filament\Resources\Series\Pages\EditSeries;
use App\Filament\Resources\Series\Pages\ViewSeries;
use App\Filament\Resources\Vods\Pages\ViewVod;
use App\Models\Channel;
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

it('shows the TMDB content rating and networks as badges on the series view page', function (?string $backdrop) {
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'backdrop_path' => $backdrop,
        'metadata' => [
            'content_rating' => 'TV-MA',
            'networks' => [
                ['id' => 174, 'name' => 'AMC', 'logo' => null],
                ['id' => 213, 'name' => 'Netflix', 'logo' => null],
            ],
        ],
    ]);

    Livewire::test(ViewSeries::class, ['record' => $series->getRouteKey()])
        ->assertOk()
        ->assertSee('TV-MA')
        ->assertSee('AMC')
        ->assertSee('Netflix');
})->with([
    'hero backdrop' => [json_encode(['https://example.com/backdrop.jpg'])],
    'no backdrop' => [null],
]);

it('falls back to the media server rating like get_series_info does', function () {
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'metadata' => ['official_rating' => 'TV-14'],
    ]);

    Livewire::test(ViewSeries::class, ['record' => $series->getRouteKey()])
        ->assertOk()
        ->assertSee('TV-14');
});

it('does not show the TMDB details on the series edit page', function () {
    $series = Series::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'metadata' => [
            'content_rating' => 'TV-MA',
            'networks' => [['id' => 174, 'name' => 'AMC', 'logo' => null]],
        ],
    ]);

    Livewire::test(EditSeries::class, ['record' => $series->getRouteKey()])
        ->assertOk()
        ->assertDontSee('TV-MA');
});

it('shows the movie certification as a badge on the VOD view page', function (array $info, ?string $backdrop) {
    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => true,
        'info' => array_merge($info, array_filter(['backdrop_path' => $backdrop])),
    ]);

    Livewire::test(ViewVod::class, ['record' => $channel->getRouteKey()])
        ->assertOk()
        ->assertSee('PG-13');
})->with([
    'provider rating' => [['mpaa_rating' => 'PG-13']],
    'blank provider rating falls back to TMDB' => [['mpaa_rating' => '', 'tmdb_certification' => 'PG-13']],
])->with([
    'hero backdrop' => ['https://example.com/backdrop.jpg'],
    'no backdrop' => [null],
]);

it('shows TMDB studios as badges on the VOD view page', function (?string $backdrop) {
    $channel = Channel::factory()->for($this->playlist)->create([
        'user_id' => $this->user->id,
        'is_vod' => true,
        'info' => array_filter([
            'backdrop_path' => $backdrop,
            'studios' => [
                ['id' => 79, 'name' => 'Village Roadshow Pictures', 'logo' => null],
                ['id' => 372, 'name' => 'Groucho II Film Partnership', 'logo' => null],
            ],
        ]),
    ]);

    Livewire::test(ViewVod::class, ['record' => $channel->getRouteKey()])
        ->assertOk()
        ->assertSee('Village Roadshow Pictures')
        ->assertSee('Groucho II Film Partnership');
})->with([
    'hero backdrop' => ['https://example.com/backdrop.jpg'],
    'no backdrop' => [null],
]);
