<?php

/**
 * Tests for DvrRecorderService
 *
 * Covers:
 * - Recording uses the raw source URL (url_custom ?? url) — no double-proxying
 * - start() stores proxy_network_id and sets status to Recording
 * - stop() clears proxy_network_id and transitions to PostProcessing
 * - DvrSetting use_proxy defaults to false
 */

use App\Enums\DvrRecordingStatus;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\User;
use App\Services\DvrRecorderService;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    $mock->shouldReceive('getDvrBroadcastHlsDir')->andReturn('/tmp/m3u-proxy-dvr/broadcast_'.$networkId);
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

it('calls proxy stop and transitions to PostProcessing', function () {
    $recording = makeScheduledRecording();
    $networkId = $recording->uuid;
    $recording->update([
        'status' => DvrRecordingStatus::Recording,
        'proxy_network_id' => $networkId,
    ]);

    $proxy = Mockery::mock(M3uProxyService::class);
    $proxy->shouldReceive('stopDvrBroadcast')->once()->with($networkId)->andReturn(true);
    $proxy->shouldReceive('getDvrBroadcastHlsDir')->once()->with($networkId)->andReturn('/tmp/m3u-proxy-dvr/broadcast_'.$networkId);
    app()->instance(M3uProxyService::class, $proxy);

    app(DvrRecorderService::class)->stop($recording);

    $fresh = $recording->fresh();
    expect($fresh->status)->toBe(DvrRecordingStatus::PostProcessing);
    expect($fresh->proxy_network_id)->toBeNull();
    expect($fresh->actual_end)->not->toBeNull();
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
