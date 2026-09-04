<?php

use App\Jobs\ProcessM3uImportSeriesEpisodes;
use App\Jobs\SyncXtreamSeries;
use App\Models\Playlist;
use App\Models\User;
use App\Services\XtreamService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Bus::fake();
    Cache::flush();
});

it('aborts the rest of the batch once the Xtream account is rate limited', function () {
    Http::fake([
        '*get_series_info*series_id=1*' => Http::response(['info' => ['name' => 'Series One']], 200),
        '*get_series_info*series_id=2*' => Http::response('Too Many Requests', 429),
        '*get_series_info*series_id=3*' => Http::response(['info' => ['name' => 'Series Three']], 200),
    ]);

    $playlist = Playlist::factory()->for(User::factory())->create([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://primary.example.com:8080',
            'username' => 'testuser',
            'password' => 'testpass',
        ],
        'xtream_fallback_urls' => null,
    ]);

    (new SyncXtreamSeries(
        playlist: $playlist->id,
        catId: 1,
        catName: 'Category One',
        series: ['1', '2', '3'],
    ))->handle(app(XtreamService::class));

    // Series 1 was created (and its episodes dispatched) before the 429 hit.
    expect($playlist->series()->where('source_series_id', '1')->exists())->toBeTrue();
    Bus::assertDispatched(ProcessM3uImportSeriesEpisodes::class, 1);

    // Series 2 (the one that 429'd) and series 3 (never reached) were skipped.
    expect($playlist->series()->where('source_series_id', '2')->exists())->toBeFalse()
        ->and($playlist->series()->where('source_series_id', '3')->exists())->toBeFalse();

    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'series_id=3'));
});
