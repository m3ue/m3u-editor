<?php

/**
 * Tests for DvrRecorderService
 *
 * Covers:
 * - Recording uses the raw source URL (url_custom ?? url) — no double-proxying
 * - start() stores proxy_network_id, sets status to Recording, and sends a bell notification
 * - stop() preserves proxy_network_id (downloader needs it) and transitions to PostProcessing
 * - cancel() on a recording with captured footage routes through post-processing like stop()
 * - cancel() on a recording with no footage (never started) marks Cancelled immediately
 * - releaseProxyResources() cleans up the proxy when proxy_network_id is still set (used by
 *   delete_dvr_recording so a recording deleted before post-processing runs isn't orphaned)
 * - DvrSetting use_proxy defaults to false
 */

use App\Enums\DvrRecordingStatus;
use App\Jobs\PostProcessDvrRecording;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\User;
use App\Services\DvrRecorderService;
use App\Services\M3uProxyService;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Build a SCHEDULED DvrRecording with a real Channel attached.
 *
 * @param  array<string, mixed>  $settingOverrides
 */
function makeScheduledRecording(array $settingOverrides = []): DvrRecording
{
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()
        ->enabled()
        ->for($user)
        ->for($playlist)
        ->create($settingOverrides);

    $channel = Channel::factory()
        ->for($user)
        ->for($playlist)
        ->create(['url' => 'http://direct.example.com/stream.ts']);

    return DvrRecording::factory()
        ->for($setting, 'dvrSetting')
        ->for($user)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'channel_id' => $channel->id,
            'stream_url' => null,
            'season' => null,
            'episode' => null,
            'metadata' => null,
        ]);
}

/**
 * Build a mock M3uProxyService that returns a predictable network_id.
 */
function mockProxy(string $networkId = 'test-network-id')
{
    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('startDvrBroadcast')->andReturn($networkId);
    $mock->shouldReceive('stopDvrBroadcast')->andReturn(true);
    $mock->shouldReceive('cleanupDvrBroadcast')->andReturn(true);
    $mock->shouldReceive('getActiveStreamIdForChannel')->andReturnNull();
    $mock->shouldReceive('getStreamProxyUrl')->andReturn('');
    app()->instance(M3uProxyService::class, $mock);

    return $mock;
}

// ── URL selection ─────────────────────────────────────────────────────────────

it('uses the raw channel URL to avoid double-proxying through the editor', function () {
    // DVR broadcasts run inside the proxy, which handles reconnects natively.
    // We must NOT use getProxyUrl() here — that returns an editor-routed URL
    // (/live/…?proxy=true) which would loop back through XtreamStreamController
    // → m3u-proxy pooled stream, causing double-proxying.
    $recording = makeScheduledRecording(['use_proxy' => false]);
    mockProxy($recording->uuid);

    app(DvrRecorderService::class)->start($recording);

    expect($recording->fresh()->stream_url)
        ->toBe('http://direct.example.com/stream.ts');
});

// ── start() ───────────────────────────────────────────────────────────────────

it('stores proxy_network_id and transitions to Recording on start', function () {
    $recording = makeScheduledRecording();
    mockProxy($recording->uuid);

    app(DvrRecorderService::class)->start($recording);

    $fresh = $recording->fresh();
    expect($fresh->status)->toBe(DvrRecordingStatus::Recording);
    expect($fresh->proxy_network_id)->toBe($recording->uuid);
    expect($fresh->actual_start)->not->toBeNull();
});

it('sends a bell notification when recording starts', function () {
    Notification::fake();
    $recording = makeScheduledRecording();
    mockProxy($recording->uuid);

    app(DvrRecorderService::class)->start($recording);

    Notification::assertSentTo($recording->user, DatabaseNotification::class);
});

it('skips start when recording is not in SCHEDULED state', function () {
    $recording = makeScheduledRecording();
    $recording->update(['status' => DvrRecordingStatus::Completed]);

    $proxy = Mockery::mock(M3uProxyService::class);
    $proxy->shouldNotReceive('startDvrBroadcast');
    app()->instance(M3uProxyService::class, $proxy);

    app(DvrRecorderService::class)->start($recording);

    expect($recording->fresh()->status)->toBe(DvrRecordingStatus::Completed);
});

// ── stop() ────────────────────────────────────────────────────────────────────

