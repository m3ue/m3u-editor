<?php

use App\Models\ArrIntegration;
use App\Models\Playlist;
use App\Models\User;
use App\Services\ContentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->sonarr = ArrIntegration::factory()->sonarr()->guestEnabled()->create([
        'user_id' => $this->user->id,
    ]);
});

it('corrects season has_file using authoritative episode data instead of echoed lookup statistics', function () {
    // Sonarr's /series/lookup echoes the series-level file count into every
    // season's statistics, falsely marking season 2 (which has no files) as downloaded.
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            [
                'id' => 42,
                'tvdbId' => 12345,
                'title' => 'House of the Dragon',
                'seasons' => [
                    ['seasonNumber' => 1, 'statistics' => ['episodeCount' => 10, 'episodeFileCount' => 10]],
                    ['seasonNumber' => 2, 'statistics' => ['episodeCount' => 8, 'episodeFileCount' => 10]],
                ],
            ],
        ], 200),
        '*/api/v3/episode*' => Http::response([
            ['seasonNumber' => 1, 'episodeNumber' => 1, 'hasFile' => true],
            ['seasonNumber' => 1, 'episodeNumber' => 2, 'hasFile' => true],
            ['seasonNumber' => 2, 'episodeNumber' => 1, 'hasFile' => false],
            ['seasonNumber' => 2, 'episodeNumber' => 2, 'hasFile' => false],
        ], 200),
    ]);

    $result = app(ContentRequestService::class)->search($this->playlist, 'house of the dragon');

    $seasons = collect($result['results'][0]['seasons'])->keyBy('season_number');

    expect($seasons[1]['has_file'])->toBeTrue()
        ->and($seasons[1]['episode_file_count'])->toBe(2)
        ->and($seasons[2]['has_file'])->toBeFalse()
        ->and($seasons[2]['episode_file_count'])->toBe(0);
});

it('falls back to lookup statistics when the series is not yet in the library', function () {
    Http::fake([
        '*/api/v3/series/lookup*' => Http::response([
            [
                'tvdbId' => 999,
                'title' => 'Some New Show',
                'seasons' => [
                    ['seasonNumber' => 1, 'statistics' => ['episodeCount' => 10, 'episodeFileCount' => 0]],
                ],
            ],
        ], 200),
    ]);

    $result = app(ContentRequestService::class)->search($this->playlist, 'some new show');

    $seasons = collect($result['results'][0]['seasons'])->keyBy('season_number');

    expect($seasons[1]['has_file'])->toBeFalse();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/v3/episode'));
});
