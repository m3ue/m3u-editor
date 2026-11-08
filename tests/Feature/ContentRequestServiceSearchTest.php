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

it('lists every provider and defaults to one that does not have the title yet', function () {
    $this->sonarr->delete();
    $hd = ArrIntegration::factory()->radarr()->guestEnabled()->create([
        'user_id' => $this->user->id,
        'name' => 'Radarr HD',
        'url' => 'http://radarr-hd.test:7878',
    ]);
    $uhd = ArrIntegration::factory()->radarr()->guestEnabled()->create([
        'user_id' => $this->user->id,
        'name' => 'Radarr UHD',
        'url' => 'http://radarr-uhd.test:7878',
    ]);

    Http::fake([
        'radarr-hd.test:7878/api/v3/movie/lookup*' => Http::response([
            ['id' => 7, 'tmdbId' => 603, 'title' => 'The Matrix', 'year' => 1999],
        ], 200),
        'radarr-uhd.test:7878/api/v3/movie/lookup*' => Http::response([
            ['tmdbId' => 603, 'title' => 'The Matrix', 'year' => 1999],
        ], 200),
    ]);

    $result = app(ContentRequestService::class)->search($this->playlist, 'the matrix');

    expect($result['results'])->toHaveCount(1);
    $movie = $result['results'][0];
    $providers = collect($movie['providers'])->keyBy('integration_id');

    expect($providers)->toHaveCount(2)
        ->and($providers[$hd->id]['already_available'])->toBeTrue()
        ->and($providers[$uhd->id]['already_available'])->toBeFalse()
        // The provider that has it must not hide the one that can still take it.
        ->and($movie['integration_id'])->toBe($uhd->id)
        ->and($movie['integration_name'])->toBe('Radarr UHD')
        ->and($movie['already_available'])->toBeFalse();
});

it('marks a title available only when every provider has it', function () {
    $this->sonarr->delete();
    foreach (['radarr-a.test', 'radarr-b.test'] as $index => $host) {
        ArrIntegration::factory()->radarr()->guestEnabled()->create([
            'user_id' => $this->user->id,
            'name' => 'Radarr '.$index,
            'url' => "http://{$host}:7878",
        ]);
    }

    Http::fake([
        '*/api/v3/movie/lookup*' => Http::response([
            ['id' => 7, 'tmdbId' => 603, 'title' => 'The Matrix'],
        ], 200),
    ]);

    $movie = app(ContentRequestService::class)->search($this->playlist, 'the matrix')['results'][0];

    expect($movie['providers'])->toHaveCount(2)
        ->and($movie['already_available'])->toBeTrue();
});

it('keeps a single provider result shaped as before, plus its providers entry', function () {
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

    $show = app(ContentRequestService::class)->search($this->playlist, 'some new show')['results'][0];

    expect($show['integration_id'])->toBe($this->sonarr->id)
        ->and($show['already_available'])->toBeFalse()
        ->and($show['providers'])->toHaveCount(1)
        ->and($show['providers'][0]['integration_id'])->toBe($this->sonarr->id)
        ->and($show['providers'][0]['seasons'])->toBe($show['seasons']);
});
