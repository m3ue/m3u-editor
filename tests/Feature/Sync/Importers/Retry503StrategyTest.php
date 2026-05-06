<?php

use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use App\Models\User;
use App\Sync\Importers\Support\Retry503Strategy;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function make503RequestException(): RequestException
{
    Http::fake([
        '*' => Http::response('Service Unavailable', 503),
    ]);

    try {
        Http::throw()->get('https://example.com/503');
    } catch (RequestException $e) {
        return $e;
    }

    throw new RuntimeException('Expected RequestException was not thrown.');
}

it('detects 503 from a RequestException response', function () {
    $exception = make503RequestException();

    expect(Retry503Strategy::isHttp503($exception))->toBeTrue();
});

it('detects 503 from a substring in the exception message', function () {
    expect(Retry503Strategy::isHttp503(new Exception('Got status code 503 from upstream')))->toBeTrue();
    expect(Retry503Strategy::isHttp503(new Exception('Server returned 503 Service Temporarily Unavailable')))->toBeTrue();
    expect(Retry503Strategy::isHttp503(new Exception('Upstream said HTTP 503: please retry')))->toBeTrue();
});

it('returns false for non-503 errors', function () {
    expect(Retry503Strategy::isHttp503(new Exception('Connection refused')))->toBeFalse();
    expect(Retry503Strategy::isHttp503(new Exception('500 Internal Server Error')))->toBeFalse();
});

it('does nothing when the feature is disabled', function () {
    config()->set('dev.auto_retry_503_enabled', false);
    Bus::fake();

    $user = User::factory()->create();
    // createQuietly() suppresses the PlaylistCreated observer dispatch so we
    // can test scheduleRetry() in isolation.
    $playlist = Playlist::factory()->for($user)->createQuietly();
    Bus::assertDispatchedTimes(ProcessM3uImport::class, 0);

    Retry503Strategy::scheduleRetry($playlist);

    // No dispatch from the strategy when feature flag is disabled.
    Bus::assertDispatchedTimes(ProcessM3uImport::class, 0);
    expect((int) $playlist->fresh()->auto_retry_503_count)->toBe(0);
});

it('skips retry when the per-playlist attempt cap is reached', function () {
    config()->set('dev.auto_retry_503_enabled', true);
    config()->set('dev.auto_retry_503_max', 3);
    Bus::fake();

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly([
        'auto_retry_503_count' => 3,
    ]);
    Bus::assertDispatchedTimes(ProcessM3uImport::class, 0);

    Retry503Strategy::scheduleRetry($playlist);

    Bus::assertDispatchedTimes(ProcessM3uImport::class, 0);
});

it('skips retry while the cooldown window is still active', function () {
    config()->set('dev.auto_retry_503_enabled', true);
    config()->set('dev.auto_retry_503_max', 3);
    config()->set('dev.auto_retry_503_cooldown_minutes', 10);
    Bus::fake();

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly([
        'auto_retry_503_count' => 1,
        'auto_retry_503_last_at' => now()->subMinutes(2),
    ]);
    Bus::assertDispatchedTimes(ProcessM3uImport::class, 0);

    Retry503Strategy::scheduleRetry($playlist);

    Bus::assertDispatchedTimes(ProcessM3uImport::class, 0);
});

it('dispatches a delayed ProcessM3uImport and bumps counters when allowed', function () {
    config()->set('dev.auto_retry_503_enabled', true);
    config()->set('dev.auto_retry_503_max', 3);
    config()->set('dev.auto_retry_503_cooldown_minutes', 10);
    config()->set('dev.auto_retry_503_delay_min_seconds', 60);
    config()->set('dev.auto_retry_503_delay_max_seconds', 60);
    Bus::fake();

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->createQuietly([
        'auto_retry_503_count' => 0,
        'auto_retry_503_last_at' => null,
    ]);
    Bus::assertDispatchedTimes(ProcessM3uImport::class, 0);

    Retry503Strategy::scheduleRetry($playlist);

    // Strategy retry dispatch only.
    Bus::assertDispatchedTimes(ProcessM3uImport::class, 1);

    $fresh = $playlist->fresh();
    expect($fresh->auto_retry_503_count)->toBe(1);
    expect($fresh->auto_retry_503_last_at)->not->toBeNull();
});
