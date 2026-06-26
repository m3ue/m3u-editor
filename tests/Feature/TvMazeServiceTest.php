<?php

use App\Services\TvMazeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

it('parses cast with personId for click-through to filmography', function () {
    Http::fake([
        'api.tvmaze.com/lookup/shows*' => Http::response(['id' => 1], 200),
        'api.tvmaze.com/shows/1/episodes*' => Http::response([], 200),
        'api.tvmaze.com/shows/1/cast*' => Http::response([
            [
                'person' => ['id' => 12345, 'name' => 'Bryan Cranston', 'image' => ['medium' => '/bc.jpg']],
                'character' => ['name' => 'Walter White'],
            ],
            [
                'person' => ['id' => 67890, 'name' => 'Aaron Paul', 'image' => ['medium' => '/ap.jpg']],
                'character' => ['name' => 'Jesse Pinkman'],
            ],
        ], 200),
    ]);

    $service = new TvMazeService;
    $data = $service->fetchSeriesData(81189);

    expect($data['cast'])->toHaveCount(2)
        ->and($data['cast'][0])->toMatchArray([
            'id' => 12345,
            'actor' => 'Bryan Cranston',
            'character' => 'Walter White',
        ])
        ->and($data['cast'][1])->toMatchArray([
            'id' => 67890,
            'actor' => 'Aaron Paul',
        ]);
});

it('skips voice and self cast entries', function () {
    Http::fake([
        'api.tvmaze.com/lookup/shows*' => Http::response(['id' => 1], 200),
        'api.tvmaze.com/shows/1/episodes*' => Http::response([], 200),
        'api.tvmaze.com/shows/1/cast*' => Http::response([
            [
                'person' => ['id' => 1, 'name' => 'Real Actor'],
                'character' => ['name' => 'Main'],
            ],
            [
                'voice' => true,
                'person' => ['id' => 2, 'name' => 'Voice Actor'],
                'character' => ['name' => 'Voice'],
            ],
            [
                'self' => true,
                'person' => ['id' => 3, 'name' => 'Themselves'],
                'character' => ['name' => 'Self'],
            ],
        ], 200),
    ]);

    $service = new TvMazeService;
    $data = $service->fetchSeriesData(81189);

    expect($data['cast'])->toHaveCount(1)
        ->and($data['cast'][0]['actor'])->toBe('Real Actor');
});

it('returns empty cast when show lookup fails', function () {
    Http::fake([
        'api.tvmaze.com/lookup/shows*' => Http::response('Not found', 404),
    ]);

    $service = new TvMazeService;
    $data = $service->fetchSeriesData(999999);

    expect($data['cast'])->toBe([])
        ->and($data['episodes'])->toBe([]);
});