it('calls proxy stop and transitions to PostProcessing while preserving proxy_network_id', function () {
    $recording = makeScheduledRecording();
    $networkId = $recording->uuid;
    $recording->update([
        'status' => DvrRecordingStatus::Recording,
        'proxy_network_id' => $networkId,
    ]);

    $proxy = Mockery::mock(M3uProxyService::class);
    $proxy->shouldReceive('stopDvrBroadcast')->once()->with($networkId)->andReturn(true);
    // cleanupDvrBroadcast is NOT called by stop() — it runs after post-processing succeeds.
    $proxy->shouldNotReceive('cleanupDvrBroadcast');
    app()->instance(M3uProxyService::class, $proxy);

    app(DvrRecorderService::class)->stop($recording);

    $fresh = $recording->fresh();
    expect($fresh->status)->toBe(DvrRecordingStatus::PostProcessing);
    // proxy_network_id is preserved through post-processing — the HLS downloader needs it.
    expect($fresh->proxy_network_id)->toBe($networkId);
    // actual_end is NOT set here — it's set by the proxy callback when FFmpeg actually stops.
    // This ensures we capture the true end time, not a premature one.
    expect($fresh->actual_end)->toBeNull();
});

it('cancel() on a recording with captured footage stops the proxy and routes through post-processing instead of marking Cancelled directly', function () {
    // "Keep recording" in the TV app: a recording that had already started has real
    // footage worth keeping, so cancel() preserves it through the same stop() →
    // post-processing pipeline as a natural completion — it ends up Completed
    // (playable), not stuck as Cancelled with no file. user_cancelled and
    // error_message survive as a "stopped early" marker.
    $recording = makeScheduledRecording();
    $networkId = $recording->uuid;
    $recording->update([
        'status' => DvrRecordingStatus::Recording,
        'proxy_network_id' => $networkId,
    ]);

    $proxy = Mockery::mock(M3uProxyService::class);
    $proxy->shouldReceive('stopDvrBroadcast')->once()->with($networkId)->andReturn(true);
    $proxy->shouldNotReceive('cleanupDvrBroadcast');
    app()->instance(M3uProxyService::class, $proxy);

    app(DvrRecorderService::class)->cancel($recording);

    $fresh = $recording->fresh();
    expect($fresh->status)->toBe(DvrRecordingStatus::PostProcessing);
    // proxy_network_id is preserved through post-processing — the HLS downloader needs it.
    expect($fresh->proxy_network_id)->toBe($networkId);
    expect($fresh->user_cancelled)->toBeTrue();
    expect($fresh->error_message)->toBe('Cancelled by user');
    expect($fresh->actual_end)->not->toBeNull();

    Queue::assertPushed(PostProcessDvrRecording::class);
});

it('cancel() on a recording that never started (no footage) marks it Cancelled immediately with no post-processing', function () {
    // Scheduled but never started: there's no footage to preserve, so this stays
    // the original immediate-Cancelled behavior — nothing for the proxy to stop
    // and nothing for post-processing to do.
    $recording = makeScheduledRecording();
    expect($recording->status)->toBe(DvrRecordingStatus::Scheduled);
    expect($recording->proxy_network_id)->toBeNull();

    $proxy = Mockery::mock(M3uProxyService::class);
    $proxy->shouldNotReceive('stopDvrBroadcast');
    app()->instance(M3uProxyService::class, $proxy);

    app(DvrRecorderService::class)->cancel($recording);

    $fresh = $recording->fresh();
    expect($fresh->status)->toBe(DvrRecordingStatus::Cancelled);
    expect($fresh->proxy_network_id)->toBeNull();
    expect($fresh->user_cancelled)->toBeTrue();

    Queue::assertNotPushed(PostProcessDvrRecording::class);
});

// ── releaseProxyResources() ───────────────────────────────────────────────────

it('releaseProxyResources() cleans up the proxy broadcast when proxy_network_id is still set', function () {
    $recording = makeScheduledRecording();
    $recording->update([
        'status' => DvrRecordingStatus::PostProcessing,
        'proxy_network_id' => 'test-network-id',
    ]);

    $proxy = Mockery::mock(M3uProxyService::class);
    $proxy->shouldReceive('cleanupDvrBroadcast')->once()->with('test-network-id')->andReturn(true);
    app()->instance(M3uProxyService::class, $proxy);

    app(DvrRecorderService::class)->releaseProxyResources($recording->fresh());
});

it('releaseProxyResources() is a no-op once proxy_network_id has already been cleared', function () {
    $recording = makeScheduledRecording();
    $recording->update([
        'status' => DvrRecordingStatus::Completed,
        'proxy_network_id' => null,
    ]);

    $proxy = Mockery::mock(M3uProxyService::class);
    $proxy->shouldNotReceive('cleanupDvrBroadcast');
    app()->instance(M3uProxyService::class, $proxy);

    app(DvrRecorderService::class)->releaseProxyResources($recording->fresh());
});

// ── use_proxy default ─────────────────────────────────────────────────────────

it('DvrSetting use_proxy defaults to false', function () {
    $setting = DvrSetting::factory()->create();

    expect($setting->use_proxy)->toBeFalse();
});

it('DvrSetting use_proxy can be set to true', function () {
    $setting = DvrSetting::factory()->create(['use_proxy' => true]);

    expect($setting->fresh()->use_proxy)->toBeTrue();
});
