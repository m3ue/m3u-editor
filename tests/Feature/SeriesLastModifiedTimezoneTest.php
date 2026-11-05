<?php

/**
 * last_modified was written as a UTC wall clock while last_metadata_fetch is written in
 * the app timezone, so on non-UTC installs the freshness check was off by the UTC offset:
 * west of UTC fresh series looked stale, and east of UTC changed series looked fresh.
 */

use App\Jobs\ProcessM3uImportSeriesChunk;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

dataset('timezones either side of UTC', ['America/Denver', 'Asia/Tokyo']);

beforeEach(function () {
    $this->originalTimezone = date_default_timezone_get();

    Bus::fake();
    Notification::fake();

    $settings = Mockery::mock(GeneralSettings::class)->shouldIgnoreMissing();
    $settings->tmdb_auto_lookup_on_import = false;
    app()->instance(GeneralSettings::class, $settings);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create([
        'xtream' => true,
        'xtream_config' => ['url' => 'http://xtream.test', 'username' => 'user', 'password' => 'pass'],
    ]));
});

afterEach(function () {
    date_default_timezone_set($this->originalTimezone);
});

function lastModifiedUseTimezone(string $timezone): void
{
    date_default_timezone_set($timezone);
    config(['app.timezone' => $timezone]);
}

function lastModifiedFakeProvider(): void
{
    Http::fake(function ($request) {
        $lastModified = test()->providerLastModified;

        if (str_contains($request->url(), 'get_series_info')) {
            return Http::response([
                'info' => ['name' => 'Show', 'last_modified' => $lastModified],
                'seasons' => [['id' => 1, 'season_number' => 1, 'name' => 'Season 1']],
                'episodes' => ['1' => [[
                    'id' => 1001,
                    'episode_num' => 1,
                    'title' => 'S01E01 - Pilot',
                    'container_extension' => 'mkv',
                    'info' => [],
                ]]],
            ]);
        }

        return Http::response([['series_id' => 300, 'name' => 'Show', 'last_modified' => $lastModified]]);
    });
}

function lastModifiedRunChunk(Playlist $playlist, string $batchNo): void
{
    (new ProcessM3uImportSeriesChunk(
        payload: ['playlistId' => $playlist->id, 'categoryId' => 1, 'categoryName' => 'Drama'],
        batchCount: 1,
        batchNo: $batchNo,
        index: 0,
    ))->handle();
}

it('treats a series as fresh right after fetching one its provider changed an hour ago', function (string $timezone) {
    lastModifiedUseTimezone($timezone);
    $changedAt = now()->subHour()->timestamp;
    $this->providerLastModified = $changedAt;
    lastModifiedFakeProvider();

    lastModifiedRunChunk($this->playlist, 'batch-1');
    $series = Series::where('source_series_id', 300)->sole();
    $series->fetchMetadata(sync: false, dispatchTmdb: false);
    $series->refresh();

    expect($series->last_modified->timestamp)->toBe($changedAt)
        ->and($series->isMetadataFresh())->toBeTrue()
        ->and(Series::needsMetadataRefresh()->whereKey($series->id)->exists())->toBeFalse();
})->with('timezones either side of UTC');

it('flags a series for refresh when the catalog says it changed after the last fetch', function (string $timezone) {
    lastModifiedUseTimezone($timezone);
    $this->providerLastModified = now()->subDays(3)->timestamp;
    lastModifiedFakeProvider();

    lastModifiedRunChunk($this->playlist, 'batch-1');
    $series = Series::where('source_series_id', 300)->sole();
    $series->fetchMetadata(sync: false, dispatchTmdb: false);
    $series->update(['last_metadata_fetch' => now()->subHours(2)]);

    $changedAt = now()->subHour()->timestamp;
    $this->providerLastModified = $changedAt;
    lastModifiedRunChunk($this->playlist, 'batch-2');
    $series->refresh();

    expect($series->last_modified->timestamp)->toBe($changedAt)
        ->and($series->isMetadataFresh())->toBeFalse()
        ->and(Series::needsMetadataRefresh()->whereKey($series->id)->exists())->toBeTrue();
})->with('timezones either side of UTC');
