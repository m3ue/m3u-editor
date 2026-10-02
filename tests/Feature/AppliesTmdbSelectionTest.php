<?php

use App\Filament\Resources\Series\Pages\ViewSeries;
use App\Filament\Resources\Vods\Pages\ViewVod;
use App\Models\Channel;
use App\Models\Series;
use App\Models\User;
use App\Services\TmdbService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
        'info' => ['mpaa_rating' => 'PG', 'age' => '16+'],
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
            'certification' => 'R',
            'studios' => [['id' => 79, 'name' => 'Village Roadshow Pictures', 'logo' => null]],
            'logo_url' => 'https://image.tmdb.org/t/p/w500/matrix-logo.png',
            'cast_list' => [
                ['id' => 6384, 'name' => 'Keanu Reeves', 'character' => 'Neo', 'photo' => null],
            ],
            'keywords' => ['saving the world', 'dystopia'],
        ]);
    app()->instance(TmdbService::class, $tmdbService);

    Livewire::test(ViewVod::class, ['record' => $vod->getKey()])
        ->call('applyTmdbSelection', 603, 'movie', $vod->id, 'vod');

    // cast_list is persisted to a Postgres jsonb column, which does not preserve
    // object key order - compare with toEqual (loose ==) so the assertion checks
    // values, not the byte order jsonb chose to store the keys in.
    expect($vod->fresh()->info['vote_count'])->toBe(3)
        ->and($vod->fresh()->info['mpaa_rating'])->toBe('R')
        // The generic age field is provider-owned: only filled when blank.
        ->and($vod->fresh()->info['age'])->toBe('16+')
        ->and($vod->fresh()->info['studios'])->toEqual([['id' => 79, 'name' => 'Village Roadshow Pictures', 'logo' => null]])
        ->and($vod->fresh()->info['clearlogo'])->toBe('https://image.tmdb.org/t/p/w500/matrix-logo.png')
        ->and($vod->fresh()->info['tmdb_keywords'])->toBe(['saving the world', 'dystopia'])
        ->and($vod->fresh()->info['cast_list'])->toEqual([
            ['id' => 6384, 'name' => 'Keanu Reeves', 'character' => 'Neo', 'photo' => null],
        ]);
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
            'certification' => 'TV-MA',
            'networks' => [['id' => 49, 'name' => 'HBO', 'logo' => null]],
            'logo_url' => 'https://image.tmdb.org/t/p/w500/got-logo.png',
            'cast_list' => [
                ['id' => 22970, 'name' => 'Peter Dinklage', 'character' => 'Tyrion Lannister', 'photo' => null],
            ],
            'keywords' => ['dragon', 'fantasy'],
        ]);
    app()->instance(TmdbService::class, $tmdbService);

    Livewire::test(ViewSeries::class, ['record' => $series->getKey()])
        ->call('applyTmdbSelection', 1399, 'tv', $series->id, 'series');

    // metadata is a Postgres jsonb column, which does not preserve object key
    // order - compare cast_list with toEqual (loose ==) so key order is ignored.
    expect($series->fresh()->metadata['vote_count'])->toBe(2)
        ->and($series->fresh()->metadata['content_rating'])->toBe('TV-MA')
        ->and($series->fresh()->metadata['networks'])->toEqual([['id' => 49, 'name' => 'HBO', 'logo' => null]])
        ->and($series->fresh()->metadata['clearlogo'])->toBe('https://image.tmdb.org/t/p/w500/got-logo.png')
        ->and($series->fresh()->metadata['tmdb_keywords'])->toBe(['dragon', 'fantasy'])
        ->and($series->fresh()->metadata['cast_list'])->toEqual([
            ['id' => 22970, 'name' => 'Peter Dinklage', 'character' => 'Tyrion Lannister', 'photo' => null],
        ]);
});

it('applies a series match whose director and genre lists exceed 255 characters', function () {
    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'genre' => null,
        'metadata' => [],
    ]);

    // Long-running series list every episode director (issue #1548: 295 chars).
    $directors = implode(', ', array_map(fn (int $i) => "Director Number {$i}", range(1, 20)));
    $genres = implode(', ', array_map(fn (int $i) => "Genre Number {$i}", range(1, 20)));

    $tmdbService = Mockery::mock(TmdbService::class);
    $tmdbService->shouldReceive('applyTvSeriesSelection')
        ->with(70977)
        ->andReturn([
            'tmdb_id' => 70977,
            'name' => 'Nestor Burma',
        ]);
    $tmdbService->shouldReceive('getTvSeriesDetails')
        ->with(70977)
        ->andReturn([
            'name' => 'Nestor Burma',
            'director' => $directors,
            'genres' => $genres,
        ]);
    app()->instance(TmdbService::class, $tmdbService);

    Livewire::test(ViewSeries::class, ['record' => $series->getKey()])
        ->call('applyTmdbSelection', 70977, 'tv', $series->id, 'series');

    // SQLite (tests) ignores varchar length, so also guard the column types
    // that Postgres enforces.
    expect(Schema::getColumnType('series', 'director'))->toBe('text')
        ->and(Schema::getColumnType('series', 'genre'))->toBe('text')
        ->and(mb_strlen($directors))->toBeGreaterThan(255)
        ->and($series->fresh()->tmdb_id)->toBe(70977)
        ->and($series->fresh()->director)->toBe($directors)
        ->and($series->fresh()->genre)->toBe($genres);
});
