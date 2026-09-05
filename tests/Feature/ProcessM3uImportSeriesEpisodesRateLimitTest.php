<?php

use App\Exceptions\XtreamRateLimitedException;
use App\Jobs\ProcessM3uImportSeriesEpisodes;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Bus::fake();
    Cache::flush();
});

it('rethrows so the batch (and pipeline/run) fails honestly instead of silently completing', function () {
    Http::fake([
        '*get_series_info*series_id=1*' => Http::response(['info' => ['name' => 'Series One']], 200),
        '*get_series_info*series_id=2*' => Http::response('Too Many Requests', 429),
        '*get_series_info*series_id=3*' => Http::response(['info' => ['name' => 'Series Three']], 200),
    ]);

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://primary.example.com:8080',
            'username' => 'testuser',
            'password' => 'testpass',
        ],
        'xtream_fallback_urls' => null,
    ]);

    $series = collect(['1', '2', '3'])->map(fn (string $id) => Series::factory()
        ->for($playlist)
        ->for($user)
        ->create([
            'enabled' => true,
            'source_series_id' => $id,
            'last_metadata_fetch' => null,
            'last_modified' => null,
        ]));

    $job = new ProcessM3uImportSeriesEpisodes(
        notify: false,
        user_id: $user->id,
        playlist_id: $playlist->id,
        batchOffset: 0,
        totalBatches: 1,
        currentBatch: 1,
    );

    expect(fn () => $job->handle(app(GeneralSettings::class)))
        ->toThrow(XtreamRateLimitedException::class);

    // Series 1 was fetched (and saved) before the 429 hit — that work isn't lost.
    expect($series[0]->refresh()->last_metadata_fetch)->not->toBeNull();

    // Series 2 (the one that 429'd) and series 3 (never reached) were skipped.
    expect($series[1]->refresh()->last_metadata_fetch)->toBeNull()
        ->and($series[2]->refresh()->last_metadata_fetch)->toBeNull();

    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'series_id=3'));

    // The job's own failed() handler (invoked by the queue worker, not by this
    // direct handle() call) is what turns this into an honest Status::Failed
    // instead of CheckSeriesImportProgress silently reporting 100% complete.
    $job->failed(new XtreamRateLimitedException(now()->addMinutes(15)));

    expect($playlist->refresh()->status->value)->toBe('failed');
});
