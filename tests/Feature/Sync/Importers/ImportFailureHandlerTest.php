<?php

use App\Enums\Status;
use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use App\Models\User;
use App\Sync\Importers\Support\ImportFailureHandler;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function makePlaylistFor(array $attributes = []): Playlist
{
    $user = User::factory()->create();

    // createQuietly() suppresses the PlaylistCreated observer dispatch so
    // tests can isolate the behaviour under test from the sync pipeline.
    return Playlist::factory()->for($user)->createQuietly($attributes);
}

it('marks the playlist failed and clears live + vod processing flags by default', function () {
    Bus::fake();
    $playlist = makePlaylistFor([
        'status' => Status::Processing,
        'processing' => ['live_processing' => true, 'vod_processing' => true, 'series_processing' => true],
    ]);

    (new ImportFailureHandler)->fail($playlist, errors: 'boom');

    $fresh = $playlist->fresh();
    expect($fresh->status)->toBe(Status::Failed);
    expect($fresh->errors)->toBe('boom');
    expect((int) $fresh->progress)->toBe(100);
    expect($fresh->processing['live_processing'])->toBeFalse();
    expect($fresh->processing['vod_processing'])->toBeFalse();
    // Not cleared by default; preserves caller intent for series-only failures.
    expect($fresh->processing['series_processing'])->toBeTrue();
});

it('clears series_processing only when explicitly requested', function () {
    Bus::fake();
    $playlist = makePlaylistFor([
        'processing' => ['live_processing' => true, 'vod_processing' => true, 'series_processing' => true],
    ]);

    (new ImportFailureHandler)->fail($playlist, errors: 'boom', clearSeriesProcessing: true);

    expect($playlist->fresh()->processing['series_processing'])->toBeFalse();
});

it('respects progress, vod_progress, series_progress and channels overrides', function () {
    Bus::fake();
    $playlist = makePlaylistFor();

    (new ImportFailureHandler)->fail(
        $playlist,
        errors: 'early failure',
        progress: 0,
        vodProgress: 0,
        seriesProgress: 0,
        channels: 0,
    );

    $fresh = $playlist->fresh();
    expect((int) $fresh->progress)->toBe(0);
    expect((int) $fresh->vod_progress)->toBe(0);
    expect((int) $fresh->series_progress)->toBe(0);
    expect((int) $fresh->channels)->toBe(0);
});

it('schedules a 503 retry when the exception is a 503 and retry is enabled', function () {
    config()->set('dev.auto_retry_503_enabled', true);
    config()->set('dev.auto_retry_503_max', 3);
    config()->set('dev.auto_retry_503_cooldown_minutes', 10);
    config()->set('dev.auto_retry_503_delay_min_seconds', 60);
    config()->set('dev.auto_retry_503_delay_max_seconds', 60);
    Bus::fake();

    $playlist = makePlaylistFor([
        'auto_retry_503_count' => 0,
        'auto_retry_503_last_at' => null,
    ]);
    Bus::assertDispatchedTimes(ProcessM3uImport::class, 0);

    Http::fake(['*' => Http::response('Service Unavailable', 503)]);
    try {
        Http::throw()->get('https://example.com/503');
    } catch (RequestException $e) {
        $exception = $e;
    }

    (new ImportFailureHandler)->fail(
        $playlist,
        errors: 'upstream 503',
        exception: $exception,
        tryRetry503: true,
    );

    Bus::assertDispatchedTimes(ProcessM3uImport::class, 1);
    expect((int) $playlist->fresh()->auto_retry_503_count)->toBe(1);
});

it('does not schedule a 503 retry when the exception is not a 503', function () {
    Bus::fake();
    $playlist = makePlaylistFor();
    Bus::assertDispatchedTimes(ProcessM3uImport::class, 0);

    (new ImportFailureHandler)->fail(
        $playlist,
        errors: 'normal failure',
        exception: new Exception('Connection refused'),
        tryRetry503: true,
    );

    Bus::assertDispatchedTimes(ProcessM3uImport::class, 0);
});

it('does not schedule a 503 retry when tryRetry503 is false even with a 503 exception', function () {
    Bus::fake();
    $playlist = makePlaylistFor();
    Bus::assertDispatchedTimes(ProcessM3uImport::class, 0);

    (new ImportFailureHandler)->fail(
        $playlist,
        errors: 'upstream 503 ignored',
        exception: new Exception('Got status code 503 from upstream'),
        tryRetry503: false,
    );

    Bus::assertDispatchedTimes(ProcessM3uImport::class, 0);
});
