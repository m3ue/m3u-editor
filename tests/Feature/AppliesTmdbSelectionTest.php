<?php

use App\Filament\Resources\Series\Pages\ViewSeries;
use App\Filament\Resources\Vods\Pages\ViewVod;
use App\Models\Channel;
use App\Models\Series;
use App\Models\User;
use App\Services\TmdbService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('persists tmdb vote_count when manually applying a movie match to a VOD', function () {
    $vod = Channel::factory()->create([
        'user_id' => $this->user->id,
        'is_vod' => true,
        'info' => [],
    ]);

    $tmdbService = Mockery::mock(TmdbService::class);
    $tmdbService->shouldReceive('applyMovieSelection')
        ->with(603)
        ->andReturn([
            'tmdb_id' => 603,
            'imdb_id' => 'tt0133093',
            'title' => 'The Matrix',
        ]);
    $tmdbService->shouldReceive('getMovieDetails')
        ->with(603)
        ->andReturn([
            'title' => 'The Matrix',
            'vote_average' => 6.5,
            'vote_count' => 3,
        ]);
    app()->instance(TmdbService::class, $tmdbService);

    Livewire::test(ViewVod::class, ['record' => $vod->getKey()])
        ->call('applyTmdbSelection', 603, 'movie', $vod->id, 'vod');

    expect($vod->fresh()->info['vote_count'])->toBe(3);
});

it('persists tmdb vote_count when manually applying a series match', function () {
    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'rating' => null,
        'metadata' => [],
    ]);

    $tmdbService = Mockery::mock(TmdbService::class);
    $tmdbService->shouldReceive('applyTvSeriesSelection')
        ->with(1399)
        ->andReturn([
            'tmdb_id' => 1399,
            'name' => 'Game of Thrones',
        ]);
    $tmdbService->shouldReceive('getTvSeriesDetails')
        ->with(1399)
        ->andReturn([
            'name' => 'Game of Thrones',
            'vote_average' => 6.0,
            'vote_count' => 2,
        ]);
    app()->instance(TmdbService::class, $tmdbService);

    Livewire::test(ViewSeries::class, ['record' => $series->getKey()])
        ->call('applyTmdbSelection', 1399, 'tv', $series->id, 'series');

    expect($series->fresh()->metadata['vote_count'])->toBe(2);
});
